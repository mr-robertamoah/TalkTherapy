<?php

namespace App\Actions\Report;

use App\Actions\Action;
use App\Actions\Message\EnsureUserCanViewMessageAttachmentAction;
use App\DTOs\ReportUnlockScope;
use App\Exceptions\ReportException;
use App\Models\ConfidentialMessageAccess;
use App\Models\File;
use App\Models\Message;
use App\Models\Report;
use App\Models\Session;
use App\Models\User;

// SCRUM-340: an attachment in a reported conversation -- only from a session this admin already
// opened for this report, and for a confidential or deleted message only one that was revealed to
// them then. Every refusal is the same 404, like the other private-file routes.
class EnsureAdminCanViewReportedAttachmentAction extends Action
{
    public const NOT_FOUND = 'Attachment not found.';

    public function execute(User $admin, Report $report, ReportUnlockScope $scope, ?Message $message, ?File $file): Session
    {
        $session = $message?->for instanceof Session ? $message->for : null;

        $accessed = fn (array $where) => ConfidentialMessageAccess::query()
            ->where('report_id', $report->id)
            ->where('admin_user_id', $admin->id)
            ->where($where)
            ->exists();

        $allowed = $session && $file
            && in_array($file->storage, EnsureUserCanViewMessageAttachmentAction::ATTACHMENT_DISKS, true)
            && $scope->allowsSession($session)
            && $message->files()->where('files.id', $file->id)->exists()
            && $accessed(['session_id' => $session->id])
            && (! self::isRestricted($message) || ($scope->reveals($message) && $accessed(['message_id' => $message->id])));

        if (! $allowed) {
            throw new ReportException(self::NOT_FOUND, 404);
        }

        return $session;
    }

    // Content participants can't currently see: confidential, or deleted for everyone.
    public static function isRestricted(Message $message): bool
    {
        return $message->confidential || ! is_null($message->deleted_at);
    }
}
