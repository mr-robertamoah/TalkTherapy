<?php

namespace App\Actions\SafeguardingException;

use App\Actions\Action;
use App\Actions\Guardianship\GetWardOpenTherapiesAction;
use App\Exceptions\SafeguardingExceptionRefusedException;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Support\Collection;

// SCRUM-381 (the user's decision): only a super administrator grants, renews or ends a safeguarding
// exception -- and not one who counsels the minor (in an open therapy or group of theirs).
class EnsureCanManageSafeguardingExceptionAction extends Action
{
    public const NOT_SUPER_ADMIN = 'Only the super administrator can grant, renew or end a safeguarding exception.';

    public const COUNSELS_WARD = 'You counsel this person, so you can\'t decide a safeguarding exception for them.';

    // SCRUM-384: null unless remembering() was called -- then counsels() is worked out once per
    // administrator and minor on this instance.
    private ?array $counselsMemo = null;

    public function execute(?User $admin, ?User $ward): void
    {
        if (! $admin?->isSuperAdmin()) {
            throw new SafeguardingExceptionRefusedException(self::NOT_SUPER_ADMIN, 403);
        }

        if (! $ward) {
            throw new SafeguardingExceptionRefusedException('Account not found.', 404);
        }

        if ($this->counsels($admin, $ward)) {
            throw new SafeguardingExceptionRefusedException(self::COUNSELS_WARD, 403);
        }
    }

    // Whether $admin may decide for $ward: the same rule as execute(), as a yes/no (for the page's
    // buttons and the requests list).
    public function allows(?User $admin, ?User $ward): bool
    {
        return (bool) $admin?->isSuperAdmin() && $ward && ! $this->counsels($admin, $ward);
    }

    // SCRUM-384: closing (rejecting) a counsellor's request -- the same rule, except that a request
    // whose minor's row is gone for good (nobody left to counsel) can still be closed; otherwise it
    // would wait in the "no longer needed" list forever.
    public function ensureCanClose(?User $admin, ?User $ward): void
    {
        if (! $admin?->isSuperAdmin()) {
            throw new SafeguardingExceptionRefusedException(self::NOT_SUPER_ADMIN, 403);
        }

        if ($ward && $this->counsels($admin, $ward)) {
            throw new SafeguardingExceptionRefusedException(self::COUNSELS_WARD, 403);
        }
    }

    public function allowsClosing(?User $admin, ?User $ward): bool
    {
        return (bool) $admin?->isSuperAdmin() && (! $ward || ! $this->counsels($admin, $ward));
    }

    // SCRUM-382 review (SCRUM-362's rule for reports): an administrator who counsels the minor, or
    // who sent $request (as a counsellor), is part of the case -- the admin page and the requests
    // list show them only that it exists, never who the minor is, the reasons or the therapy.
    public function involves(User $admin, ?User $ward, ?Request $request = null): bool
    {
        return ($request && $this->sentBy($request, $admin)) || ($ward && $this->counsels($admin, $ward));
    }

    public function sentBy(Request $request, User $user): bool
    {
        return $request->from_type === Counsellor::class
            && $user->counsellor
            && (int) $request->from_id === $user->counsellor->id;
    }

    // Who is told about a request or a reminder: the super administrators whose account isn't
    // suspended -- and, given $ward, not one who counsels them (they can't decide it).
    /** @return Collection<int, User> */
    public function deciders(?User $ward = null): Collection
    {
        return User::query()->whereSuperAdmin()->whereNotAccountSuspended()->get()
            ->reject(fn (User $admin) => $ward && $this->counsels($admin, $ward))
            ->values();
    }

    // For one render of a list (RequestResource shares one instance per HTTP request and viewer), so
    // rows about the same minor don't each re-read their therapies. Never for a decision that writes:
    // a fresh instance always re-reads.
    public function remembering(): static
    {
        $this->counselsMemo ??= [];

        return $this;
    }

    public function counsels(User $admin, User $ward): bool
    {
        if (is_null($this->counselsMemo)) {
            return $this->readCounsels($admin, $ward);
        }

        return $this->counselsMemo["{$admin->id}:{$ward->id}"] ??= $this->readCounsels($admin, $ward);
    }

    private function readCounsels(User $admin, User $ward): bool
    {
        $counsellor = $admin->counsellor;

        if (! $counsellor) {
            return false;
        }

        $open = GetWardOpenTherapiesAction::new()->execute($ward);

        return $open['therapies']->contains(fn (Therapy $therapy) => $therapy->counsellor_id === $counsellor->id)
            || $open['groups']->contains(fn (GroupTherapy $group) => $group->isCounsellor($counsellor));
    }
}
