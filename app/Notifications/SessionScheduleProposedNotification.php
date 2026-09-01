<?php

namespace App\Notifications;

use App\Models\Counsellor;
use App\Models\Request;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-207 (TT-2.5b): sent to the recipient (`to`) of a new session-schedule proposal or
// counter-offer -- a Counsellor on the client's turn, or a User (the client) on the counsellor's
// counter-offer turn. Mirrors OrganizationCounsellorCompensationChangeProposedNotification.
class SessionScheduleProposedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Request $request)
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $via = ['database'];

        if ($notifiable->email_verified_at) {
            $via[] = 'mail';
        }

        return $via;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $therapy = $this->request->for;

        return (new MailMessage)
            ->subject('New Session Time Proposed')
            ->greeting("Hello {$this->notifiableName($notifiable)}!")
            ->line("A new session day/time has been proposed for therapy: {$therapy->name}.")
            ->line($this->describeTimes())
            ->action('Go Home', url(''))
            ->line("Thank you for choosing to 'TalkTherapy'.");
    }

    private function notifiableName(object $notifiable): string
    {
        return $notifiable instanceof Counsellor ? $notifiable->getName() : $notifiable->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'therapyId' => $this->request->for_id,
            'requestId' => $this->request->id,
        ];
    }

    private function describeTimes(): string
    {
        $data = $this->request->data;

        if (! isset($data['startTime'], $data['endTime'])) {
            return 'Proposed time is available in the app.';
        }

        return "Proposed: {$data['startTime']} to {$data['endTime']}.";
    }
}
