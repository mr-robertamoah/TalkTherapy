<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Actions\File\DeleteAttachedFilesAction;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Contact;
use App\Models\Counsellor;
use App\Models\Request;
use App\Models\SafeguardingException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// SCRUM-367 (user decision 2026-11-01): a closed account's personal details are erased once its
// retention period has passed. What goes:
//  - on the account: names, username, email, date of birth, gender, country, preferences, photo
//    (and the password, replaced with a random one);
//  - its counsellor profile, if any: name, email, phone, about, photo and cover (the profile row
//    itself is purged after its own grace period, SCRUM-134);
//  - its age and date-of-birth requests: the dates of birth in them, and any identity document
//    still attached (a pending request's scan is otherwise never swept);
//  - its notifications, password-reset token and API tokens, the name and email on contact
//    messages it sent, and the link from visit records to the account;
//  - the reasons on its safeguarding exceptions and on counsellors' requests for one (SCRUM-381).
// What stays: the account row itself (soft-deleted), so what the person took part in still holds
// together -- their messages stay in other people's conversations as "Deleted user", and payment,
// complaint, suspension and audit records keep pointing at it. Guardianship and organisation
// membership rows hold only ids.
class AnonymiseClosedAccountAction extends Action
{
    private const DOB_REQUEST_KEYS = ['attestedDob', 'newDob', 'priorDob'];

    // Returns false when a stored file couldn't be deleted: nothing is marked, and the next run
    // retries.
    public function execute(User $user): bool
    {
        $counsellor = Counsellor::withTrashed()->where('user_id', $user->id)->first();
        $dobRequests = $this->dobRequests($user);

        $owners = [[$user, 'avatarFile'], ...$dobRequests->map(fn (Request $request) => [$request, 'files'])->all()];
        if ($counsellor) {
            array_push($owners, [$counsellor, 'avatarFile'], [$counsellor, 'coverFile']);
        }

        foreach ($owners as [$owner, $relation]) {
            DeleteAttachedFilesAction::new()->execute($owner, 'closed account anonymised', $relation);
        }

        if (collect($owners)->contains(fn (array $owner) => $owner[0]->{$owner[1]}()->exists())) {
            return false;
        }

        DB::transaction(function () use ($user, $counsellor, $dobRequests) {
            $dobRequests->each(function (Request $request) {
                $request->data = collect($request->data ?? [])->except(self::DOB_REQUEST_KEYS)->all();

                if ($request->status === RequestStatusEnum::pending->value) {
                    $request->status = RequestStatusEnum::inconsequencial->value;
                    $request->responded_at = now();
                }

                $request->saveQuietly();
            });

            $counsellor?->forceFill(['name' => null, 'email' => null, 'phone' => null, 'about' => null])->saveQuietly();

            // SCRUM-381: their safeguarding exceptions stay (the audit trail), without the
            // administrators' and counsellors' free-text reasons about them.
            SafeguardingException::query()->where('ward_id', $user->id)->update(['reason' => null, 'end_reason' => null]);
            Request::query()
                ->safeguardingException($user)
                ->lockForUpdate()
                ->get()
                ->each(function (Request $request) {
                    $request->data = collect($request->data ?? [])->except('reason')->all();

                    // saveQuietly() skips the model hook that would stamp responded_at.
                    if ($request->status === RequestStatusEnum::pending->value) {
                        $request->status = RequestStatusEnum::inconsequencial->value;
                        $request->responded_at = now();
                    }

                    $request->saveQuietly();
                });

            DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->id)->delete();
            if ($user->email) {
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            }
            $user->tokens()->delete();
            Contact::query()->where('addedby_type', $user->getMorphClass())->where('addedby_id', $user->id)
                ->update(['name' => null, 'email' => null]);
            DB::table('visitors')->where('user_id', $user->id)->update(['user_id' => null]);

            $user->forceFill([
                ...array_fill_keys(User::ANONYMISED_ATTRIBUTES, null),
                'password' => Str::random(64),
                'anonymised_at' => now(),
            ])->saveQuietly();
        });

        return true;
    }

    private function dobRequests(User $user)
    {
        return Request::query()
            ->whereIn('type', [RequestTypeEnum::ageVerification->value, RequestTypeEnum::dobChange->value])
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('from_type', $user->getMorphClass())->where('from_id', $user->id))
                ->orWhere(fn ($q) => $q->where('for_type', $user->getMorphClass())->where('for_id', $user->id)))
            ->get();
    }
}
