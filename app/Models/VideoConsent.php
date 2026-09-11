<?php

namespace App\Models;

use App\Enums\VideoConsentRevocationReasonEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// TT-3.1e-a/SCRUM-280: one grant-cycle per row, never deleted -- see the migration's own comment
// for why this deliberately does NOT follow VideoSessionParticipant's append-only-per-cycle
// precedent. `consentable` is Therapy (a PER_THERAPY grant, covers all of that therapy's
// sessions) or Session (a PER_SESSION grant, that one session only) -- see
// Therapy::video_consent_mode for which mode is currently selected, though enforcement
// (EnsureVideoIsAvailableForSessionAction, TT-3.1e-d) checks BOTH scopes regardless of the
// current mode, which is what makes a mode switch prospective-only "for free".
class VideoConsent extends Model
{
    use HasFactory;

    protected $fillable = [
        'ward_id', 'guardian_id', 'consentable_type', 'consentable_id',
        'granted_at', 'revoked_at', 'revoked_by_guardian_id', 'revocation_reason',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function ward()
    {
        return $this->belongsTo(User::class, 'ward_id');
    }

    public function guardian()
    {
        return $this->belongsTo(User::class, 'guardian_id');
    }

    public function revokedByGuardian()
    {
        return $this->belongsTo(User::class, 'revoked_by_guardian_id');
    }

    public function consentable()
    {
        return $this->morphTo();
    }

    public function isValid(): bool
    {
        return is_null($this->revoked_at);
    }

    // TT-3.1e-c: a Guardianship-deletion cascade revokes on the guardian's behalf, with no
    // acting guardian to attribute it to (revoked_by_guardian_id stays null either way this
    // happened) -- revocation_reason is what actually distinguishes the two cases for an audit
    // UI, not the presence/absence of revoked_by_guardian_id alone.
    public function wasRevokedByGuardianshipRemoval(): bool
    {
        return $this->revocation_reason === VideoConsentRevocationReasonEnum::guardianship_removed->value;
    }
}
