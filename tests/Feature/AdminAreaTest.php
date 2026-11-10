<?php

use App\Enums\AdministratorTypeEnum;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\Administrator;
use App\Models\User;
use App\Support\AdminNavigation;
use Illuminate\Support\Facades\DB;

// SCRUM-387: the admin area's shell -- the `admin` middleware on every /administrator page, the
// role-filtered sidebar (App\Support\AdminNavigation) and how it reaches the pages. Helpers are
// file-local and aar-prefixed (--parallel).

function aarAdmin(): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::normal->value]))->create();
}

function aarSuperAdmin(): User
{
    return User::factory()->adult()->has(Administrator::factory()->state(['type' => AdministratorTypeEnum::super->value]))->create();
}

// Every admin GET page, by route name => its Inertia component (or null when it needs a record).
const AAR_PAGES = [
    'administrator' => 'Admin/Overview',
    'administrator.requests' => 'Admin/Requests',
    'administrator.users' => 'Admin/People/Users',
    'administrator.counsellors' => 'Admin/People/Counsellors',
    'administrator.counsellor_verification' => 'Admin/People/CounsellorVerification',
    'administrator.testimonials' => 'Admin/Content/Testimonials',
    'administrator.how_tos' => 'Admin/Content/HowTos',
    'administrator.payouts' => 'Admin/Payouts',
    'administrator.organization_billing' => 'Admin/OrganizationBilling',
    'administrator.refund_requests' => 'Admin/RefundRequests',
    'administrator.reports' => 'Admin/Reports',
    'administrator.suspensions' => 'Admin/Suspensions',
    'administrator.safeguarding_exceptions' => 'Admin/SafeguardingExceptions',
    'administrator.content-accesses' => 'Admin/ContentAccesses',
];

test('every admin page sends a signed-in non-admin home with the message, and a guest to log in', function () {
    $user = User::factory()->adult()->create();

    foreach (array_keys(AAR_PAGES) as $name) {
        $this->actingAs($user)->get(route($name))
            ->assertRedirect(route('home'))
            ->assertSessionHas('message', EnsureUserIsAdmin::MESSAGE);
    }

    $this->actingAs($user)->get(route('administrator.reports.show', ['report' => 1]))->assertRedirect(route('home'));

    auth()->logout();
    $this->get(route('administrator.requests'))->assertRedirect(route('login'));
});

test('every /administrator GET route carries the admin middleware', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('GET', $route->methods(), true) && str_starts_with($route->uri(), 'administrator'));

    expect($routes)->not->toBeEmpty();
    $routes->each(fn ($route) => expect(in_array('admin', $route->gatherMiddleware(), true))->toBeTrue($route->uri()));
});

test('an ordinary admin opens every page except the super-admin-only read log', function () {
    $admin = aarAdmin();

    foreach (AAR_PAGES as $name => $component) {
        $response = $this->actingAs($admin)->get(route($name));

        if ($name === 'administrator.content-accesses') {
            $response->assertRedirect(route('home'));

            continue;
        }

        $response->assertOk()->assertInertia(fn ($page) => $page->component($component)->has('adminNavigation'));
    }
});

test('a super admin opens every page, the read log included', function () {
    $super = aarSuperAdmin();

    foreach (AAR_PAGES as $name => $component) {
        $this->actingAs($super)->get(route($name))->assertOk()->assertInertia(fn ($page) => $page->component($component));
    }
});

test('the sidebar is grouped in order and the read log is for super admins only', function () {
    $admin = AdminNavigation::for(aarAdmin());
    $super = AdminNavigation::for(aarSuperAdmin());

    expect(array_column($admin, 'label'))->toBe(['Overview', 'People', 'Requests to resolve', 'Safety', 'Money', 'Content']);

    $keys = fn (array $groups) => collect($groups)->flatMap(fn ($group) => array_column($group['items'], 'key'))->all();
    expect($keys($admin))->toBe([
        'overview', 'users', 'counsellors', 'counsellor-verification', 'requests',
        'help-requests', 'reports', 'suspensions', 'safeguarding-exceptions', 'emergency-numbers',
        'payouts', 'refund-requests', 'organization-billing', 'testimonials', 'how-tos',
    ]);
    expect($keys($super))->toContain('read-log');
    expect(AdminNavigation::for(User::factory()->create()))->toBe([]);
    expect(AdminNavigation::for(null))->toBe([]);

    // Every item's route exists.
    collect($super)->flatMap(fn ($group) => $group['items'])->each(fn ($item) => expect(route($item['route']))->toBeString());
});

test('the sidebar is shared on admin pages only, and the badges never are', function () {
    $admin = aarAdmin();

    $this->actingAs($admin)->get(route('administrator.users'))
        ->assertInertia(fn ($page) => $page->has('adminNavigation', 6)->missing('adminBadges'));

    $this->actingAs($admin)->get(route('profile.show'))
        ->assertInertia(fn ($page) => $page->where('adminNavigation', null)->missing('adminBadges'));
});

test('the badges come from their own JSON route, for admins only', function () {
    $this->actingAs(aarAdmin())->getJson(route('admin.badges'))
        ->assertOk()
        ->assertJsonPath('badges.requests', 0)
        ->assertJsonStructure(['badges' => ['requests', 'counsellorVerifications', 'refunds', 'reports', 'suspensionAppeals', 'failedPayouts', 'organizationBilling']]);

    $this->actingAs(User::factory()->adult()->create())->getJson(route('admin.badges'))
        ->assertForbidden()
        ->assertJsonPath('message', EnsureUserIsAdmin::MESSAGE);

    auth()->logout();
    $this->getJson(route('admin.badges'))->assertUnauthorized();
});

test('the badge route runs only the counts, never a page\'s own queries', function () {
    $super = aarSuperAdmin();
    $this->actingAs($super)->getJson(route('admin.badges'))->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($super)->getJson(route('admin.badges'))->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // Cached: the viewer, the admin check and their own age verifications -- no paged list, no
    // paused minors.
    expect($queries->count())->toBeLessThanOrEqual(6)
        ->and($queries->filter(fn (string $sql) => str_contains($sql, 'guardianship') || str_contains($sql, 'offset'))->all())->toBe([]);
});

test('every admin route -- pages, writes and the /api/administrator JSON routes -- carries the admin middleware', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'administrator') || str_starts_with($route->uri(), 'api/administrator'));

    expect($routes->filter(fn ($route) => ! in_array('GET', $route->methods(), true)))->not->toBeEmpty();
    $routes->each(fn ($route) => expect(in_array('admin', $route->gatherMiddleware(), true))->toBeTrue($route->uri()));
});

test('a non-admin gets a JSON 403 from an admin JSON route, and is sent home from an admin form post', function () {
    $user = User::factory()->adult()->create();

    $this->actingAs($user)->getJson(route('admin.users'))
        ->assertForbidden()
        ->assertJsonPath('message', EnsureUserIsAdmin::MESSAGE);
    $this->actingAs($user)->postJson(route('admin.how-tos.create'), ['name' => 'x'])->assertForbidden();

    $this->actingAs($user)->post(route('admin.settings.platform-fee.update'), ['platformFeePercentage' => 10])
        ->assertRedirect(route('home'))
        ->assertSessionHas('message', EnsureUserIsAdmin::MESSAGE);

    // An admin gets through to the controller.
    $this->actingAs(aarAdmin())->getJson(route('admin.users'))->assertOk();
});

test('the counsellor verification page falls back to pending for an unknown status', function () {
    $admin = aarAdmin();

    $this->actingAs($admin)->get(route('administrator.counsellor_verification', ['status' => 'nonsense']))
        ->assertInertia(fn ($page) => $page->where('status', 'PENDING'));
    $this->actingAs($admin)->get(route('administrator.counsellor_verification', ['status' => 'all']))
        ->assertInertia(fn ($page) => $page->where('status', 'all'));
    $this->actingAs($admin)->get(route('administrator.counsellors', ['search' => 'Ama']))
        ->assertInertia(fn ($page) => $page->where('search', 'Ama'));
});
