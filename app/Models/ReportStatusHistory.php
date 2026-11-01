<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// SCRUM-340: one row per review step on a report (a status change, or an admin naming the reported
// party). Written only by TransitionReportStatusAction and RecordReportedPartyAction.
class ReportStatusHistory extends Model
{
    public const EVENT_STATUS_CHANGE = 'status_change';

    public const EVENT_PARTY_RECORDED = 'party_recorded';

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
