<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-438: a counsellor's notes on a therapy were exported as a PDF -- so a stolen session and password can't take them unseen. Never names the therapy or the client.
// Never carries the data, or a link to it.
class TherapyNotesExportedNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    public function __construct(public string $downloadedAt)
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // The address a recent email change replaced: by mail only.
        if ($notifiable instanceof AnonymousNotifiable) {
            return ['mail'];
        }

        $via = ['database'];

        if ($notifiable->email && $notifiable->email_verified_at) {
            $via[] = 'mail';
        }

        return $via;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your notes were exported')
            ->greeting($notifiable instanceof AnonymousNotifiable ? 'Hello!' : "Hello {$notifiable->name}!")
            ->line("Your notes on one of your therapies were exported as a PDF from your account on {$this->downloadedAt}.")
            ->line('If you did this, no further action is needed.')
            ->line('If you did NOT, change your password now and contact support -- someone else may have access to your account.')
            ->action('Open your profile', route('profile.show'))
            ->line("Thank you for choosing to 'TalkTherapy'.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['downloadedAt' => $this->downloadedAt];
    }

    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry(
            'Your notes were exported',
            'Your notes on a therapy were exported as a PDF'.(isset($data['downloadedAt']) ? " on {$data['downloadedAt']}" : '').'. Not you? Change your password now.',
            NotificationEntry::link('profile.show', []),
        );
    }
}
