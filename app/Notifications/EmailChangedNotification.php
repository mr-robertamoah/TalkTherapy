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
    ) {
        $this->afterCommit();
    }

    // The one way to send it: nothing when the current address isn't verified. $name is the one the account had before
    // the save that asked for the change.
    // $accountHasEmail false: this address was removed from the account (it's still the protected one).
    public static function sendTo(?string $currentEmail, string $name, ?string $newEmail, bool $byTeam = false, string $stage = self::REQUESTED, ?int $userId = null, ?string $effectiveAt = null, ?string $pendingHash = null, bool $accountHasEmail = true): void
    {
        if (! $currentEmail) {
            return;
        }

        Notifications::route('mail', $currentEmail)->notify(new self(
            $name,
            $newEmail ? MaskedEmail::of($newEmail) : null,
            now()->format('j F Y, H:i T'),
            $byTeam,
            $newEmail ? $stage : self::REMOVED,
            $userId,
            $newEmail && $userId ? $pendingHash : null,
            $effectiveAt,
            $accountHasEmail,
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
                    : "{$who} on {$this->changedAt} to put {$to} on your TalkTherapy account, which this address was recently removed from. It only takes effect once that address is confirmed, and two days after that.",
            });

        if ($this->stopUrl()) {
            $message->line($this->byTeam ? 'If you asked us to, no further action is needed.' : 'If you made this change, no further action is needed.')
                ->line('If you did NOT, stop it now: it cancels the change and signs your account out everywhere. Then reset your password.')
                ->action('This wasn\'t me', $this->stopUrl());
        } else {
            $message->line('If you did NOT make this change, someone else may have access to your account: contact TalkTherapy support from this address straight away.');
        }

        return $message->line("Thank you for choosing to 'TalkTherapy'.");
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
