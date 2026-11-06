<?php

namespace App\Http\Controllers;

use App\Actions\Request\PreloadRequestListAction;
use App\Enums\RequestStatusEnum;
use App\Http\Resources\RequestResource;
use App\Models\Request as ModelsRequest;
use App\Models\User;
use App\Support\AdminRequestQueue;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// SCRUM-387: "Requests to resolve" -- one queue of everything waiting on an administrator, of the
// fixed list of types in App\Support\AdminRequestQueue (safeguarding exceptions for super
// administrators only). Rows are the personal Requests list's own (PreloadRequestListAction and
// RequestResource, so their masking and `isRespondent` apply here too); answering goes through
// requests.respond. The `admin` route middleware refuses a non-admin.
class AdminRequestController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request)
    {
        $viewer = $request->user();
        $types = AdminRequestQueue::typesFor($viewer);

        $filters = $request->validate([
            'type' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in([...RequestStatusEnum::values(), 'all'])],
        ]);
        // A type the viewer can't filter by (not a queue type, or one they may not see) is ignored,
        // so the list and the filter shown always agree.
        $type = in_array($filters['type'] ?? null, $types, true) ? $filters['type'] : null;
        $status = $filters['status'] ?? RequestStatusEnum::pending->value;

        $requests = ModelsRequest::query()
            ->awaitingAdmin($viewer, $type ? [$type] : null)
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        PreloadRequestListAction::new()->execute($requests);
        // Before the resource collection, which swaps the paginator's models for resources.
        $rows = $this->rows($viewer, $requests->getCollection());

        return Inertia::render('Admin/Requests', [
            'requests' => RequestResource::collection($requests)->response()->getData(true),
            'rows' => $rows,
            'filters' => ['type' => $type, 'status' => $status],
            'types' => AdminRequestQueue::optionsFor($viewer),
            'statuses' => RequestStatusEnum::values(),
        ]);
    }

    // What the queue shows beside each row, by request id: when it was answered, and whether it is
    // the viewer's own age verification (which another administrator must review). Who answered it
    // and whether the viewer may are on the row itself (RequestResource).
    private function rows(User $viewer, $requests): array
    {
        return $requests->mapWithKeys(fn (ModelsRequest $row) => [$row->id => [
            'ownAgeVerification' => $row->isOwnAgeVerificationOf($viewer),
            'respondedAt' => $row->responded_at?->toIso8601String(),
        ]])->all();
    }
}
