<?php

namespace Database\Seeders;

use App\Actions\Star\AwardCounsellorStarAction;
use App\DTOs\CounsellorRecognitionDTO;
use App\Enums\StarSourceEnum;
use App\Models\CounsellorRecognition;
use App\Models\User;
use App\Services\CounsellorRecognitionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

// TT-7.11d1/SCRUM-473: new-rule stars for the previous month (UTC) on the six demo counsellors, so the recognition
// suggestions have a ranking to show -- including a tie (emma_williams and david_brown: same count, same first star) --
// and one decision already made: sarah_johnson (rank 1) approved by the super admin (mr_robertamoah), with her featuring
// consent request pending on her counsellor dashboard. Made through the real award action and recognition service.
// Stars are keyed per month, so running it again adds nothing; the approval is skipped once that month has one.
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
}
