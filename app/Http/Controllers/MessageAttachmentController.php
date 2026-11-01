<?php

namespace App\Http\Controllers;

use App\Actions\Admin\RecordAdminContentReadAction;
use App\Actions\Message\EnsureUserCanViewMessageAttachmentAction;
use App\Models\AdminContentAccess;
use App\Models\File;
use App\Models\Message;
use App\Traits\ResolvesExceptionResponse;
use App\Traits\StreamsPrivateDocuments;
use Illuminate\Http\Request;

// SCRUM-336: the ONLY retrieval path for a chat attachment -- never a public asset() URL.
class MessageAttachmentController extends Controller
{
    use ResolvesExceptionResponse, StreamsPrivateDocuments;

    public function show(Request $httpRequest, Message $message, File $file)
    {
        // The disk is whichever EnsureUserCanViewMessageAttachmentAction allows -- never an
        // arbitrary one named by the row.
        return $this->authorizedDocumentResponse(
            function () use ($httpRequest, $message, $file) {
                EnsureUserCanViewMessageAttachmentAction::new()->execute($httpRequest->user(), $message, $file);
                // SCRUM-360
                RecordAdminContentReadAction::new()->execute($httpRequest->user(), AdminContentAccess::MESSAGE_ATTACHMENT, $message, fileId: $file->id);
            },
            $file->storage,
            $file,
            'Attachment not found.',
            self::INLINE_MEDIA_TYPES,
        );
    }
}
