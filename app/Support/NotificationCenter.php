<?php

namespace App\Support;

use App\Actions\Safety\GetSafetyNoticeAction;
use App\Contracts\ShownInNotificationCenter;
use App\Models\Counsellor;
use App\Models\DatabaseNotification;
use App\Models\User;
use App\Notifications\AgeVerificationRequestApprovedNotification;
use App\Notifications\AgeVerificationRequestRejectedNotification;
use App\Notifications\CounsellorVerificationEndingAdminNotification;
use App\Notifications\DiscussionDeletedNotification;
use App\Notifications\DiscussionDueNotification;
use App\Notifications\DiscussionInclusionNotification;
use App\Notifications\DiscussionRequestNotification;
use App\Notifications\DiscussionStatusChangedNotification;
use App\Notifications\DiscussionUpdatedNotification;
use App\Notifications\DobChangeRequestApprovedNotification;
use App\Notifications\DobChangeRequestRejectedNotification;
use App\Notifications\DobChangeRequestSupersededNotification;
use App\Notifications\GroupTherapyCounsellorLinkUsedNotification;
use App\Notifications\GroupTherapyMemberJoinedGuardianNotification;
use App\Notifications\GroupTherapyMembershipRequestAcceptedNotification;
use App\Notifications\GroupTherapyMembershipRequestRejectedNotification;
use App\Notifications\GuardianshipEstablishedNotification;
use App\Notifications\GuardianshipRemovedNotification;
use App\Notifications\GuardianshipRequestNotification;
use App\Notifications\GuardianVideoConsentReminderNotification;
use App\Notifications\HelpRequestWaitingNotification;
use App\Notifications\OrganizationCounsellorCompensationChangeAcceptedNotification;
use App\Notifications\OrganizationCounsellorCompensationChangeExpiredNotification;
use App\Notifications\OrganizationCounsellorCompensationChangeExpiryReminderNotification;
use App\Notifications\OrganizationCounsellorCompensationChangeProposedNotification;
use App\Notifications\OrganizationCounsellorCompensationChangeRejectedNotification;
use App\Notifications\OrganizationFinancedTransactionRefundedNotification;
use App\Notifications\RefundFailedNotification;
use App\Notifications\RefundRequestRejectedNotification;
use App\Notifications\RefundSucceededNotification;
use App\Notifications\ReportClosedNotification;
use App\Notifications\SafeguardingExceptionEndingSoonNotification;
use App\Notifications\SafeguardingExceptionRequestedNotification;
use App\Notifications\SafetyFlagRaisedNotification;
use App\Notifications\SafetyFlagReAlertNotification;
use App\Notifications\SafetyFlagWithdrawnNotification;
use App\Notifications\SessionCreatedNotification;
use App\Notifications\SessionDeletedNotification;
use App\Notifications\SessionDueNotification;
use App\Notifications\SessionScheduleProposalAcceptedNotification;
use App\Notifications\SessionScheduleProposalRejectedNotification;
use App\Notifications\SessionScheduleProposedNotification;
use App\Notifications\SessionUpdatedNotification;
use App\Notifications\SuspensionAppealSubmittedNotification;
use App\Notifications\TherapyAssistanceLinkNotification;
use App\Notifications\TherapyAssistanceRequestAcceptedGuardianNotification;
use App\Notifications\TherapyAssistanceRequestAcceptedNotification;
use App\Notifications\TherapyContinuationNotification;
use App\Notifications\TherapyCreatedNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

// SCRUM-39: the notification center's rules, in one place.
// - Whose: the viewer's own notifications and their counsellor profile's, in one list (the user's
//   decision), always read through the guarded DatabaseNotification model (never the raw table: it keeps
//   ward references out, SCRUM-387).
// - What's hidden (in the query, so counts and pages are right): what only administrators are sent, from
//   anyone who isn't one now; and the safety flag's notifications while the feature is off.
// - What the list never marks read: the person's and a guardian's safety notices -- only their own
//   dialog dismisses them (SCRUM-32 TT-4.3c).
// - What it shows: present() -- a fixed shape, never the saved data, a class name or an internal id.
final class NotificationCenter
{
    // Sent only to administrators (the classes marked SentOnlyToAdministrators -- a test keeps these equal).
    public const ADMIN_ONLY = [
        HelpRequestWaitingNotification::class,
        SafetyFlagRaisedNotification::class,
        SafetyFlagWithdrawnNotification::class,
        CounsellorVerificationEndingAdminNotification::class,
        ...self::SUPER_ADMIN_ONLY,
    ];

    // Sent only to super administrators (marked SentOnlyToSuperAdministrators): hidden from an
    // administrator who isn't one now too (security review).
    public const SUPER_ADMIN_ONLY = [
        SafetyFlagReAlertNotification::class,
        SuspensionAppealSubmittedNotification::class,
        SafeguardingExceptionRequestedNotification::class,
        SafeguardingExceptionEndingSoonNotification::class,
    ];

    // Hidden while SAFETY_FLAGS_ENABLED is off (SCRUM-32).
    public const SAFETY_GATED = [
        SafetyFlagRaisedNotification::class,
        SafetyFlagReAlertNotification::class,
        SafetyFlagWithdrawnNotification::class,
        ...GetSafetyNoticeAction::TYPES,
    ];

    // Dismissed only by their own dialog (GetSafetyNoticeAction), never by the list.
    public const NEVER_MARKED_BY_LIST = GetSafetyNoticeAction::TYPES;

    /** @var array<string, true> */
    private static array $warned = [];

    /** @return list<class-string> */
    public static function hiddenTypesFor(User $viewer): array
    {
        return array_values(array_unique([
            ...match (true) {
                ! $viewer->isAdmin() => self::ADMIN_ONLY,
                ! $viewer->isSuperAdmin() => self::SUPER_ADMIN_ONLY,
                default => [],
            },
            ...(config('safety.enabled') ? [] : self::SAFETY_GATED),
        ]));
    }

    public static function unreadCount(User $viewer): int
    {
        return self::query($viewer)->whereNull('read_at')->count();
    }

    // The viewer's notifications (and their counsellor profile's), hidden types left out.
    public static function query(User $viewer): Builder
    {
        $counsellor = $viewer->counsellor;

        return DatabaseNotification::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('notifiable_type', $viewer->getMorphClass())->where('notifiable_id', $viewer->getKey()))
                ->when($counsellor, fn (Builder $query) => $query->orWhere(fn (Builder $query) => $query
                    ->where('notifiable_type', $counsellor->getMorphClass())->where('notifiable_id', $counsellor->getKey()))))
            ->whereNotIn('type', self::hiddenTypesFor($viewer));
    }

    // What the list shows for one notification. Nothing from the row but its id, time and read state.
    public static function present(DatabaseNotification $notification): array
    {
        $type = $notification->type;
        $entry = is_string($type) && class_exists($type) && is_subclass_of($type, ShownInNotificationCenter::class)
            ? rescue(fn () => $type::present((array) $notification->data), null, report: true)
            : null;

        if (! $entry) {
            // Once per type per process, not once per row on every list load (review).
            if (! in_array($type, self::PENDING_WORDING, true) && ! isset(self::$warned[$type])) {
                self::$warned[$type] = true;
                Log::warning('A notification has nothing for the notification center to show.', ['notification' => $notification->id]);
            }
            $entry = new NotificationEntry('You have an update');
        }

        return [
            'id' => $notification->id,
            'title' => $entry->title,
            'message' => $entry->message,
            'url' => $entry->url,
            'createdAt' => $notification->created_at?->toIso8601String(),
            'read' => $notification->read_at !== null,
            'audience' => $notification->notifiable_type === (new Counsellor)->getMorphClass() ? 'counsellor' : 'user',
            // Opens the safety notice's own dialog instead of a link (SCRUM-32).
            'safetyNotice' => in_array($type, self::NEVER_MARKED_BY_LIST, true),
        ];
    }

    // TT-5.4b: classes that store only ids and get their wording there. Until then they show generically,
    // without a warning each time. Emptied by TT-5.4b (a test keeps the rest honest).
    public const PENDING_WORDING = [
        AgeVerificationRequestApprovedNotification::class,
        AgeVerificationRequestRejectedNotification::class,
        DiscussionDeletedNotification::class,
        DiscussionDueNotification::class,
        DiscussionInclusionNotification::class,
        DiscussionRequestNotification::class,
        DiscussionStatusChangedNotification::class,
        DiscussionUpdatedNotification::class,
        DobChangeRequestApprovedNotification::class,
        DobChangeRequestRejectedNotification::class,
        DobChangeRequestSupersededNotification::class,
        GroupTherapyCounsellorLinkUsedNotification::class,
        GroupTherapyMemberJoinedGuardianNotification::class,
        GroupTherapyMembershipRequestAcceptedNotification::class,
        GroupTherapyMembershipRequestRejectedNotification::class,
        GuardianshipEstablishedNotification::class,
        GuardianshipRemovedNotification::class,
        GuardianshipRequestNotification::class,
        GuardianVideoConsentReminderNotification::class,
        OrganizationCounsellorCompensationChangeAcceptedNotification::class,
        OrganizationCounsellorCompensationChangeExpiredNotification::class,
        OrganizationCounsellorCompensationChangeExpiryReminderNotification::class,
        OrganizationCounsellorCompensationChangeProposedNotification::class,
        OrganizationCounsellorCompensationChangeRejectedNotification::class,
        OrganizationFinancedTransactionRefundedNotification::class,
        RefundFailedNotification::class,
        RefundRequestRejectedNotification::class,
        RefundSucceededNotification::class,
        ReportClosedNotification::class,
        SessionCreatedNotification::class,
        SessionDeletedNotification::class,
        SessionDueNotification::class,
        SessionScheduleProposalAcceptedNotification::class,
        SessionScheduleProposalRejectedNotification::class,
        SessionScheduleProposedNotification::class,
        SessionUpdatedNotification::class,
        TherapyAssistanceLinkNotification::class,
        TherapyAssistanceRequestAcceptedGuardianNotification::class,
        TherapyAssistanceRequestAcceptedNotification::class,
        TherapyContinuationNotification::class,
        TherapyCreatedNotification::class,
    ];
}
