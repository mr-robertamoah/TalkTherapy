<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Enums\SuspensionScopeEnum;
use App\Models\Suspension;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-359: tells a person they have been suspended -- what it covers, until when, the reason
// written for them and how to appeal. Never names the reporter or the administrator. Mailed when
// the address is verified; always kept in-app.
class SuspensionAppliedNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public function __construct(private Suspension $suspension)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email_verified_at ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $account = $this->suspension->scope == SuspensionScopeEnum::account;

        return (new MailMessage)
            ->subject($account ? 'Your TalkTherapy account has been suspended' : 'Your counsellor role on TalkTherapy has been suspended')
            ->greeting("Hello {$notifiable->name},")
            ->line($account
                ? 'Following a review, your TalkTherapy account has been suspended. You will not be able to use TalkTherapy while it lasts.'
                : 'Following a review, your counsellor role on TalkTherapy has been suspended. You can still use TalkTherapy as a client, but you cannot work as a counsellor while it lasts.')
            ->line('Ends: '.self::until($this->suspension))
            ->line('Reason: '.$this->suspension->public_reason)
            ->lineIf((bool) $notifiable->counsellor, 'Your payouts are on hold until the suspension ends. Nothing you have earned is lost; you can withdraw it once it ends.')
            ->line('You can\'t delete your account while you are suspended.')
            ->action('Read about your suspension or appeal', route('suspended.show'))
            ->line('You can appeal once. A different administrator will look at it.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'suspension.applied',
            'suspensionId' => $this->suspension->id,
            'scope' => $this->suspension->scope->value,
            'endsAt' => $this->suspension->ends_at?->toJSON(),
        ];
    }

    public static function until(Suspension $suspension): string
    {
        return $suspension->ends_at ? $suspension->ends_at->toDayDateTimeString().' (UTC)' : 'when an administrator lifts it';
    }

    // SCRUM-39: in the notification center.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry('You have been suspended', 'The reason and how to appeal are on your suspension page.', NotificationEntry::link('suspended.show', []));
    }
}
