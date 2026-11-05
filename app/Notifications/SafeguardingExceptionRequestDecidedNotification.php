<?php

namespace App\Notifications;

use App\Models\GroupTherapy;
use App\Models\Request;
use App\Models\SafeguardingException;
use App\Models\Therapy;
use App\Support\TherapyLink;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-381: to the counsellor who asked for a safeguarding exception -- whether it was approved
// (and until when) or not. Neutral: never the administrator's reason. SCRUM-73: $closed -- nobody
// decided on it as such; it was closed because it is no longer needed (the member left the group,
// or the super administrator rejected it from the "no longer needed" list), and the counsellor
// isn't told why.
class SafeguardingExceptionRequestDecidedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Request $request, private ?CarbonInterface $endsAt, private bool $closed = false)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email_verified_at ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $therapy = $this->request->for;
        $mail = (new MailMessage)->greeting("Hello {$notifiable->name},");

        $mail = match (true) {
            $this->closed => $mail->subject('Your safeguarding exception request was closed')
                ->line('Your request for a safeguarding exception is no longer needed, so it has been closed.'),
            (bool) $this->endsAt => $mail->subject('Your safeguarding exception request was approved')
                ->line('Your request for a safeguarding exception was approved. The therapy can continue until '.SafeguardingException::formatEnd($this->endsAt).'.'),
            default => $mail->subject('Your safeguarding exception request was not approved')
                ->line('Your request for a safeguarding exception was not approved. The therapy stays paused until the client\'s account has a guardian.'),
        };

        return $therapy instanceof Therapy || $therapy instanceof GroupTherapy
            ? $mail->action('Open the therapy', TherapyLink::to($therapy))
            : $mail;
    }

    public function toArray(object $notifiable): array
    {
        return array_filter([
            'type' => 'safeguarding_exception.request_'.match (true) {
                $this->closed => 'closed',
                (bool) $this->endsAt => 'approved',
                default => 'rejected',
            },
            'requestId' => $this->request->id,
            'endsAt' => $this->endsAt?->toJSON(),
        ]);
    }
}
