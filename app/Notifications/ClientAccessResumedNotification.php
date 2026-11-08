<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Models\GroupTherapy;
use App\Models\SafeguardingException;
use App\Models\Therapy;
use App\Models\User;
use App\Support\NotificationEntry;
use App\Support\TherapyLink;
use App\Support\WardRef;
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
class ClientAccessResumedNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
{
    use Queueable;

    // SCRUM-384: the minor as an opaque WardRef (never their id), so anonymising their closed
    // account can replace $clientName in the stored notice. Nullable with a default: a notice queued
    // before this property existed is unserialised without it, and is stored without the key.
    private ?string $wardRef = null;

    public function __construct(private Therapy|GroupTherapy $therapy, private string $clientName, User $ward, private ?CarbonInterface $continuesUntil = null)
    {
        $this->wardRef = WardRef::of($ward);
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
            WardRef::KEY => $this->wardRef,
            'continuesUntil' => $this->continuesUntil?->toJSON(),
        ]);
    }

    // SCRUM-39: in the notification center.
    public static function present(array $data): NotificationEntry
    {
        $name = NotificationEntry::text($data, 'clientName', 'A client');
        $until = NotificationEntry::date($data['continuesUntil'] ?? null);

        return new NotificationEntry("{$name} can take part again", $until ? "For now, until {$until}." : '', TherapyLink::fromIds($data));
    }
}
