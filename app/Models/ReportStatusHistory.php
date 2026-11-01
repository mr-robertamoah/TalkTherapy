<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// SCRUM-340: one row per review step on a report (a status change, or an admin naming the reported
// party). Written by TransitionReportStatusAction, RecordReportedPartyAction and (SCRUM-359) the
// suspension actions.
class ReportStatusHistory extends Model
{
    public const EVENT_STATUS_CHANGE = 'status_change';

    public const EVENT_PARTY_RECORDED = 'party_recorded';

    // SCRUM-359: a suspension applied from the report, and what happened to it after.
    public const EVENT_SUSPENSION_APPLIED = 'suspension_applied';

    public const EVENT_SUSPENSION_LIFTED = 'suspension_lifted';

    public const EVENT_SUSPENSION_ENDED = 'suspension_ended';

    public const EVENT_APPEAL_SUBMITTED = 'appeal_submitted';

    public const EVENT_APPEAL_DECIDED = 'appeal_decided';

    protected $guarded = ['id'];

    protected $casts = ['data' => 'array'];

    public function report()
    {
        return $this->belongsTo(Report::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
