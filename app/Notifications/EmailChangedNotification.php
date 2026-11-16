<?php

namespace App\Notifications;

use App\Support\MaskedEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifications;
use Illuminate\Support\Facades\URL;

// SCRUM-422/427: to the account's current address, when verified, at each step of a change of email: asked for, its new
// address confirmed (and when it takes effect), taken effect -- or the email removed. Sent straight to it (on demand),
// mail only (an in-app entry could go to whoever holds the account). The new address only ever masked, never "reset
// your password" (that link could go elsewhere). While the change hasn't taken effect, a signed "This wasn't me" link
// stops it and signs the account out everywhere (security review: an attacker confirms their own address in seconds).
class EmailChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const REQUESTED = 'requested';

    public const CONFIRMED = 'confirmed';

    public const APPLIED = 'applied';

    public const REMOVED = 'removed';

    public const STOP_LINK_DAYS = 4;

    public const GOOGLE_DISCONNECTED = 'Google sign-in was disconnected from the account too. It can be connected again from the profile once signed in.';

    public function __construct(
        public string $name,
        public ?string $maskedNewEmail,
        public string $changedAt,
        public bool $byTeam = false,
        public string $stage = self::REQUESTED,
        public ?int $userId = null,
        public ?string $pendingHash = null,
        public ?string $effectiveAt = null,
        public bool $accountHasEmail = true,
        public bool $stale = false,
        // SCRUM-455: the team's change also disconnected Google sign-in (ApplyEmailChangeAction).
        public bool $googleDisconnected = false,
    ) {
        $this->afterCommit();
    }

    // The one way to send it: nothing when the current address isn't verified. $name is the one the account had before
    // the save that asked for the change.
    // $accountHasEmail false: this address was removed from the account (it's still the protected one).
    // SCRUM-434: $stale -- this is a remembered address from longer ago than auth.email_stop_restore_days (User::
    // protectedEmailIsStale()). Its mailbox may have passed to someone else, so they're told only that a change was asked
    // for and to contact support: no name, no new address, no "This wasn't me" (which would let a stranger stop the
    // owner's real change and lock them out).
    public static function sendTo(?string $currentEmail, string $name, ?string $newEmail, bool $byTeam = false, string $stage = self::REQUESTED, ?int $userId = null, ?string $effectiveAt = null, ?string $pendingHash = null, bool $accountHasEmail = true, bool $stale = false, bool $googleDisconnected = false): void
    {
        // SCRUM-434 (code review): a long-remembered address hears that a change was asked for and when it takes effect
        // -- not a second, near-identical email when the new address is confirmed.
        if (! $currentEmail || ($stale && $stage === self::CONFIRMED && $newEmail)) {
            return;
        }

        Notifications::route('mail', $currentEmail)->notify(new self(
            // Not even queued with it (security review).
            $stale ? '' : $name,
            $newEmail && ! $stale ? MaskedEmail::of($newEmail) : null,
            now()->format('j F Y, H:i T'),
            $byTeam,
            $newEmail ? $stage : self::REMOVED,
            $userId,
            $newEmail && $userId && ! $stale ? $pendingHash : null,
            $effectiveAt,
            $accountHasEmail,
            $stale,
            $googleDisconnected,
        ));
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->stale) {
            return $this->staleMail();
        }

        $to = $this->maskedNewEmail;
        $who = $this->byTeam ? 'The TalkTherapy team asked' : 'Someone asked';

        $message = (new MailMessage)
            ->subject(match ($this->stage) {
                self::REMOVED, self::APPLIED => 'Your account email was changed',
                self::CONFIRMED => "Your account email changes on {$this->effectiveAt}",
                default => 'A change of your account email was requested',
            })
            ->greeting("Hello {$this->name}!")
            ->line(match ($this->stage) {
                self::REMOVED => "This email address was removed from your TalkTherapy account on {$this->changedAt}.",
                self::APPLIED => "Your TalkTherapy account's email is now {$to} (since {$this->changedAt}). This address no longer gets emails about the account.",
                self::CONFIRMED => "The new address for your TalkTherapy account, {$to}, was confirmed on {$this->changedAt}. It becomes the account's email on {$this->effectiveAt}".($this->accountHasEmail ? ' -- until then this address stays the account\'s.' : '.'),
                default => $this->accountHasEmail
                    ? "{$who} to change the email address on your TalkTherapy account from this one to {$to} on {$this->changedAt}. It only takes effect once the new address is confirmed -- until then this address stays the account's."
                    : "{$who} on {$this->changedAt} to put {$to} on your TalkTherapy account, which this address was removed from. It only takes effect once that address is confirmed, and two days after that.",
            })
            ->lineIf($this->googleDisconnected, self::GOOGLE_DISCONNECTED);

        if ($this->stopUrl()) {
            $message->line($this->byTeam ? 'If you asked us to, no further action is needed.' : 'If you made this change, no further action is needed.')
                // SCRUM-433: with no email on the account, a long-remembered address isn't put back -- support then.
                ->line('If you did NOT, stop it now: it cancels the change and signs your account out everywhere. Then reset your password'
                    .($this->accountHasEmail ? '.' : ' -- or, if the page says the account has no email to send it to, contact TalkTherapy support.'))
                ->action('This wasn\'t me', $this->stopUrl());
        } else {
            $message->line('If you did NOT make this change, someone else may have access to your account: contact TalkTherapy support from this address straight away.');
        }

        return $message->line("Thank you for choosing to 'TalkTherapy'.");
    }

    // SCRUM-434: to a long-remembered address -- what happened and to contact support, nothing that names the account or
    // lets the reader act on it.
    private function staleMail(): MailMessage
    {
        return (new MailMessage)
            ->subject($this->stage === self::APPLIED ? 'A TalkTherapy account email was changed' : 'A change of a TalkTherapy account email was requested')
            ->greeting('Hello!')
            ->line($this->stage === self::APPLIED
                ? "The email address of a TalkTherapy account this address was once used for was changed on {$this->changedAt}."
                : "A change of email address was requested on {$this->changedAt} for a TalkTherapy account this address was once used for. It takes effect once the new address is confirmed, and two days after that.")
            ->line('If that account is yours and you did NOT make this change, contact TalkTherapy support straight away and we\'ll look into it.')
            ->line('If it isn\'t your account, you can ignore this email.')
            ->line("Thank you for choosing to 'TalkTherapy'.");
    }

    // Only while the change hasn't taken effect.
    private function stopUrl(): ?string
    {
        if (! in_array($this->stage, [self::REQUESTED, self::CONFIRMED], true) || ! $this->userId || ! $this->pendingHash) {
            return null;
        }

        return URL::temporarySignedRoute('email.change.stop.show', now()->addDays(self::STOP_LINK_DAYS), ['id' => $this->userId, 'hash' => $this->pendingHash]);
    }
}
