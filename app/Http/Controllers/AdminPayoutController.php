<?php

namespace App\Http\Controllers;

use App\DTOs\GetCounsellorPayoutOverviewForAdminDTO;
use App\DTOs\GetModelsForAdminDTO;
use App\DTOs\SettingDTO;
use App\Enums\SettingsEnum;
use App\Http\Requests\UpdateMinimumPayoutAmountsRequest;
use App\Http\Requests\UpdatePlatformFeeRequest;
use App\Models\Counsellor;
use App\Services\PayoutService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Throwable;

class AdminPayoutController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (is_null($user) || $user->isNotAdmin()) {
            return Redirect::route('home')->with('message', 'You are not authorized to visit this page.');
        }

        $payouts = PayoutService::new()->getPayoutsForAdmin(
            GetModelsForAdminDTO::new()->fromArray(['user' => $user])
        );

        return Inertia::render('Admin/Payouts', [
            'settings' => SettingsService::new()->getSettingsForAdmin(),
            'payoutHistory' => $this->paginatedResource($payouts),
        ]);
    }

    public function payouts(Request $request)
    {
        try {
            return PayoutService::new()->getPayoutsForAdmin(
                GetModelsForAdminDTO::new()->fromArray(['user' => $request->user()])
            );
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json(['message' => $message], $status);
        }
    }

    public function counsellorOverview(Request $request, $counsellorId)
    {
        try {
            $overview = PayoutService::new()->getPayoutOverviewForAdmin(
                GetCounsellorPayoutOverviewForAdminDTO::new()->fromArray([
                    'user' => $request->user(),
                    'counsellor' => Counsellor::find($counsellorId),
                ])
            );

            return response()->json($overview);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json(['message' => $message], $status);
        }
    }

    public function updatePlatformFee(UpdatePlatformFeeRequest $request)
    {
        try {
            SettingsService::new()->update(SettingDTO::new()->fromArray([
                'user' => $request->user(),
                'key' => SettingsEnum::platformFeePercentage,
                'value' => (string) $request->validated('percentage'),
            ]));

            return Redirect::back();
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return Redirect::back()->withErrors(['alert' => $message]);
        }
    }

    // Always receives the full set of supported currencies (enforced by
    // UpdateMinimumPayoutAmountsRequest) -- SettingsEnum::minimumPayoutAmount is one JSON blob per
    // key, so a partial write would silently erase any currency left out of the request.
    public function updateMinimumPayoutAmounts(UpdateMinimumPayoutAmountsRequest $request)
    {
        try {
            $amounts = collect($request->validated('amounts'))
                ->mapWithKeys(fn (array $row) => [strtoupper($row['currency']) => (int) round($row['amount'] * 100)])
                ->toArray();

            SettingsService::new()->update(SettingDTO::new()->fromArray([
                'user' => $request->user(),
                'key' => SettingsEnum::minimumPayoutAmount,
                'value' => json_encode($amounts),
            ]));

            return Redirect::back();
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return Redirect::back()->withErrors(['alert' => $message]);
        }
    }

    private function paginatedResource(AnonymousResourceCollection $resource): array
    {
        return $resource->response()->getData(true);
    }
}
