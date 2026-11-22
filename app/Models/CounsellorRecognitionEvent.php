<?php

namespace App\Models;

use App\Enums\CounsellorRecognitionEventEnum;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// TT-7.11d1/SCRUM-473: see the counsellor_recognition_events migration. Written only by
// RecordCounsellorRecognitionEventAction.
class CounsellorRecognitionEvent extends Model
{
    use AppendOnly;

    protected $guarded = ['id'];

    protected $casts = [
        'event' => CounsellorRecognitionEventEnum::class,
        'data' => 'array',
    ];

    public function recognition(): BelongsTo
    {
        return $this->belongsTo(CounsellorRecognition::class, 'recognition_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
