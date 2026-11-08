<?php

namespace Database\Seeders;

use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications\CounsellorVerificationEndingNotification;
use App\Notifications\DiscussionDueNotification;
use App\Notifications\DobChangeRequestRejectedNotification;
use App\Notifications\GroupTherapyMembershipRequestAcceptedNotification;
use App\Notifications\RefundSucceededNotification;
use App\Notifications\ReportClosedNotification;
use App\Notifications\SessionCreatedNotification;
use App\Notifications\SessionScheduleProposedNotification;
use App\Notifications\SessionUpdatedNotification;
use App\Notifications\TherapyAssistanceRequestAcceptedNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// SCRUM-39 (TT-5.4c): notifications for the bell -- maria_garcia gets more than a page (so "Show more"
// shows), read and unread; sarah_johnson gets some on her account and some on her counsellor profile
// (labelled "As counsellor"). Saved with the keys each class's toArray() saves (plus a marker key, which
// the center never shows), pointing at the chat demo therapy, group and discussion. Safe to run again: a
// person who already has them is skipped; each person's set is written in one transaction.
class NotificationCenterSeeder extends Seeder
{
    private const MARKER = 'scrum39Demo';

    public function run(): void
    {
        $client = User::query()->where('username', 'maria_garcia')->first();
        $counsellorUser = User::query()->where('username', 'sarah_johnson')->first();
        $therapy = Therapy::query()->where('name', 'Chat Demo Individual Therapy')->first();
        $group = GroupTherapy::query()->where('name', 'Chat Demo Group Therapy')->first();
        $discussion = Discussion::query()->where('name', 'Chat Demo Discussion')->first();

        if (! $client || ! $counsellorUser?->counsellor || ! $therapy || ! $group || ! $discussion) {
            return;
        }

        $this->seedFor($client, $this->clientNotices($therapy, $group));
        $this->seedFor($counsellorUser, $this->counsellorUserNotices());
        $this->seedFor($counsellorUser->counsellor, $this->counsellorNotices($therapy, $discussion));
    }

    /** @param list<array{0: class-string, 1: array, 2: int, 3: bool}> $notices [type, data, minutes ago, read] */
    private function seedFor(User|Counsellor $notifiable, array $notices): void
    {
        if ($notifiable->notifications()->where('data->'.self::MARKER, true)->exists()) {
            return;
        }

        DB::transaction(fn () => $this->insert($notifiable, $notices));
    }

    private function insert(User|Counsellor $notifiable, array $notices): void
    {
        foreach ($notices as [$type, $data, $minutesAgo, $read]) {
            $at = now()->subMinutes($minutesAgo);
            $notifiable->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => $type,
                'data' => [...$data, self::MARKER => true],
                'read_at' => $read ? $at->copy()->addMinutes(5) : null,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
    }

    private function clientNotices(Therapy $therapy, GroupTherapy $group): array
    {
        $notices = [
            [SessionCreatedNotification::class, ['session_id' => 0, 'therapyId' => $therapy->id], 5, false],
            [SessionScheduleProposedNotification::class, ['therapyId' => $therapy->id, 'requestId' => 0], 30, false],
            [GroupTherapyMembershipRequestAcceptedNotification::class, ['group_therapy_id' => $group->id], 90, false],
            [TherapyAssistanceRequestAcceptedNotification::class, ['therapy_id' => $therapy->id, 'kind' => 'help'], 180, true],
            [RefundSucceededNotification::class, ['refundId' => 0, 'transactionId' => 0], 300, true],
            [ReportClosedNotification::class, ['reportId' => 0, 'role' => ReportClosedNotification::REPORTER, 'status' => 'resolved', 'confidentialRead' => false], 600, true],
            [DobChangeRequestRejectedNotification::class, ['request_id' => 0], 900, true],
        ];

        // Older session updates, enough to need "Show more".
        foreach (range(1, 12) as $day) {
            $notices[] = [SessionUpdatedNotification::class, ['session_id' => 0, 'therapyId' => $therapy->id], 1440 * $day, $day > 2];
        }

        return $notices;
    }

    private function counsellorUserNotices(): array
    {
        return [
            [DobChangeRequestRejectedNotification::class, ['request_id' => 0], 45, false],
        ];
    }

    private function counsellorNotices(Therapy $therapy, Discussion $discussion): array
    {
        return [
            [DiscussionDueNotification::class, ['discussion_id' => $discussion->id], 10, false],
            [SessionScheduleProposedNotification::class, ['therapyId' => $therapy->id, 'requestId' => 0], 60, false],
            [CounsellorVerificationEndingNotification::class, ['type' => 'counsellor_verification.ending', 'expiresAt' => now()->addDays(20)->toJSON(), 'daysLeft' => 20], 2880, true],
        ];
    }
}
