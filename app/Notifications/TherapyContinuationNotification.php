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

// SCRUM-381: a minor with no guardian can carry on in therapy under a super administrator's
// safeguarding exception -- this tells them (no $therapy) or one of their counsellors ($therapy and
// the client's name as that therapy shows them, displayNameOf()) that it was RENEWED with a new end
// date, or is ENDING_SOON (the 3-day heads-up). Neutral: never the reason, nothing about the family --
// and (SCRUM-382 review) never "for longer", since a renewal can also bring the end forward.
class TherapyContinuationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const RENEWED = 'renewed';

    public const ENDING_SOON = 'ending_soon';

    public function __construct(
        private string $kind,
        private CarbonInterface $endsAt,
        private Therapy|GroupTherapy|null $therapy = null,
        private ?string $clientName = null,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return $notifiable->email_verified_at ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $until = SafeguardingException::formatEnd($this->endsAt);
        $mail = (new MailMessage)->greeting("Hello {$notifiable->name},");

        if (! $this->therapy) {
            return $this->kind === self::RENEWED
                ? $mail->subject('Your therapy on TalkTherapy can continue')
                    ->line("Your therapy can continue for now, until {$until}.")
                    ->action('Go to TalkTherapy', route('home'))
                : $mail->subject('Your therapy on TalkTherapy will be paused soon')
                    ->line("Your therapy can continue until {$until}. After that it will be paused again, unless a parent or guardian has been added to your account by then.")
                    ->action('Add a guardian', route('profile.show'))
                    ->line('If you need support urgently, please contact your local emergency services or a crisis line.');
        }

        $where = $this->therapy instanceof GroupTherapy
            ? "{$this->clientName}'s participation in your group \"{$this->therapy->name}\""
            : "Your therapy with {$this->clientName}";

        $mail = $this->kind === self::RENEWED
            ? $mail->subject('The date a therapy can continue until has changed')->line("The date it can continue until has changed. {$where} can now continue until {$until}.")
            : $mail->subject('A therapy will be paused soon')->line("{$where} can continue until {$until}. After that it pauses again, unless their account has a guardian by then.");

        return $mail->action($this->therapy instanceof GroupTherapy ? 'Open the group' : 'Open the therapy', TherapyLink::to($this->therapy));
    }

    public function toArray(object $notifiable): array
    {
        return array_filter([
            'type' => "guardian_access.continuation_{$this->kind}",
            ...($this->therapy ? TherapyLink::ids($this->therapy) : []),
            'clientName' => $this->clientName,
            'endsAt' => $this->endsAt->toJSON(),
        ]);
    }
}
