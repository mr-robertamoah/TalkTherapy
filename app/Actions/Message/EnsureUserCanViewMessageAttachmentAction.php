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
    // Transitional extras (SCRUM-341 removes them once every environment has migrated): 'public'
    // for rows the move migration hasn't reached yet (code deploys before migrate), and 'local' for
    // rows from host-run installs whose default disk was the already-private 'local'. A file on any
    // other disk is never streamed under message rules.
    private const ATTACHMENT_DISKS = ['message_attachments', 'public', 'local'];

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
