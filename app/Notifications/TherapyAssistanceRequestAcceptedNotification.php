<?php

namespace App\Notifications;

use App\Enums\ConstantsEnum;
use App\Models\Counsellor;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TherapyAssistanceRequestAcceptedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(private Request $request)
    {
        $this->afterCommit();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $via = ['database', 'broadcast'];

        if ($notifiable->email_verified_at) {
            $via[] = 'mail';
        }

        return $via;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $isCounsellor = false;
        if ($notifiable::class == Counsellor::class) {
            $isCounsellor = true;
        }

        $name = $notifiable::class == Counsellor::class ? $notifiable->getName() : $notifiable->name;

        return (new MailMessage)
            ->success()
            ->subject('Assistance Request Accepted')
            ->greeting("Hello {$name}!")
            // SCRUM-358: a group invite -- sent by the group's creator (a user OR a counsellor) and
            // accepted by a counsellor joining the group. Its own wording either way.
            ->when(! $this->request->for->isTherapy, function ($mail) {
                $acceptedBy = $this->request->to instanceof Counsellor
                    ? "counsellor with name: '{$this->request->to->getName()}'"
                    : $this->acceptedByPhrase();
                $mail->line("Your invitation to join the group therapy with name: '{$this->request->for->name}' has been accepted by {$acceptedBy}.");
            })
            ->when($this->request->for->isTherapy && $isCounsellor, function ($mail) {
                $mail
                    ->line("Your request to render assistance for the therapy with name: '{$this->request->for->name}' has been accepted by {$this->acceptedByPhrase()}.")
                    ->line("Start creating sessions and lets help make user's wellbeing better.");
            })
            ->when($this->request->for->isTherapy && ! $isCounsellor, function ($mail) {
                $mail
                    ->line("Your request for assistance for the therapy with name: '{$this->request->for->name}' has been accepted by counsellor with name: '{$this->request->to->getName()}'.")
                    ->action('Visit Counsellor Page', url("counsellors/{$this->request->to_id}"));
            })
            ->line('You may receive other reminders before the starting time.')
            // SCRUM-358: also sent for group invites now -- link to the right page.
            ->action(
                $this->request->for->isTherapy ? 'Visit Therapy Page' : 'Visit Group Therapy Page',
                url(($this->request->for->isTherapy ? 'therapies/' : 'group-therapies/').$this->request->for->id)
            )
            ->line("Thank you for choosing to 'TalkTherapy'.");
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            // SCRUM-358: group invites use this notification too -- never label a group as a therapy.
            $this->request->for->isTherapy ? 'therapy_id' : 'group_therapy_id' => $this->request->for_id,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'therapy' => [
                'id' => $this->request->for->id,
                'name' => $this->request->for->name,
                'backgroundStory' => $this->request->for->background_story,
            ],
            'to' => $this->request->to instanceof Counsellor
                ? $this->request->to->getName()
                : $this->acceptedByName(),
        ]);
    }

    public function broadcastType(): string
    {
        return 'therapy.assistance';
    }

    // SCRUM-56: masked by the therapy's anonymity *for that user* (isAnonymousFor), so a group
    // member's own anonymity choice counts, not just the group-level flag. Only ever a User here --
    // a counsellor is never masked.
    private function acceptedByName(): string
    {
        $user = $this->request->to;

        // Only reached for a User `to` (toBroadcast handles a Counsellor first). A deleted user is
        // never named either.
        if (! $user instanceof User) {
            return ConstantsEnum::anonymousPersonPhrase->value;
        }

        return $this->request->for->isAnonymousFor($user) ? ConstantsEnum::anonymousPersonPhrase->value : $user->name;
    }

    private function acceptedByPhrase(): string
    {
        $name = $this->acceptedByName();

        return $name === ConstantsEnum::anonymousPersonPhrase->value ? $name : "user with name: '{$name}'";
    }
}
