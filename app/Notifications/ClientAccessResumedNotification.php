<?php

namespace App\Notifications;

use App\Models\GroupTherapy;
use App\Models\SafeguardingException;
use App\Models\Therapy;
use App\Support\TherapyLink;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-288 (split out of GuardianAccessResumedNotification in SCRUM-382): to the counsellor of an
// individual therapy that was paused for its minor client's missing guardian and has started
// again. The client is named as that therapy shows them ($clientName from displayNameOf(), so an
// anonymous client stays anonymous). Nothing about the client's family.
// SCRUM-381: also to each counsellor of a group the minor belongs to, and with $continuesUntil when
// a safeguarding exception lets them carry on until then (never its reason).
class ClientAccessResumedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Therapy|GroupTherapy $therapy, private string $clientName, private ?CarbonInterface $continuesUntil = null)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email_verified_at ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isGroup = $this->therapy instanceof GroupTherapy;
        $until = $this->continuesUntil ? ' for now, until '.SafeguardingException::formatEnd($this->continuesUntil) : '';

        $line = $isGroup
            ? "{$this->clientName} can take part in your group \"{$this->therapy->name}\" again{$until}."
            : ($until ? "Your therapy with {$this->clientName} can continue{$until}." : "Your therapy with {$this->clientName} is no longer paused.");

        $mail = (new MailMessage)
            ->subject($isGroup ? 'A group member can take part again' : 'A paused therapy has started again')
            ->greeting("Hello {$notifiable->name},")
            ->line($line);

        if ($until) {
            $mail->line('Video calls with them still need a guardian\'s consent.');
        }

        return $mail->action($isGroup ? 'Open the group' : 'Open the therapy', TherapyLink::to($this->therapy));
    }

    public function toArray(object $notifiable): array
    {
        return array_filter([
            'type' => 'guardian_access.client_resumed',
            ...TherapyLink::ids($this->therapy),
            'clientName' => $this->clientName,
            'continuesUntil' => $this->continuesUntil?->toJSON(),
        ]);
    }
}
