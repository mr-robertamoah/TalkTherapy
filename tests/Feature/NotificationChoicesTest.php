<?php

use App\Enums\NotificationCategoryEnum;
use App\Models\Counsellor;
use App\Models\Therapy;
use App\Models\User;
use App\Notifications;
use App\Support\NotificationCategories;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

// SCRUM-40 (TT-5.5): a person chooses, per kind of notification, email and in-app; safety and security notices are
// always sent.

// Every notification that is always sent -- deciding a new notification means adding it here or to a category.
const NCH_ALWAYS_SENT = [
    // Account security
    Notifications\TwoFactorChangedNotification::class, Notifications\TwoFactorResetRequestedNotification::class,
    Notifications\EmailChangedNotification::class, Notifications\ConfirmNewEmailNotification::class,
    Notifications\VerifyEmailNotification::class, Notifications\VerifyCounsellorEmailNotification::class,
    Notifications\AccountEmailChangedByTeamNotification::class, Notifications\DataCopyDownloadedNotification::class,
    Notifications\JournalExportedNotification::class, Notifications\AccountDeletedNotification::class,
    Notifications\CounsellorAccountDeletedNotification::class, Notifications\PayoutDestinationChangedNotification::class,
    // Safety and urgent help
    Notifications\SafetyNoticeNotification::class, Notifications\GuardianSafetyNoticeNotification::class,
    Notifications\SafetyFlagRaisedNotification::class, Notifications\SafetyFlagReAlertNotification::class,
    Notifications\SafetyFlagWithdrawnNotification::class, Notifications\HelpRequestWaitingNotification::class,
    Notifications\HelpTherapyInviteNotification::class, Notifications\SafeguardingExceptionRequestedNotification::class,
    Notifications\SafeguardingExceptionRequestDecidedNotification::class, Notifications\SafeguardingExceptionEndingSoonNotification::class,
    Notifications\CounsellorUnavailableNotification::class,
    // Account standing
    Notifications\SuspensionAppliedNotification::class, Notifications\SuspensionEndedNotification::class,
    Notifications\SuspensionAppealDecidedNotification::class, Notifications\WardSuspendedNotification::class,
    Notifications\ClientAccessPausedNotification::class, Notifications\ClientAccessResumedNotification::class,
    Notifications\GuardianAccessPausedNotification::class, Notifications\GuardianAccessResumedNotification::class,
    // Guardianship, minors and consent
    Notifications\GuardianshipRequestNotification::class, Notifications\GuardianshipEstablishedNotification::class,
    Notifications\GuardianshipRemovedNotification::class, Notifications\GuardianVideoConsentReminderNotification::class,
    Notifications\GroupVideoConsentRequestedNotification::class, Notifications\AgeVerificationRequestApprovedNotification::class,
    Notifications\AgeVerificationRequestRejectedNotification::class, Notifications\DobChangeRequestApprovedNotification::class,
    Notifications\DobChangeRequestRejectedNotification::class, Notifications\DobChangeRequestSupersededNotification::class,
    // Counsellor verification, reports, continuations
    Notifications\CounsellorVerificationEndedNotification::class, Notifications\CounsellorVerificationEndingNotification::class,
    Notifications\CounsellorVerificationRevokedNotification::class, Notifications\ReportClosedNotification::class,
    Notifications\TherapyContinuationNotification::class,
    // Mail-only: switching off the email would leave nothing at all
    Notifications\SessionFailedNotification::class, Notifications\DiscussionFailedNotification::class,
    Notifications\DiscussionCounsellorRemovedNotification::class, Notifications\TherapyAssistanceRequestSentNotification::class,
    Notifications\GroupTherapyAssistanceRequestSentNotification::class, Notifications\GroupTherapyMembershipRequestSentNotification::class,
    Notifications\PayoutFailedNotification::class, Notifications\SessionTopicUnsetNotification::class,
    // Anything a guardian is told about a ward (security review)
    Notifications\TherapyCreatedNotification::class, Notifications\GroupTherapyCounsellorLinkUsedNotification::class,
    Notifications\GroupTherapyMemberJoinedGuardianNotification::class, Notifications\GroupTherapyMemberLeftGuardianNotification::class,
    Notifications\OrganizationMemberJoinedGuardianNotification::class, Notifications\TherapyAssistanceRequestAcceptedGuardianNotification::class,
    // Staff alerts (an organisation's refund that may need manual follow-up too)
    Notifications\OrganizationFinancedTransactionRefundedNotification::class,
    Notifications\CounsellorVerificationEndingAdminNotification::class, Notifications\SuspensionAppealSubmittedNotification::class,
    Notifications\QueueJobFailedNotification::class, Notifications\ReportNotification::class,
    Notifications\RefundRequestedNotification::class, Notifications\RefundExecutionFailedNotification::class,
    Notifications\VisitorsStatusNotification::class, Notifications\OrganizationBillingSuspensionMayBeResolvedNotification::class,
];

function nchUser(array $attributes = []): User
{
    return User::factory()->adult()->create($attributes);
}

// Whether the listener lets $channel through for a notification of $class to $notifiable (events: false stops it).
function nchSends(object $notifiable, string $class, string $channel): bool
{
    $notification = (new ReflectionClass($class))->newInstanceWithoutConstructor();

    return app('events')->until(new NotificationSending($notifiable, $notification, $channel)) !== false;
}

test('every notification is decided: in a category, or always sent', function () {
    $classes = collect(glob(app_path('Notifications/*.php')))
        ->map(fn ($path) => 'App\\Notifications\\'.basename($path, '.php'))
        ->filter(fn ($class) => is_subclass_of($class, Notification::class));

    $undecided = $classes->reject(fn ($class) => array_key_exists($class, NotificationCategories::MAP) || in_array($class, NCH_ALWAYS_SENT, true));
    $both = $classes->filter(fn ($class) => array_key_exists($class, NotificationCategories::MAP) && in_array($class, NCH_ALWAYS_SENT, true));

    expect($undecided->values()->all())->toBe([])->and($both->values()->all())->toBe([]);
});

test('a switched-off channel isn\'t sent; the other one, and a broadcast, still are', function () {
    $me = nchUser(['notification_channels' => ['sessions' => ['mail' => false]]]);

    expect(nchSends($me, Notifications\SessionCreatedNotification::class, 'mail'))->toBeFalse()
        ->and(nchSends($me, Notifications\SessionCreatedNotification::class, 'database'))->toBeTrue()
        ->and(nchSends($me, Notifications\SessionCreatedNotification::class, 'broadcast'))->toBeTrue()
        ->and(nchSends($me, Notifications\GroupTherapyMemberLeftNotification::class, 'mail'))->toBeTrue();
});

test('in-app can be switched off too', function () {
    $me = nchUser(['notification_channels' => ['therapies' => ['database' => false]]]);

    expect(nchSends($me, Notifications\GroupTherapyMemberLeftNotification::class, 'database'))->toBeFalse()
        ->and(nchSends($me, Notifications\GroupTherapyMemberLeftNotification::class, 'mail'))->toBeTrue();
});

test('an always-on notice is sent whatever is switched off', function () {
    $everythingOff = collect(NotificationCategoryEnum::cases())->mapWithKeys(fn ($c) => [$c->value => ['mail' => false, 'database' => false]])->all();
    $me = nchUser(['notification_channels' => $everythingOff]);

    foreach ([Notifications\SafetyNoticeNotification::class, Notifications\TwoFactorChangedNotification::class, Notifications\SuspensionAppliedNotification::class, Notifications\GuardianshipRequestNotification::class] as $class) {
        expect(nchSends($me, $class, 'mail'))->toBeTrue()->and(nchSends($me, $class, 'database'))->toBeTrue();
    }
});

test('switching off in-app also stops the reminders that pop up on the page -- not other live updates', function () {
    $me = nchUser(['notification_channels' => ['sessions' => ['database' => false]]]);

    expect(nchSends($me, Notifications\SessionDueNotification::class, 'broadcast'))->toBeFalse()
        ->and(nchSends($me, Notifications\SessionStatusChangedNotification::class, 'broadcast'))->toBeFalse()
        ->and(nchSends($me, Notifications\SessionCreatedNotification::class, 'broadcast'))->toBeTrue()
        ->and(nchSends(nchUser(), Notifications\SessionDueNotification::class, 'broadcast'))->toBeTrue();
});

test('every notice about a ward is always sent', function () {
    foreach (glob(app_path('Notifications/*Guardian*.php')) as $path) {
        expect(NotificationCategories::MAP)->not->toHaveKey('App\\Notifications\\'.basename($path, '.php'));
    }
});

test('a counsellor sees the counsellors\' kinds too', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => nchUser()->id]);

    $this->actingAs($counsellor->user)->get(route('profile.show'))->assertInertia(fn ($page) => $page
        ->has('notificationChannels', count(NotificationCategoryEnum::cases())));
});

test('a real notification honours the choice: no bell entry, the email still sent', function () {
    Mail::fake();
    $me = nchUser(['email_verified_at' => now(), 'notification_channels' => ['therapies' => ['database' => false]]]);
    $therapy = Therapy::factory()->make(['anonymous' => false, 'public' => false]);
    $therapy->addedby()->associate($me);
    $therapy->counsellor_id = Counsellor::factory()->create(['user_id' => nchUser()->id])->id;
    $therapy->save();
    $sent = [];
    Event::listen(NotificationSent::class, function ($event) use (&$sent) {
        $sent[] = $event->channel;
    });

    $me->notifyNow(new Notifications\TherapyAssistanceLinkNotification($therapy));

    expect($sent)->toBe(['mail'])->and($me->notifications()->count())->toBe(0);
});

test('a counsellor profile follows its person\'s choices; an address on its own is never stopped', function () {
    $user = nchUser(['notification_channels' => ['discussions' => ['mail' => false]]]);
    $counsellor = Counsellor::factory()->create(['user_id' => $user->id]);

    expect(nchSends($counsellor, Notifications\DiscussionRequestNotification::class, 'mail'))->toBeFalse()
        ->and(nchSends((new AnonymousNotifiable)->route('mail', 'x@example.com'), Notifications\DiscussionRequestNotification::class, 'mail'))->toBeTrue();
});

test('the profile shows the choices -- all on to start with -- and saves them', function () {
    $me = nchUser();

    $this->actingAs($me)->get(route('profile.show'))->assertInertia(fn ($page) => $page
        // The counsellors' kinds only for counsellors.
        ->has('notificationChannels', 3)
        ->where('notificationChannels.0.key', 'sessions')
        ->where('notificationChannels.0.mail', true)
        ->where('notificationChannels.0.database', true));

    $channels = collect(NotificationCategoryEnum::cases())->mapWithKeys(fn ($c) => [$c->value => ['mail' => true, 'database' => true]])->all();
    $channels['sessions'] = ['mail' => false, 'database' => true];
    $channels['payments'] = ['mail' => false, 'database' => false];

    $this->post(route('notifications.channels'), ['channels' => $channels])->assertRedirect()->assertSessionHasNoErrors();

    expect($me->fresh()->notification_channels)->toEqualCanonicalizing(['sessions' => ['mail' => false], 'payments' => ['mail' => false, 'database' => false]]);

    $this->post(route('notifications.channels'), ['channels' => collect($channels)->map(fn () => ['mail' => true, 'database' => true])->all()]);
    expect($me->fresh()->notification_channels)->toBeNull();
});

test('an always-on kind, or a made-up one, can\'t be switched off', function () {
    $me = nchUser();

    $this->actingAs($me)->post(route('notifications.channels'), ['channels' => ['safety' => ['mail' => false, 'database' => false]]])->assertSessionHasErrors('channels');
    $this->post(route('notifications.channels'), ['channels' => ['sessions' => ['mail' => 'no', 'database' => true]]])->assertSessionHasErrors('channels.sessions.mail');

    expect($me->fresh()->notification_channels)->toBeNull();
});

test('"What we hold about you" lists the kinds switched off', function () {
    $me = nchUser(['notification_channels' => ['payments' => ['mail' => false, 'database' => false]]]);

    $this->actingAs($me)->get(route('profile.your-data'))->assertInertia(fn ($page) => $page
        ->where('data.notifications.switchedOff', [['kind' => 'Your refunds', 'email' => true, 'inApp' => true]]));
});
