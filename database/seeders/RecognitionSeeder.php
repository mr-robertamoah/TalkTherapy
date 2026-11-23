<?php

namespace Database\Seeders;

use App\Actions\Recognition\GetRecognitionSuggestionsAction;
use App\Actions\Recognition\RecordCounsellorRecognitionEventAction;
use App\Actions\Star\AwardCounsellorStarAction;
use App\DTOs\CounsellorRecognitionDTO;
use App\DTOs\PlatformFeeWaiverDTO;
use App\DTOs\RequestResponseDTO;
use App\Enums\CounsellorRecognitionConsentEnum;
use App\Enums\CounsellorRecognitionDecisionEnum;
use App\Enums\CounsellorRecognitionEventEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\StarSourceEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Exceptions\AppException;
use App\Models\CounsellorRecognition;
use App\Models\PlatformFeeWaiver;
use App\Models\Report;
use App\Models\User;
use App\Services\CounsellorRecognitionService;
use App\Services\PlatformFeeWaiverService;
use App\Services\RequestService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

// TT-7.11d1/SCRUM-473: new-rule stars for the previous month (UTC) on the six demo counsellors, so the recognition
// suggestions have a ranking to show -- including a tie (emma_williams and david_brown: same count, same first star) --
// and one decision already made: sarah_johnson (rank 1) approved by the super admin (mr_robertamoah), with her featuring
// consent request pending on her counsellor dashboard. Made through the real award action and recognition service.
// Stars are keyed per month, so running it again adds nothing; the approval is skipped once that month has one.
//
// TT-7.11e/SCRUM-475: sarah_johnson's approved recognition also gets her own commission-free period for next month (UTC),
// made through PlatformFeeWaiverService -- skipped once she has any period of her own.
//
// TT-7.11f/SCRUM-476: the showcase's states for last month (showcase()) and this month's stars for Home's "Leading"
// (leading()). See documentation/seeded-data.md, "Counsellor recognition".
class RecognitionSeeder extends Seeder
{
    use RefusesOutsideLocal;

    // Username => stars earned in the previous month.
    public const STARS = [
        'sarah_johnson' => 6,
        'michael_chen' => 5,
        'emma_williams' => 4,
        'david_brown' => 4,
        'lisa_anderson' => 3,
        'james_wilson' => 2,
    ];

    public const APPROVED = 'sarah_johnson';

    // Username => new-rule stars earned this month (Home's "Leading").
    public const LEADING_STARS = [
        'james_wilson' => 5,
        'lisa_anderson' => 4,
        'david_brown' => 3,
        'michael_chen' => 2,
    ];

    public const REPORT_MARKER = '[Recognition demo: open report]';

    public function run(): void
    {
        if ($this->refusedHere()) {
            return;
        }

        $month = CarbonImmutable::now('UTC')->startOfMonth()->subMonth();
        $position = 0;

        foreach (self::STARS as $username => $count) {
            $counsellor = User::query()->where('username', $username)->first()?->counsellor;

            if (! $counsellor) {
                continue;
            }

            // A first star a day apart per counsellor -- except the tie, which shares one.
            $firstStarAt = $month->addDays(in_array($username, ['emma_williams', 'david_brown'], true) ? 3 : ++$position)->setTime(10, 0);

            for ($i = 1; $i <= $count; $i++) {
                $star = AwardCounsellorStarAction::new()->execute(
                    $counsellor,
                    StarSourceEnum::sessionHeld,
                    $counsellor->user,
                    "seeded_recognition:{$month->format('Y-m')}:{$i}",
                );
                $star?->forceFill(['created_at' => $firstStarAt->addHours($i - 1)])->save();
            }
        }

        $this->approve($month);
        $this->waive($month);
        $this->showcase($month);
        $this->leading();
    }

    // TT-7.11f/SCRUM-476: the rest of last month's recognitions, one per state the showcase handles -- michael_chen
    // accepted, emma_williams declined, james_wilson accepted but with an open report, suspended_counsellor_demo
    // accepted but suspended (with sarah_johnson's pending one, the 5 a month allows). Each is skipped once that
    // counsellor has a row for the month; the report once it exists.
    private function showcase(CarbonImmutable $month): void
    {
        $superAdmin = User::query()->where('username', 'mr_robertamoah')->first();

        if (! $superAdmin) {
            return;
        }

        try {
            $this->recogniseAndAnswer($superAdmin, $month, 'michael_chen', 'approve', RequestStatusEnum::accepted);
            $this->recogniseAndAnswer($superAdmin, $month, 'emma_williams', 'approve', RequestStatusEnum::rejected);
            $this->recogniseAndAnswer($superAdmin, $month, 'james_wilson', 'add', RequestStatusEnum::accepted);
        } catch (ValidationException|AppException $e) {
            // E.g. one of them is no longer eligible in this database: the rest of the seed still runs.
            Log::warning('RecognitionSeeder: a demo recognition was refused.', [
                'errors' => $e instanceof ValidationException ? $e->errors() : $e->getMessage(),
            ]);
        }

        $this->reportJames();
        $this->recogniseSuspended($superAdmin, $month);
    }

    // Through the real service and the counsellor's own answer to the consent request (requests.respond's path).
    private function recogniseAndAnswer(User $superAdmin, CarbonImmutable $month, string $username, string $decide, RequestStatusEnum $answer): void
    {
        $counsellor = User::query()->where('username', $username)->first()?->counsellor;

        if (! $counsellor || CounsellorRecognition::query()->forMonth($month)->where('counsellor_id', $counsellor->id)->exists()) {
            return;
        }

        $recognition = CounsellorRecognitionService::new()->{$decide}(CounsellorRecognitionDTO::new()->fromArray([
            'user' => $superAdmin,
            'month' => $month->format('Y-m'),
            'counsellorId' => $counsellor->id,
        ]));

        $request = $recognition->consentRequests()->wherePending()->latest('id')->first();

        if ($request) {
            RequestService::new()->respondToRequest(RequestResponseDTO::new()->fromArray([
                'user' => $counsellor->user,
                'response' => strtolower($answer->value),
                'request' => $request,
            ]));
        }
    }

    // An open report naming james_wilson (by john_davis, on a therapy of theirs), so his accepted recognition is
    // hidden everywhere until it is resolved or dismissed.
    private function reportJames(): void
    {
        $james = User::query()->where('username', 'james_wilson')->first()?->counsellor;
        $client = User::query()->where('username', 'john_davis')->first();

        if (! $james || ! $client || Report::query()->where('description', 'like', self::REPORT_MARKER.'%')->exists()) {
            return;
        }

        $therapy = $client->addedTherapies()->create([
            'name' => 'Recognition Demo Therapy (reported counsellor)',
            'background_story' => 'Seeded therapy whose counsellor has an open report (SCRUM-476).',
            'counsellor_id' => $james->id,
            'session_type' => TherapySessionTypeEnum::periodic->value,
            'payment_type' => TherapyPaymentTypeEnum::free->value,
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => TherapyStatusEnum::in_session->value,
        ]);

        $report = new Report(['description' => self::REPORT_MARKER.' My counsellor kept missing our sessions.', 'data' => ['counsellorId' => $james->id]]);
        $report->addedby()->associate($client);
        $report->reportable()->associate($therapy);
        $report->save();
    }

    // suspended_counsellor_demo can't be recognised through the service (it refuses a suspended counsellor), so the row
    // is written directly, as if they had been recognised and agreed before their suspension -- with the same events.
    private function recogniseSuspended(User $superAdmin, CarbonImmutable $month): void
    {
        $counsellor = User::query()->where('username', 'suspended_counsellor_demo')->first()?->counsellor;
        $decided = CounsellorRecognition::query()->forMonth($month);

        if (! $counsellor || (clone $decided)->where('counsellor_id', $counsellor->id)->exists()
            || (clone $decided)->approved()->count() >= GetRecognitionSuggestionsAction::perMonth()) {
            return;
        }

        $recognition = (new CounsellorRecognition)->forceFill([
            'counsellor_id' => $counsellor->id,
            'month' => CounsellorRecognition::monthKey($month),
            'decision' => CounsellorRecognitionDecisionEnum::approved,
            'consent' => CounsellorRecognitionConsentEnum::accepted,
            'consent_changed_at' => now(),
            'added_manually' => true,
            'stars_count' => 0,
            'decided_by_id' => $superAdmin->id,
            'decided_at' => now(),
        ]);
        $recognition->save();

        RecordCounsellorRecognitionEventAction::new()->execute($recognition, CounsellorRecognitionEventEnum::added, $superAdmin, ['stars' => 0]);
        RecordCounsellorRecognitionEventAction::new()->execute($recognition, CounsellorRecognitionEventEnum::consentAccepted, $counsellor->user);
    }

    // TT-7.11f/SCRUM-476: this month's new-rule stars, for Home's "Leading (current month)" (signed-in users). james_wilson
    // has the most, but his open report keeps him out. Keyed per month, so running it again adds nothing. Backdated to
    // earlier days of the month where there are any, so the daily caps don't stop the next one.
    private function leading(): void
    {
        $month = CarbonImmutable::now('UTC')->startOfMonth();

        foreach (self::LEADING_STARS as $username => $count) {
            $counsellor = User::query()->where('username', $username)->first()?->counsellor;

            if (! $counsellor) {
                continue;
            }

            for ($i = 1; $i <= $count; $i++) {
                $star = AwardCounsellorStarAction::new()->execute(
                    $counsellor,
                    StarSourceEnum::sessionHeld,
                    $counsellor->user,
                    "seeded_leading:{$month->format('Y-m')}:{$i}",
                );
                $at = CarbonImmutable::now('UTC')->subDays($i)->setTime(9, 0);
                $star?->forceFill(['created_at' => $at->lessThan($month) ? $month : $at])->save();
            }
        }
    }

    private function approve(CarbonImmutable $month): void
    {
        $superAdmin = User::query()->where('username', 'mr_robertamoah')->first();
        $counsellor = User::query()->where('username', self::APPROVED)->first()?->counsellor;

        if (! $superAdmin || ! $counsellor || CounsellorRecognition::query()->forMonth($month)->exists()) {
            return;
        }

        CounsellorRecognitionService::new()->approve(CounsellorRecognitionDTO::new()->fromArray([
            'user' => $superAdmin,
            'month' => $month->format('Y-m'),
            'counsellorId' => $counsellor->id,
        ]));
    }

    private function waive(CarbonImmutable $month): void
    {
        $superAdmin = User::query()->where('username', 'mr_robertamoah')->first();
        $counsellor = User::query()->where('username', self::APPROVED)->first()?->counsellor;
        $recognition = $counsellor
            ? CounsellorRecognition::query()->forMonth($month)->where('counsellor_id', $counsellor->id)->first()
            : null;

        if (! $superAdmin || ! $recognition?->isApproved() || PlatformFeeWaiver::query()->where('counsellor_id', $counsellor->id)->exists()) {
            return;
        }

        $nextMonth = CarbonImmutable::now('UTC')->startOfMonth()->addMonth();

        // A refusal (she has an open report, or lost her verification, in this database) is logged, not fatal: the rest
        // of the seed still runs.
        try {
            PlatformFeeWaiverService::new()->createForCounsellor(
                PlatformFeeWaiverDTO::new()->fromArray([
                    'user' => $superAdmin,
                    'counsellorId' => $counsellor->id,
                    'recognitionId' => $recognition->id,
                ]),
                [
                    'starts_at' => $nextMonth,
                    'ends_at' => $nextMonth->addMonth(),
                    'reason' => 'Counsellor of the month for '.$recognition->monthLabel(),
                ],
            );
        } catch (ValidationException|AppException $e) {
            Log::warning('RecognitionSeeder: the demo commission-free period was refused.', [
                'counsellor' => $counsellor->id,
                'errors' => $e instanceof ValidationException ? $e->errors() : $e->getMessage(),
            ]);
        }
    }
}
