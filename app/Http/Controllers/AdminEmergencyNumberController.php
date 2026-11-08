<?php

namespace App\Http\Controllers;

use App\Actions\Help\DeleteEmergencyNumberAction;
use App\Actions\Help\EnsureCanManageEmergencyNumbersAction;
use App\Actions\Help\SaveEmergencyNumberAction;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\EmergencyNumber;
use App\Support\Countries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Throwable;

// SCRUM-33: the emergency numbers the "I need help now" dialog shows. Every administrator can see
// them; only a super administrator adds, corrects or removes one (SaveEmergencyNumberAction).
class AdminEmergencyNumberController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if ($user?->isNotAdmin() ?? true) {
            return Redirect::route('home')->with('message', EnsureUserIsAdmin::MESSAGE);
        }

        return Inertia::render('Admin/EmergencyNumbers', [
            'numbers' => EmergencyNumber::query()->orderBy('country_code')->orderBy('position')->orderBy('id')->get()
                ->map(fn (EmergencyNumber $number) => [
                    'id' => $number->id,
                    'countryCode' => $number->country_code,
                    'country' => $number->countryName(),
                    'label' => $number->label,
                    'number' => $number->number,
                    'notes' => $number->notes,
                    'sourceUrl' => $number->source_url,
                    'verifiedBy' => $number->verified_by,
                    'verifiedAt' => $number->verified_at->toDateString(),
                    'position' => $number->position,
                    'reviewDue' => $number->isReviewDue(),
                ])->values(),
            'canManage' => $user->isSuperAdmin(),
            'reviewMonths' => (int) config('help.number_review_months'),
        ]);
    }

    // Who may, first; then validated outside the try, so a field error comes back as a field error.
    public function store(Request $request)
    {
        try {
            EnsureCanManageEmergencyNumbersAction::new()->execute($request->user());
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }

        $values = $this->validated($request);

        try {
            SaveEmergencyNumberAction::new()->execute($request->user(), $values);

            return Redirect::back()->with('message', 'Emergency number added.');
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    public function update(Request $request, int $number)
    {
        try {
            EnsureCanManageEmergencyNumbersAction::new()->execute($request->user());
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }

        $values = $this->validated($request);

        try {
            SaveEmergencyNumberAction::new()->execute($request->user(), $values, EmergencyNumber::query()->findOrFail($number));

            return Redirect::back()->with('message', 'Emergency number saved.');
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    public function destroy(Request $request, int $number)
    {
        try {
            DeleteEmergencyNumberAction::new()->execute($request->user(), EmergencyNumber::query()->find($number));

            return Redirect::back()->with('message', 'Emergency number removed.');
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    private function validated(Request $request): array
    {
        $values = $request->validate([
            'countryCode' => ['required', 'string', Rule::in(Countries::codes())],
            'label' => ['required', 'string', 'max:100'],
            // Digits, spaces and + ( ) - only: what a phone can dial.
            'number' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9][0-9 ()-]{1,28}$/'],
            'notes' => ['nullable', 'string', 'max:200'],
            'sourceUrl' => ['required', 'url:https', 'max:500'],
            'verifiedBy' => ['required', 'string', 'max:120'],
            'verifiedAt' => ['required', 'date', 'before_or_equal:today'],
            'position' => ['nullable', 'integer', 'min:0', 'max:99'],
        ]);

        return [
            'country_code' => $values['countryCode'],
            'label' => trim($values['label']),
            'number' => trim($values['number']),
            'notes' => isset($values['notes']) ? trim($values['notes']) : null,
            'source_url' => $values['sourceUrl'],
            'verified_by' => trim($values['verifiedBy']),
            'verified_at' => $values['verifiedAt'],
            'position' => $values['position'] ?? 0,
        ];
    }
}
