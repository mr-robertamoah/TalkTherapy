<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

// SCRUM-360: see the admin_content_accesses migration. Written only by RecordAdminContentReadAction.
class AdminContentAccess extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    // A private therapy's or group's page: its background story, members and sessions.
    public const THERAPY = 'therapy';

    // SCRUM-367: a session's details (name, about, topics), fetched after a live update.
    public const SESSION = 'session';

    public const SESSION_MESSAGES = 'session_messages';

    public const TOPIC_MESSAGES = 'topic_messages';

    public const MESSAGE = 'message';

    public const MESSAGE_REPLIES = 'message_replies';

    public const MESSAGE_ATTACHMENT = 'message_attachment';

    public const DISCUSSION_MESSAGES = 'discussion_messages';

    public const LICENSE_DOCUMENT = 'license_document';

    public const IDENTITY_DOCUMENT = 'identity_document';

    // SCRUM-33 (TT-4.4b): who asked for urgent help, revealed to an administrator handling it.
    public const HELP_REQUEST_IDENTITY = 'help_request_identity';

    // SCRUM-32: who a safety flag is about, and the counsellor's reason -- to the administrator handling it.
    public const SAFETY_FLAG_IDENTITY = 'safety_flag_identity';

    public const SAFETY_FLAG_REASON = 'safety_flag_reason';

    public const RESOURCES = [
        self::THERAPY,
        self::SESSION,
        self::SESSION_MESSAGES,
        self::TOPIC_MESSAGES,
        self::MESSAGE,
        self::MESSAGE_REPLIES,
        self::MESSAGE_ATTACHMENT,
        self::DISCUSSION_MESSAGES,
        self::LICENSE_DOCUMENT,
        self::IDENTITY_DOCUMENT,
        self::HELP_REQUEST_IDENTITY,
        self::SAFETY_FLAG_IDENTITY,
        self::SAFETY_FLAG_REASON,
    ];

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'datetime'];

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_user_id')->withTrashed();
    }

    public function subject()
    {
        return $this->morphTo();
    }

    public function file()
    {
        return $this->belongsTo(File::class);
    }
}
