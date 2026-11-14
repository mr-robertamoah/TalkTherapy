<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

// SCRUM-427: to a new address someone asked to put on an account -- it becomes the account's only once this link's page
// is confirmed (signed, 24 hours, bound to this exact address: a link for an earlier request can't confirm a later one).
// No name in it (security review: an unconfirmed address -- a typo, someone else's -- mustn't learn who holds an account
// here).
class ConfirmNewEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const HOURS = 24;

    public function __construct(public int $userId, public string $email, public string $hash)
    {
        $this->afterCommit();
    }

    // Bound to the address and to the request (QA: a link from a cancelled request mustn't work again when the same
    // address is asked for later), and labelled so it can't stand in for another of the app key's uses.
    public static function hashOf(string $email, ?\DateTimeInterface $requestedAt): string
    {
        return hash_hmac('sha256', 'email-change|'.mb_strtolower($email).'|'.($requestedAt?->format('U.u') ?? ''), (string) config('app.key'));
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
        $url = URL::temporarySignedRoute('email.change.confirm.show', now()->addHours(self::HOURS), [
            'id' => $this->userId,
            'hash' => $this->hash,
        ]);

        return (new MailMessage)
            ->subject('Confirm your new email address')
            ->greeting('Hello!')
            ->line('Someone asked to use this address for a TalkTherapy account. It only becomes the account\'s once you confirm it.')
            ->action('Confirm this address', $url)
            ->line('The link works for '.self::HOURS.' hours. If you didn\'t expect this, ignore it -- nothing changes.');
    }
}
