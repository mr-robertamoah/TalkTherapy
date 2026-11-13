<?php

namespace App\Http\Middleware;

use App\Http\Resources\UserResource;
use App\Support\AdminNavigation;
use App\Support\InertiaHistory;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
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

    // SCRUM-406: history encrypted when the browser can do it (InertiaHistory), and a signed-in person's pages are
    // never stored by the browser cache -- a cached full page carries its props in plain text, and the back button
    // after signing out could otherwise show it from there.
    public function handle(Request $request, Closure $next)
    {
        Inertia::encryptHistory(InertiaHistory::encrypts($request));

        $response = parent::handle($request, $next);

        if ($request->user() && InertiaHistory::isPage($response)) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

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

        if ($userResource) {
            $userResource->withoutWrapping();
        }

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
            // TT-3.1c/SCRUM-276: which VideoProviderInterface adapter is active, so the frontend
            // knows which provider SDK to dynamically import on join -- a deployment-level value,
            // not per-therapy/session, so it's shared globally like supportedCurrencies above
            // rather than threaded through as a page prop.
            'videoProvider' => config('video.provider'),
            // SCRUM-32: features switched on or off by the deployment (the safety flag waits for a legal read).
            'features' => ['safetyFlags' => (bool) config('safety.enabled')],
            // SCRUM-371: a failed page visit's message (Controller::failureResponse), shown by the layout.
            // Pulled: shown on the first page that renders, then gone (FailureResponse puts it).
            'failureAlert' => fn () => $request->hasSession() ? $request->session()->pull('failureAlert') : null,
            // SCRUM-41: the email-verification outcomes the layout's notice and toast show, from any page (a bare shared
            // `status` would collide with the pages that pass their own).
            'verificationStatus' => fn () => $request->hasSession() && in_array($request->session()->get('status'), ['verification-link-sent', 'verification-throttled', 'email-verified'], true)
                ? $request->session()->get('status')
                : null,
            ...$this->adminArea($request),
        ];
    }

    // SCRUM-387: the admin area's sidebar, on its pages only (null elsewhere -- always present, so
    // a value shared for one request can't linger into the next where the app is long-lived). Its
    // badges are not a prop: AdminLayout.vue fetches them from admin.badges once a page is up, so a
    // page never waits on the counts. Not a deferred prop either, on purpose (SCRUM-410): a deferred prop
    // loads through a partial reload, which runs the page's whole controller again.
    private function adminArea(Request $request): array
    {
        return [
            'adminNavigation' => fn () => $request->user() && $request->routeIs('administrator', 'administrator.*')
                ? AdminNavigation::for($request->user())
                : null,
        ];
    }
}
