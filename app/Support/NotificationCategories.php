<?php

namespace App\Support;

use App\Enums\NotificationCategoryEnum as Category;
use App\Notifications;
use Illuminate\Notifications\Notification;

// SCRUM-40 (TT-5.5): which notifications a person can switch off, and under which category -- the one list, so the
// always-on ones can be read on one screen. A class that is NOT here is always sent: safety notices and alerts, urgent
// help, two-factor, email changes and verification, data copies and journal exports, suspensions, guardianship and
// anything a guardian is told about their ward (security review: a ward starting a therapy, a counsellor taking them
// on, joining or leaving a group), verification and age checks, a counsellor becoming unavailable, paused access,
// report outcomes, and every staff alert (an organisation's refund needing manual follow-up too). So a new
// notification is never silenced by mistake; NotificationChoicesTest makes each one be decided. Mail-only notices (a
// session or discussion that failed, a request sent) stay always-on: switching off their email would leave nothing.
class NotificationCategories
{
    public const MAP = [
        Notifications\SessionCreatedNotification::class => Category::sessions,
        Notifications\SessionUpdatedNotification::class => Category::sessions,
        Notifications\SessionDeletedNotification::class => Category::sessions,
        Notifications\SessionDueNotification::class => Category::sessions,
        Notifications\SessionStatusChangedNotification::class => Category::sessions,
        Notifications\SessionScheduleProposedNotification::class => Category::sessions,
        Notifications\SessionScheduleProposalAcceptedNotification::class => Category::sessions,
        Notifications\SessionScheduleProposalRejectedNotification::class => Category::sessions,
        // SCRUM-446: marked absent from an in-person session.
        Notifications\SessionMarkedAbsentNotification::class => Category::sessions,

        Notifications\TherapyAssistanceRequestAcceptedNotification::class => Category::therapies,
        Notifications\TherapyAssistanceLinkNotification::class => Category::therapies,
        Notifications\GroupTherapyMembershipRequestAcceptedNotification::class => Category::therapies,
        Notifications\GroupTherapyMembershipRequestRejectedNotification::class => Category::therapies,
        Notifications\GroupTherapyMemberLeftNotification::class => Category::therapies,
        // SCRUM-37: a check-in to answer, and one answered (the attention notice is always sent).
        Notifications\OutcomeCheckInRequestedNotification::class => Category::therapies,
        Notifications\OutcomeCheckInCompletedNotification::class => Category::therapies,

        Notifications\DiscussionRequestNotification::class => Category::discussions,
        Notifications\DiscussionInclusionNotification::class => Category::discussions,
        Notifications\DiscussionUpdatedNotification::class => Category::discussions,
        Notifications\DiscussionStatusChangedNotification::class => Category::discussions,
        Notifications\DiscussionDeletedNotification::class => Category::discussions,
        Notifications\DiscussionDueNotification::class => Category::discussions,

        Notifications\OrganizationCounsellorCompensationChangeProposedNotification::class => Category::organisations,
        Notifications\OrganizationCounsellorCompensationChangeAcceptedNotification::class => Category::organisations,
        Notifications\OrganizationCounsellorCompensationChangeRejectedNotification::class => Category::organisations,
        Notifications\OrganizationCounsellorCompensationChangeExpiredNotification::class => Category::organisations,
        Notifications\OrganizationCounsellorCompensationChangeExpiryReminderNotification::class => Category::organisations,

        Notifications\RefundSucceededNotification::class => Category::payments,
        Notifications\RefundFailedNotification::class => Category::payments,
        Notifications\RefundRequestRejectedNotification::class => Category::payments,

        // TT-7.11d1/SCRUM-473.
        Notifications\CounsellorRecognisedNotification::class => Category::recognition,
        // TT-7.11e/SCRUM-475: given their own commission-free period.
        Notifications\CounsellorFeeWaiverGrantedNotification::class => Category::recognition,
    ];

    // These pop up on the page when they arrive (AuthenticatedLayout): switching off "in the app" stops that live
    // message too (code review), not only the bell's entry.
    public const POP_UPS = [
        Notifications\SessionDueNotification::class,
        Notifications\DiscussionDueNotification::class,
        Notifications\SessionStatusChangedNotification::class,
    ];

    // Null: always sent.
    public static function for(Notification $notification): ?Category
    {
        return self::MAP[$notification::class] ?? null;
    }
}
