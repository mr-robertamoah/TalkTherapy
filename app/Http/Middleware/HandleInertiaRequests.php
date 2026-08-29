<?php

namespace App\Http\Middleware;

use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): string|null
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
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
