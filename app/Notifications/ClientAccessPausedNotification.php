<?php

namespace App\Notifications;

use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;
use App\Support\TherapyLink;
use App\Support\WardRef;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-288: to the counsellor of an individual therapy paused because its minor client has no
// active guardian. The client is named as that therapy shows them ($clientName from
// displayNameOf(), so an anonymous client stays anonymous). Nothing about the client's family.
// SCRUM-381 (the user's decision): also to each counsellor of a group the minor belongs to -- only
// that member is paused there; the group carries on.
class ClientAccessPausedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    // SCRUM-384: the minor as an opaque WardRef (never their id), so anonymising their closed
    // account can replace $clientName in the stored notice. Nullable with a default: a notice queued
    // before this property existed is unserialised without it, and is stored without the key.
    private ?string $wardRef = null;

    public function __construct(private Therapy|GroupTherapy $therapy, private string $clientName, User $ward)
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
        if ($this->therapy instanceof GroupTherapy) {
            return (new MailMessage)
                ->subject('A group member\'s participation is paused')
                ->greeting("Hello {$notifiable->name},")
                ->line("{$this->clientName}'s participation in your group \"{$this->therapy->name}\" is paused until their account has a guardian again.")
                ->line('They can still read the group, but can\'t write in it or join its calls. The rest of the group carries on as usual.')
                ->action('Open the group', TherapyLink::to($this->therapy))
                ->line('It starts again on its own. You will be told when it does.');
        }

        return (new MailMessage)
            ->subject('A therapy is paused')
            ->greeting("Hello {$notifiable->name},")
            ->line("Your therapy with {$this->clientName} is paused until their account has a guardian again.")
            ->line('You can still read its history. Messages, sessions and calls are on hold, and upcoming sessions stay booked; you can still cancel one.')
            ->action('Open the therapy', TherapyLink::to($this->therapy))
            ->line('It starts again on its own. You will be told when it does.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'guardian_access.client_paused',
            ...TherapyLink::ids($this->therapy),
            'clientName' => $this->clientName,
            ...($this->wardRef ? [WardRef::KEY => $this->wardRef] : []),
        ];
    }
}
