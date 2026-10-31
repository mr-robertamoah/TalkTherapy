<?php

namespace App\Actions\Message;

use App\Actions\Action;
use App\Exceptions\MessageAttachmentAccessDeniedException;
use App\Models\File;
use App\Models\Message;
use App\Models\User;
use App\Services\MessageService;

// SCRUM-336: gates the only retrieval path for a chat attachment -- whoever may see the message
// itself (the same rule as api.messages.show and the messages.{id} channel), except that a
// confidential message's files are for its parties only, exactly like its content. Every refusal
// is the same 404, like the identity/license document routes.
class EnsureUserCanViewMessageAttachmentAction extends Action
{
    // SCRUM-341: only the private disk. The transitional 'public'/'local' allowances are gone: the
    // deploy (scripts/deploy.sh) now keeps the site down from pull to migrate, so no request can
    // see a row the move migrations haven't reached, and 2026_10_31_120000 moves 'local' rows too.
    // A file on any other disk is never streamed under message rules.
    private const ATTACHMENT_DISKS = ['message_attachments'];

    public function execute(User $user, Message $message, File $file): void
    {
        $isAttached = $message->files()->where('files.id', $file->id)->exists();
        $canSeeMessage = $message->isParty($user) || MessageService::new()->userCanSeeMessage($message, $user);
        $hiddenAsConfidential = $message->confidential && $message->isNotParty($user);

        if ($isAttached && $canSeeMessage && ! $hiddenAsConfidential && in_array($file->storage, self::ATTACHMENT_DISKS, true)) {
            return;
        }

        throw new MessageAttachmentAccessDeniedException('Attachment not found.', 404);
    }
}
