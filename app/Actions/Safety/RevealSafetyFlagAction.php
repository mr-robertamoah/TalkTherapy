<?php

namespace App\Actions\Safety;

use App\Actions\Action;
use App\Actions\Admin\RecordAdminContentReadAction;
use App\Enums\SafetyFlagKindEnum;
use App\Models\AdminContentAccess;
use App\Models\GroupTherapy;
use App\Models\SafetyFlag;
use App\Models\SafetyFlagNote;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// SCRUM-32: what only the administrator handling a safety flag sees, each look recorded in the read log
// (before anything is returned):
// - who(): the client (for an anonymous one, the only way anyone learns who they are), where it was
//   raised and by which counsellor;
// - reason(): the counsellor's reason, whether they discussed it with the client, and whether
//   emergency services were contacted; and (TT-4.3b) the notes and actions so far, the counsellor's
//   reason for withdrawing it, and how it was resolved; and (TT-4.3c) why a notice was held or withheld,
//   each reason with its author (the counsellor's safeguarding reason included).
class RevealSafetyFlagAction extends Action
{
    public function who(?User $admin, ?SafetyFlag $flag): array
    {
        $flag = EnsureCanHandleSafetyFlagAction::new()->execute($admin, $flag, handler: true);
        RecordAdminContentReadAction::new()->execute($admin, AdminContentAccess::SAFETY_FLAG_IDENTITY, $flag);

        $client = $flag->client;
        $flaggable = $flag->flaggable;
        $counsellor = $flag->counsellor;

        return [
            'client' => [
                'name' => $client?->name,
                'username' => $client?->username,
                'email' => $client?->email,
                // From the membership the flag was raised for (none for a group's creator).
                'leftGroup' => $flaggable instanceof GroupTherapy && $flag->membership_id
                    && DB::table('group_therapy_user')->where('id', $flag->membership_id)->whereNotNull('left_at')->exists(),
            ],
            'where' => [
                'kind' => $flag->kind->label(),
                'name' => $flag->kind === SafetyFlagKindEnum::helpChat ? 'Urgent help chat' : $flaggable?->name,
                'status' => $flaggable?->status,
            ],
            'counsellor' => [
                'name' => $counsellor?->getName(),
                'username' => $counsellor?->user?->username,
            ],
        ];
    }

    public function reason(?User $admin, ?SafetyFlag $flag): array
    {
        $flag = EnsureCanHandleSafetyFlagAction::new()->execute($admin, $flag, handler: true);
        RecordAdminContentReadAction::new()->execute($admin, AdminContentAccess::SAFETY_FLAG_REASON, $flag);

        return [
            'reason' => $flag->reason,
            'discussedWithClient' => $flag->discussed_with_client->value,
            'emergencyServicesContacted' => $flag->emergency_services_contacted,
            'emergencyServicesNote' => $flag->emergency_services_note,
            'withdrawnReason' => $flag->withdrawn_reason,
            'outcome' => $flag->outcome?->label(),
            'outcomeNote' => $flag->outcome_note,
            'notes' => $flag->notes()->with('author')->get()->map(fn (SafetyFlagNote $note) => AddSafetyFlagNoteAction::present($note))->all(),
        ];
    }
}
