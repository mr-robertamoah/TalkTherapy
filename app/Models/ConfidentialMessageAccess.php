<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

// SCRUM-340: the audit trail of an admin reading confidential messages for a report. Append-only:
// a row can be created, never changed or deleted through the model.
class ConfidentialMessageAccess extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    // The one way rows are written (architect review). $messageIds: one row each (none = the
    // session-open row only). Inserted together, inside the caller's transaction.
    public static function record(Report $report, User $admin, int $sessionId, string $reason, array $messageIds = [], ?int $fileId = null): void
    {
        $base = [
            'report_id' => $report->id,
            'admin_user_id' => $admin->id,
            'session_id' => $sessionId,
            'file_id' => $fileId,
            'reason' => $reason,
            'ip' => request()?->ip(),
            'user_agent' => ($agent = request()?->userAgent()) ? mb_substr($agent, 0, 512) : null,
            'created_at' => now(),
        ];

        static::query()->insert(array_map(
            fn ($messageId) => [...$base, 'message_id' => $messageId],
            $messageIds ?: [null],
        ));
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_user_id')->withTrashed();
    }
}
