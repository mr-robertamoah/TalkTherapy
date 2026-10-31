<?php

namespace App\Http\Controllers;

use App\Actions\Message\EnsureUserCanViewMessageAttachmentAction;
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
            fn () => EnsureUserCanViewMessageAttachmentAction::new()->execute($httpRequest->user(), $message, $file),
            $file->storage,
            $file,
            'Attachment not found.',
            self::INLINE_MEDIA_TYPES,
        );
    }
}
