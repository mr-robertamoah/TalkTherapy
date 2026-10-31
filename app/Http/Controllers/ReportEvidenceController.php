<?php

namespace App\Http\Controllers;

use App\Actions\Report\EnsureUserCanViewReportEvidenceAction;
use App\Models\File;
use App\Models\Report;
use App\Traits\ResolvesExceptionResponse;
use App\Traits\StreamsPrivateDocuments;
use Illuminate\Http\Request;

// SCRUM-337: the ONLY retrieval path for report evidence -- never a public asset() URL.
class ReportEvidenceController extends Controller
{
    use ResolvesExceptionResponse, StreamsPrivateDocuments;

    public function show(Request $httpRequest, Report $report, File $file)
    {
        return $this->authorizedDocumentResponse(
            fn () => EnsureUserCanViewReportEvidenceAction::new()->execute($httpRequest->user(), $report, $file),
            $file->storage,
            $file,
            'Evidence not found.',
            self::INLINE_DOCUMENT_TYPES,
        );
    }
}
