<?php

namespace App\Http\Middleware;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequestsV2 extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $userResource = $request->user()
            ? new UserResource($request->user())
            : null;

        if ($userResource) $userResource->withoutWrapping();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $userResource,
            ],
            // SCRUM-153 (TT-7.2a): shared dynamically (not a hardcoded frontend mirror, unlike
            // useEnums.js's other enum lists) so config('currencies.supported') stays the single
            // source of truth -- a currency added/removed via SUPPORTED_CURRENCIES reaches every
            // currency picker without a frontend code change.
            'supportedCurrencies' => config('currencies.supported'),
        ];
    }
}
