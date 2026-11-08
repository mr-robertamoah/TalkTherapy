<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\WardRef;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-32 (TT-4.3c): the neutral notice to a minor's guardian on an imminent or urgent safety flag -- in
// the app and by email (the user's default). It names their ward and nothing else: not the therapy, the
// counsellor, the kind (it must never reveal an urgent help chat), the reason, or that a flag exists. The
// WardRef lets anonymising the ward's closed account rename them here. Wording pending the legal read.
class GuardianSafetyNoticeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private string $wardName;

    private string $wardRef;

    // Server-side only (the queued job), never in the stored data: to skip a ward closed meanwhile.
    private int $wardId;

    // What the guardian reads in the app (the dialog now; SCRUM-395's list later) and the email's first line.
    public static function message(array $data = []): string
    {
        $ward = (string) ($data['wardName'] ?? 'your ward');

        return "The TalkTherapy team would like you to check in with {$ward} and make sure they have the support they need right now. If you think they may be in danger, please contact emergency services now.";
    }

    public function __construct(User $ward)
    {
        $this->wardName = $ward->firstName ?: 'your ward';
        $this->wardRef = WardRef::of($ward);
        $this->wardId = $ward->id;
        $this->afterCommit();
    }

    // Never once the feature is off, nor for a ward whose account has been closed meanwhile (security review).
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $ward = User::withTrashed()->find($this->wardId);

        return (bool) config('safety.enabled') && $ward && ! $ward->trashed() && ! $ward->anonymised_at;
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email_verified_at ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Please check in with {$this->wardName}")
            ->greeting("Hello {$notifiable->name},")
            ->line(self::message(['wardName' => $this->wardName]))
            ->line('TalkTherapy isn\'t an emergency service.')
            ->action('Open TalkTherapy', route('home'));
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'safety.guardian_notice', 'wardName' => $this->wardName, WardRef::KEY => $this->wardRef];
    }
}
