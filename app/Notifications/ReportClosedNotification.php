<?php

namespace App\Notifications;

use App\Enums\ReportStatusEnum;
use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-340: sent to the reporter and to the reported person when an admin closes a report. Never
// names the reviewing admin, and never tells the reported person who reported them. When an admin
// read confidential messages during the review, both are told so -- the transparency promise made
// on the confidential-message toggle.
class ReportClosedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const REPORTER = 'reporter';

    public const REPORTED = 'reported';

    public function __construct(
        private Report $report,
        // The status it closed with, fixed now: the job runs later, maybe after a reopen.
        private ReportStatusEnum $closedAs,
        private string $role,
        private bool $confidentialRead,
    ) {}

    public function via(object $notifiable): array
    {
        return $notifiable->email_verified_at ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('A report has been reviewed')
            ->greeting("Hello {$notifiable->name}!")
            ->line($this->summary());

        if ($this->confidentialRead) {
            $mail->line('As part of this review, an administrator read confidential messages from the reported conversation. Access was limited to this report and is recorded.');
        }

        return $mail->line('Thank you for helping keep TalkTherapy safe.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'reportId' => $this->report->id,
            'role' => $this->role,
            'status' => $this->closedAs->value,
            'confidentialRead' => $this->confidentialRead,
        ];
    }

    private function summary(): string
    {
        $dismissed = $this->closedAs == ReportStatusEnum::dismissed;

        if ($this->role == self::REPORTER) {
            return $dismissed
                ? 'Thank you for your report. An administrator reviewed it and closed it without further action.'
                : 'Thank you for your report. An administrator reviewed it and has taken it forward.';
        }

        return $dismissed
            ? 'A report involving you was reviewed by an administrator and closed without further action.'
            : 'A report involving you was reviewed by an administrator and has been resolved.';
    }
}
