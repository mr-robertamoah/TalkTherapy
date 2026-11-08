<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

// SCRUM-368: the privacy policy states the configured retention periods, read on each request --
// never copies that could drift from what the scheduled jobs actually do.
class PrivacyController extends Controller
{
    public function __invoke()
    {
        return Inertia::render('Privacy', [
            'retentionDays' => [
                'closedAccount' => config('user.closed_account_retention_days'),
                'counsellorProfile' => config('counsellor.deletion_grace_period_days'),
                'identityDocument' => config('identity_verification.document_retention_days'),
                'licenseRejected' => config('counsellor.license_rejected_retention_days'),
                'nationalId' => config('counsellor.national_id_retention_days'),
                'licenseAbandoned' => config('counsellor.license_abandoned_retention_days'),
                // SCRUM-33 (TT-4.4c): urgent help chats.
                'helpChat' => config('help.transcript_retention_days'),
            ],
        ]);
    }
}
