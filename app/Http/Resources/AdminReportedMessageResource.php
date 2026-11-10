<?php

namespace App\Http\Resources;

use App\Actions\Report\EnsureAdminCanViewReportedAttachmentAction;
use App\DTOs\ReportUnlockScope;
use App\Models\Counsellor;
use App\Models\Message;
use App\Models\Report;

// SCRUM-340: how a message appears to an admin reviewing a report -- and nowhere else (an arch test
// keeps it out of every other class, and a test pins its exact keys). A separate, stricter presenter,
// not a view of MessageResource: it never carries a user id, only each person's role in the report,
// and it builds every field itself so nothing added to MessageResource later can reach it.
//  - Content participants can still see (not confidential, not deleted) is shown.
//  - Content they can't (confidential, or deleted for everyone) is shown only when the reported
//    person sent or received it; deleted messages are labelled.
class AdminReportedMessageResource
{
    public const KEYS = [
        'id', 'type', 'topicId', 'replyingToId', 'confidential', 'deletedForEveryone', 'hidden',
        'sender', 'recipient', 'fromReportedPerson', 'content', 'files', 'status', 'createdAt',
    ];

    public static function present(Message $message, Report $report, ReportUnlockScope $scope): array
    {
        $hidden = EnsureAdminCanViewReportedAttachmentAction::isRestricted($message) && ! $scope->reveals($message);

        return [
            'id' => $message->id,
            'type' => $message->type,
            'topicId' => $message->therapy_topic_id,
            'replyingToId' => $message->message_id,
            'confidential' => (bool) $message->confidential,
            'deletedForEveryone' => ! is_null($message->deleted_at),
            'hidden' => $hidden,
            'sender' => self::roleOf($message->from_type, $message->from_id, $scope->sentByParty($message), $report),
            'recipient' => $message->to_type
                ? self::roleOf($message->to_type, $message->to_id, $scope->receivedByParty($message), $report)
                : null,
            'fromReportedPerson' => $scope->sentByParty($message),
            'content' => $hidden ? null : $message->content,
            'files' => $hidden ? [] : $message->files->map(fn ($file) => [
                'id' => $file->id,
                'name' => $file->name,
                'mime' => $file->mime,
                'url' => route('admin.reports.attachments.show', ['report' => $report->id, 'message' => $message->id, 'file' => $file->id]),
            ])->values()->all(),
            'status' => $message->status,
            'createdAt' => $message->created_at,
        ];
    }

    private static function roleOf(?string $type, $id, bool $isParty, Report $report): string
    {
        if ($isParty) {
            return 'Reported person';
        }

        if ($report->addedby_type == $type && (int) $report->addedby_id === (int) $id) {
            return 'Reporter';
        }

        return $type == Counsellor::class ? 'Counsellor' : 'Client';
    }
}
