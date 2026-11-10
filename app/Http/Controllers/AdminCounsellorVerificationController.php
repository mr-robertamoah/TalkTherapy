<?php

namespace App\Http\Controllers;

use App\Actions\CounsellorVerification\RevokeCounsellorVerificationAction;
use App\Enums\RequestTypeEnum;
use App\Http\Resources\AdminCounsellorVerificationRenewalResource;
use App\Http\Resources\CounsellorVerificationEventResource;
use App\Models\Counsellor;
use App\Support\AdminSearch;
use App\Support\CounsellorVerification;
use Illuminate\Http\Request;
use Throwable;

// SCRUM-31: the admin side of counsellor verification renewals -- who is due, ended or revoked, each
// counsellor's history, and revoking. Behind the `admin` middleware (routes/api.php); revoking checks
// again (EnsureCanRevokeCounsellorVerificationAction).
class AdminCounsellorVerificationController extends Controller
{
    private const PER_PAGE = 20;

    public const FILTERS = ['due', 'ended'];

    // ?filter=due (the default): ending within the renewal window, soonest first -- asked to
    // re-verify or not (the overview's count is the ones not asked yet). ?filter=ended: ended or
    // revoked, most recent first.
    public function renewals(Request $request)
    {
        $ended = $request->query('filter') === 'ended';
        $search = AdminSearch::term($request);

        $counsellors = Counsellor::query()
            ->when(
                $ended,
                fn ($query) => $query->whereVerificationNotCurrent()->orderByDesc('verification_revoked_at')->orderByDesc('verification_expires_at'),
                fn ($query) => $query->whereVerificationEndingWithin(CounsellorVerification::renewalWindowDays())->orderBy('verification_expires_at'),
            )
            // SCRUM-390
            ->when($search, fn ($query, $term) => $query->where(fn ($query) => $query
                ->whereContains('counsellors.name', $term)
                ->orWhereHas('user', fn ($query) => AdminSearch::person($query, $term))))
            ->withExists(['sentRequests as pending_verification_request_exists' => fn ($query) => $query
                ->where('type', RequestTypeEnum::counsellor->value)->wherePending()])
            ->with(['user', 'avatarFile'])
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return AdminCounsellorVerificationRenewalResource::collection($counsellors);
    }

    public function events(Request $request, int $counsellorId)
    {
        $counsellor = Counsellor::withTrashed()->findOrFail($counsellorId);

        // Not one's own (who answered or revoked theirs is for the other administrators).
        abort_if((int) $counsellor->user_id === $request->user()->id, 404);

        return CounsellorVerificationEventResource::collection(
            $counsellor->verificationEvents()->with('actor')->latest('id')->paginate(self::PER_PAGE)
        );
    }

    public function revoke(Request $request, int $counsellorId)
    {
        try {
            $counsellor = RevokeCounsellorVerificationAction::new()->execute(
                $request->user(),
                Counsellor::query()->findOrFail($counsellorId),
                $request->input('reason'),
            );

            return $this->successResponse($request, [
                'status' => true,
                'verification' => CounsellorVerification::projection($counsellor, $request->user()),
            ]);
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }
}
