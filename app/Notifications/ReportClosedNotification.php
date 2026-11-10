<?php

namespace App\Notifications;

use App\Contracts\ShownInNotificationCenter;
use App\Enums\ReportStatusEnum;
use App\Models\Report;
use App\Support\NotificationEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// SCRUM-340: sent to the reporter and to the reported person when an admin closes a report. Never
// names the reviewing admin, and never tells the reported person who reported them. When an admin
// read confidential messages during the review, both are told so -- the transparency promise made
// on the confidential-message toggle.
class ReportClosedNotification extends Notification implements ShouldQueue, ShownInNotificationCenter
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
        return self::summaryFor($this->role, $this->closedAs->value);
    }

    // The email's and the notification center's wording, one place (review).
    private static function summaryFor(?string $role, ?string $status): string
    {
        $dismissed = $status === ReportStatusEnum::dismissed->value;

        if ($role === self::REPORTED) {
            return $dismissed
                ? 'A report involving you was reviewed by an administrator and closed without further action.'
                : 'A report involving you was reviewed by an administrator and has been resolved.';
        }

        return $dismissed
            ? 'Thank you for your report. An administrator reviewed it and closed it without further action.'
            : 'Thank you for your report. An administrator reviewed it and has taken it forward.';
    }

    // SCRUM-39 (TT-5.4b): in the notification center -- from the saved ids only, never more than the email.
    public static function present(array $data): NotificationEntry
    {
        return new NotificationEntry(
            ($data['role'] ?? null) === self::REPORTED ? 'A report has been reviewed' : 'Your report has been reviewed',
            self::summaryFor($data['role'] ?? null, $data['status'] ?? null)
                .(! empty($data['confidentialRead']) ? ' An administrator read confidential messages from the conversation for this review; it is recorded.' : ''),
        );
    }
}
