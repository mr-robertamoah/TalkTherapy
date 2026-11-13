<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// TT-3.1e-e/SCRUM-284: one row per Session (SCRUM-313: and ward -- a group can have several minors) that has ever needed a guardian video-consent
// reminder -- day_before_sent_at/hour_before_sent_at track each reminder window independently,
// exactly-once, the same way Request.reminder_sent_at does for compensation-change expiry.
class VideoConsentReminder extends Model
{
    use HasFactory;

    protected $fillable = ['session_id', 'ward_id', 'day_before_sent_at', 'hour_before_sent_at'];

    protected $casts = [
        'day_before_sent_at' => 'datetime',
        'hour_before_sent_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(Session::class);
    }

    public function ward()
    {
        return $this->belongsTo(User::class, 'ward_id');
    }
}
