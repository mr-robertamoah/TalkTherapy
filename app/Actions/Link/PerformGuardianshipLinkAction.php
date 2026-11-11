<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\Actions\Guardianship\HandleGuardianRestoredAction;
use App\Actions\User\EnsureUserCanBeGuardianAction;
use App\DTOs\CreateLinkDTO;
use App\DTOs\CreateRequestDTO;
use App\Enums\LinkStateEnum;
use App\Exceptions\LinkException;
use App\Models\Link;
use App\Models\User;
use App\Support\LinkConfirmation;
use App\Support\NarrowUserProjection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

// SCRUM-418: opening a guardianship link only shows who is asking (preview()); confirming makes them the
// ward's guardian (execute()). Both run the same checks (ensureCanBecomeGuardian()); execute() again under
// the link's and the ward's locks.
class PerformGuardianshipLinkAction extends Action
{
    public const NOT_ELIGIBLE = 'You cannot become a guardian because you are not an adult, have not set your date of birth, or have not set or verified your email.';

    public const ALREADY_GUARDIAN = 'You are already a guardian of this user.';

    // Who is asking -- their name, username and photo, never their age, date of birth, email or
    // whether they are a minor (SCRUM-418).
    public function preview(CreateLinkDTO $createLinkDTO): array
    {
        $ward = $this->wardOf($createLinkDTO->link);

        $this->ensureCanBecomeGuardian($createLinkDTO->user, $ward);

        $person = NarrowUserProjection::of($ward);

        return [
            'name' => $person['fullName'],
            'username' => $person['username'],
            'avatarUrl' => $ward->avatar?->url,
        ];
    }

    public function execute(CreateLinkDTO $createLinkDTO): LinkConfirmation
    {
        $ward = DB::transaction(function () use ($createLinkDTO) {
            // A general link (to=null) can be used by any user, so the guardianship(guardian_id,
            // ward_id) unique index below -- keyed on the ACTING user -- doesn't stop two
            // different users both racing this same link: without a lock on the link itself,
            // both could read state=active and both attach as guardian before either commits
            // markUsed() (SCRUM-101). Locking the link row makes every racer for this link
            // serialize here, so only the first ever gets past the active-state check.
            $link = Link::query()->lockForUpdate()->findOrFail($createLinkDTO->link->id);

            if ($link->state !== LinkStateEnum::active->value) {
                throw new LinkException('This link is no longer active.', 422);
            }

            $ward = $this->wardOf($link);

            // SCRUM-288: under the ward's lock, with locking reads (HandleGuardianRestoredAction::around()),
            // so a paused ward is told once that their therapies have started again.
            // The existence check is a courtesy for the common (sequential) case -- the
            // guardianship(guardian_id, ward_id) unique index (SCRUM-99) is what actually prevents
            // a duplicate row from the SAME user reusing this link twice. Without the catch, that
            // race would surface as an uncaught UniqueConstraintViolationException instead of the
            // same graceful "already a guardian" error the sequential case gets.
            try {
                HandleGuardianRestoredAction::new()->around($ward, HandleGuardianRestoredAction::GUARDIANSHIP_CREATED, function () use ($createLinkDTO, $ward) {
                    $this->ensureCanBecomeGuardian($createLinkDTO->user, $ward, lock: true);

                    // TT-4.10a/SCRUM-290: the same snapshot RespondToGuardianshipRequestAction writes.
                    $createLinkDTO->user->wards()->create([
                        'ward_id' => $ward->id,
                        'ward_was_minor_at_creation' => ! $ward->isAdult(),
                    ]);
                });
            } catch (UniqueConstraintViolationException) {
                throw new LinkException(self::ALREADY_GUARDIAN, 422);
            }

            // Marking it used (SCRUM-101; used_at, SCRUM-415) keeps this link from being replayed by
            // whoever still holds the URL -- safe from the race described above because it
            // happens inside the same lockForUpdate transaction as the active-state check.
            $link->markUsed();

            return $ward;
        });

        return new LinkConfirmation("You are now {$ward->name}'s guardian.", route('profile.show'));
    }

    // SCRUM-288 (security review): the same eligibility as accepting a guardianship request
    // (RespondToGuardianshipRequestAction) -- adult, with a verified email -- and not already theirs.
    // $lock: a shared lock on the existing row, under the ward's lock.
    private function ensureCanBecomeGuardian(User $user, User $ward, bool $lock = false): void
    {
        EnsureUserCanBeGuardianAction::new()->execute(CreateRequestDTO::new()->fromArray(['to' => $user]), self::NOT_ELIGIBLE);

        if ($user->wards()->where('ward_id', $ward->id)->when($lock, fn ($query) => $query->sharedLock())->first(['id'])) {
            throw new LinkException(self::ALREADY_GUARDIAN, 422);
        }
    }

    private function wardOf(Link $link): User
    {
        if (! $link->for instanceof User) {
            throw LinkException::inactive();
        }

        return $link->for;
    }
}
