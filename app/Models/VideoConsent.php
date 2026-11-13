<?php

namespace App\Models;

use App\Enums\VideoConsentLevelEnum;
use App\Enums\VideoConsentRevocationReasonEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

// TT-3.1e-a/SCRUM-280: one grant-cycle per row, never deleted -- see the migration's own comment
// for why this deliberately does NOT follow VideoSessionParticipant's append-only-per-cycle
// precedent. `consentable` is Therapy (a PER_THERAPY grant, covers all of that therapy's
// sessions), GroupTherapy (SCRUM-313: a group-wide grant for one minor member) or Session (a PER_SESSION grant, that
// one session only) -- see
// Therapy::video_consent_mode for which mode is currently selected, though enforcement
// (EnsureVideoIsAvailableForSessionAction, TT-3.1e-d) checks BOTH scopes regardless of the
// current mode, which is what makes a mode switch prospective-only "for free".
class VideoConsent extends Model
{
    use HasFactory;

    protected $fillable = [
        'ward_id', 'guardian_id', 'consentable_type', 'consentable_id', 'level',
        'granted_at', 'revoked_at', 'revoked_by_guardian_id', 'revocation_reason',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    // SCRUM-313 (security review): a grant says what it allows. The column's SPEAK default is only for the grants made
    // before levels existed; a new grant that forgot its level must fail, not quietly allow the most.
    protected static function booted(): void
    {
        static::creating(function (VideoConsent $consent) {
            if (! in_array($consent->level, VideoConsentLevelEnum::values(), true)) {
                throw new LogicException('A video consent grant needs its level (WATCH or SPEAK).');
            }
        });
    }

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

    // TT-3.1e-e/SCRUM-284 (reviewer suggestion): the exact-scope, currently-valid lookup. SCRUM-313: for one ward --
    // a group's minors each have their own guardians' grants on the same group or session, so a scope alone no longer
    // identifies a grant.
    public function scopeWhereValidFor($query, int $wardId, string $consentableType, int $consentableId)
    {
        return $query
            ->where('ward_id', $wardId)
            ->where('consentable_type', $consentableType)
            ->where('consentable_id', $consentableId)
            ->whereNull('revoked_at');
    }

    // SCRUM-313: grants for a group -- the whole group's, or one of its sessions' (soft-deleted ones too).
    public function scopeWhereForGroup($query, GroupTherapy $group)
    {
        return $query->where(fn ($query) => $query
            ->where(fn ($q) => $q->where('consentable_type', GroupTherapy::class)->where('consentable_id', $group->id))
            ->orWhere(fn ($q) => $q->where('consentable_type', Session::class)
                ->whereIn('consentable_id', Session::withTrashed()->where('for_type', GroupTherapy::class)->where('for_id', $group->id)->select('id'))));
    }

    // SCRUM-313: from a guardian the ward still has -- the only grants the join gate counts.
    public function scopeFromActiveGuardianOf($query, User $ward)
    {
        return $query->whereIn('guardian_id', $ward->activeGuardianships()->select('guardianship.guardian_id'));
    }

    public function allowsSpeaking(): bool
    {
        return $this->level === VideoConsentLevelEnum::speak->value;
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
