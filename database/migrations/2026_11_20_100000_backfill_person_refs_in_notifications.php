<?php

use App\Actions\User\AnonymiseClosedAccountAction;
use App\Enums\RequestTypeEnum;
use App\Models\Counsellor;
use App\Models\User;
use App\Notifications\CounsellorUnavailableNotification;
use App\Notifications\HelpTherapyInviteNotification;
use App\Notifications\SessionScheduleProposalRejectedNotification;
use App\Support\WardRef;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// SCRUM-396: notices saved before they carried a reference to the person they name -- a counsellor named
// to their clients, and someone's reason for declining a session time -- get one where the saved data leads
// to that person, so anonymising their account later takes them out. Where that account is already
// anonymised, the name or reason is taken out now (and a declined time's reason on the request itself too).
//  - help invite: its help request's counsellor;
//  - declined time: the request's recipient (only they can decline);
//  - "counsellor unavailable": the suspension it came from (its end date is saved as `until`; suspensions
//    outlive anonymisation and a purged counsellor profile) -- the name only breaks a tie.
// What can't be traced (e.g. a counsellor profile purged for good) is counted in the log and left as it is.
// Data only; down() does nothing (a reference is harmless, and a cleared name can't come back).
return new class extends Migration
{
    public function up(): void
    {
        $counts = ['referenced' => 0, 'cleared' => 0, 'unmatched' => 0];

        DB::table('notifications')
            ->whereIn('type', [CounsellorUnavailableNotification::class, HelpTherapyInviteNotification::class, SessionScheduleProposalRejectedNotification::class])
            ->whereNull('ward_ref')
            ->select(['id', 'type', 'data', 'created_at'])
            // By id, never by offset: a row given a reference leaves the filtered set (security review).
            ->lazyById(500, 'id')
            ->each(function (object $row) use (&$counts) {
                $data = json_decode((string) $row->data, true);
                // Nothing of theirs to take out: a declined time with no reason.
                if (! is_array($data) || ($row->type === SessionScheduleProposalRejectedNotification::class && blank($data['reason'] ?? null))) {
                    return;
                }

                $userId = match ($row->type) {
                    HelpTherapyInviteNotification::class => $this->helpCounsellorUserId($data['helpRequestId'] ?? null),
                    SessionScheduleProposalRejectedNotification::class => $this->rejecterUserId($data['requestId'] ?? null),
                    default => $this->suspendedCounsellorUserId($data, $row->created_at),
                };
                if (! $userId) {
                    $counts['unmatched']++;

                    return;
                }

                $anonymised = DB::table('users')->where('id', $userId)->whereNotNull('anonymised_at')->exists();
                $data = $anonymised ? AnonymiseClosedAccountAction::withoutPerson($data) : [...$data, WardRef::KEY => WardRef::of($userId)];
                $counts[$anonymised ? 'cleared' : 'referenced']++;

                DB::table('notifications')->where('id', $row->id)->update(['data' => json_encode($data)]);
            });

        $counts['requestReasonsCleared'] = $this->clearAnonymisedDeclineReasons();

        Log::info('SCRUM-396: person references backfilled in stored notices.', $counts);
    }

    public function down(): void {}

    private function helpCounsellorUserId(mixed $helpRequestId): ?int
    {
        if (! is_numeric($helpRequestId)) {
            return null;
        }

        $userId = DB::table('help_requests')->join('counsellors', 'counsellors.id', '=', 'help_requests.counsellor_id')
            ->where('help_requests.id', (int) $helpRequestId)->value('counsellors.user_id');

        return $userId ? (int) $userId : null;
    }

    // The one who declined: the request's recipient (a user, or a counsellor profile's owner).
    private function rejecterUserId(mixed $requestId): ?int
    {
        $request = is_numeric($requestId) ? DB::table('requests')->where('id', (int) $requestId)->first(['to_type', 'to_id']) : null;

        return $request ? $this->userIdOf($request->to_type, $request->to_id) : null;
    }

    // The suspension that sent it: the one ending when the notice says (or open-ended), applied by the time
    // it was sent -- the latest such. Among several at once, the name breaks the tie.
    private function suspendedCounsellorUserId(array $data, mixed $sentAt): ?int
    {
        $until = is_string($data['until'] ?? null) ? rescue(fn () => Carbon::parse($data['until']), null, report: false) : null;
        if (($data['until'] ?? null) !== null && ! $until) {
            return null;
        }

        $candidates = DB::table('suspensions')
            ->when($until, fn ($query) => $query->where('ends_at', $until->copy()->utc()->toDateTimeString()), fn ($query) => $query->whereNull('ends_at'))
            ->when($sentAt, fn ($query) => $query->where('created_at', '<=', $sentAt))
            ->orderByDesc('created_at')
            ->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values();

        if ($candidates->count() > 1) {
            $name = $data['counsellorName'] ?? null;
            $candidates = $candidates->filter(fn (int $userId) => $this->counsellorNameOf($userId) === $name)->values();
        }

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    private function counsellorNameOf(int $userId): ?string
    {
        $counsellor = Counsellor::withTrashed()->where('user_id', $userId)->first();
        $user = User::withTrashed()->find($userId);

        return $counsellor?->name ?: $user?->name;
    }

    // A declined time's reason kept on the request itself, where its author is already anonymised.
    private function clearAnonymisedDeclineReasons(): int
    {
        $cleared = 0;
        DB::table('requests')->where('type', RequestTypeEnum::sessionScheduleProposal->value)
            ->select(['id', 'to_type', 'to_id', 'data'])
            ->lazyById(500, 'id')
            ->each(function (object $request) use (&$cleared) {
                $data = json_decode((string) $request->data, true);
                $userId = $this->userIdOf($request->to_type, $request->to_id);
                if (! is_array($data) || blank($data['reason'] ?? null) || ! $userId
                    || ! DB::table('users')->where('id', $userId)->whereNotNull('anonymised_at')->exists()) {
                    return;
                }

                unset($data['reason']);
                DB::table('requests')->where('id', $request->id)->update(['data' => json_encode($data)]);
                $cleared++;
            });

        return $cleared;
    }

    private function userIdOf(?string $type, mixed $id): ?int
    {
        $userId = match ($type) {
            User::class => $id,
            Counsellor::class => DB::table('counsellors')->where('id', $id)->value('user_id'),
            default => null,
        };

        return $userId ? (int) $userId : null;
    }
};
