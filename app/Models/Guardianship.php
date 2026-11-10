<?php

namespace App\Models;

use App\Enums\SuspensionScopeEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Guardianship extends Model
{
    use HasFactory;

    protected $table = 'guardianship';

    protected $fillable = ['guardian_id', 'ward_id', 'ward_was_minor_at_creation'];

    protected $casts = [
        'ward_was_minor_at_creation' => 'boolean',
    ];

    public function guardian()
    {
        return $this->belongsTo(User::class, 'guardian_id');
    }

    public function ward()
    {
        return $this->belongsTo(User::class, 'ward_id');
    }

    // SCRUM-288: a guardianship that counts -- its guardian's account exists (not deleted), is adult
    // (a date of birth at least 18 years ago; none counts as not adult, as for User::isAdult()) and
    // isn't under an account-scope suspension. $lock makes every part a shared locking read: MySQL
    // doesn't lock (or read the latest version of) a subquery's rows unless the subquery says so.
    public function scopeActive(Builder $query, array $exceptGuardianIds = [], bool $lock = false): Builder
    {
        $locking = fn ($q) => $lock ? $q->sharedLock() : $q;

        return $locking($query)
            ->when($exceptGuardianIds, fn ($q) => $q->whereNotIn('guardianship.guardian_id', $exceptGuardianIds))
            ->whereHas('guardian', fn ($q) => $locking($q)
                ->whereNotNull('dob')
                ->where('dob', '<=', now()->subYears(18)->endOfDay()->toDateTimeString())
                ->whereDoesntHave('suspensions', fn ($q) => $locking($q)->active()->covering(SuspensionScopeEnum::account)));
    }

    // SCRUM-288: the one lock order for touching a guardian's wards -- the guardian's own row first,
    // then the wards' rows in id order (deleting, suspending, lifting and expiry all take it), so two
    // of them at once can't deadlock. Call inside a transaction. Soft-deleted wards are left out.
    public static function lockedWardsOf(User $guardian): Collection
    {
        User::query()->withTrashed()->whereKey($guardian->id)->lockForUpdate()->first(['id']);

        return User::query()
            ->whereIn('id', self::query()->where('guardian_id', $guardian->id)->sharedLock()->select('ward_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }
}
