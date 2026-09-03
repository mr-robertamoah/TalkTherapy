<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\AdministratorController;
use App\Http\Controllers\AdminPayoutController;
use App\Http\Controllers\CounsellorController;
use App\Http\Controllers\CounsellorPricingController;
use App\Http\Controllers\DiscussionController;
use App\Http\Controllers\GroupTherapyController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\OrganizationAdminController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationCounsellorCompensationController;
use App\Http\Controllers\OrganizationCounsellorController;
use App\Http\Controllers\OrganizationMemberBillingConfigController;
use App\Http\Controllers\OrganizationMemberController;
use App\Http\Controllers\PayoutController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SessionNoteController;
use App\Http\Controllers\TherapyController;
use App\Http\Controllers\TransactionController;
use App\Services\AppService;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// Route::get('/', function () {
//     return Inertia::render('Welcome', [
//         'canLogin' => Route::has('login'),
//         'canRegister' => Route::has('register'),
//         'laravelVersion' => Application::VERSION,
//         'phpVersion' => PHP_VERSION,
//     ]);
// });

Route::get('/testing', function () {
    try {
        // AppService::new()->alertSuperAdminWithStatus();
        return 'done';
    } catch (Throwable $th) {
    }
});

Route::get('/', [HomeController::class, 'goHome'])
    ->name('home');
Route::get('/about', AboutController::class)
    ->name('about');
Route::get('/public-therapies', function () {
    return Inertia::render('PublicTherapies');
})->name('public.therapies');

Route::get('/therapies/{therapyId}', [TherapyController::class, 'getTherapy'])->name('therapies.get');
Route::get('/group-therapies/{groupTherapyId}', [GroupTherapyController::class, 'getGroupTherapy'])->name('group.therapies.get');

Route::get('/counsellor/{counsellorId}/verify-email/{hash}', [CounsellorController::class, 'verifyEmail'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('counsellor.verification.verify');
// SCRUM-213/TT-2.6b: a counsellor's own cross-therapy session calendar -- must be registered
// before the /counsellor/{counsellorId} route below, or "calendar" gets swallowed as a
// counsellorId route parameter instead (Laravel matches routes in registration order).
Route::middleware('auth')->get('/counsellor/calendar', [SessionController::class, 'calendar'])->name('counsellor.calendar');
Route::get('/counsellor/{counsellorId}', [CounsellorController::class, 'show'])->name('counsellor.show');

Route::get('/posts/{postId}', [PostController::class, 'getPost'])->name('posts.get');

Route::middleware('auth')->group(function () {

    Route::get('/links/{uuid}', [LinkController::class, 'performAction'])->name('links.get');

    Route::get('/discussions/{discussionId}/chat', [DiscussionController::class, 'showChat'])->name('discussions.chat');

    Route::get('/administrator', [AdministratorController::class, 'show'])->name('administrator');
    // TT-7.6e/SCRUM-229: admin-facing payout page -- platform-fee/minimum-payout settings form,
    // admin-on-behalf-of trigger UI, payout audit table. Settings updates return Redirect::back()
    // (Inertia's native response shape), so they live here rather than routes/api.php, matching
    // payout.trigger/payout.destination.store's own precedent (SCRUM-228).
    Route::get('/administrator/payouts', [AdminPayoutController::class, 'index'])->name('administrator.payouts');
    Route::post('/administrator/settings/platform-fee', [AdminPayoutController::class, 'updatePlatformFee'])->name('admin.settings.platform-fee.update')->middleware('throttle:10,1');
    Route::post('/administrator/settings/minimum-payout', [AdminPayoutController::class, 'updateMinimumPayoutAmounts'])->name('admin.settings.minimum-payout.update')->middleware('throttle:10,1');

    Route::get('/therapies', [TherapyController::class, 'show'])->name('therapies');
    Route::get('/therapies/{therapyId}/chat', [TherapyController::class, 'chat'])->name('therapies.chat');
    Route::patch('/therapies/{therapyId}', [TherapyController::class, 'updateTherapy'])->name('therapies.update');
    // SCRUM-221/TT-7.5a: separate from therapies.update above -- the assigned counsellor can
    // never reach that route (EnsureCanUpdateTherapyAction has no branch for a counsellor merely
    // assigned via counsellor_id), and this narrower one deliberately doesn't need it to, since
    // EnsureCanSetStrictPaymentGateAction is a complete, self-contained authorization check.
    // Throttled (unlike its sibling above, which has none) simply because it's new and this
    // codebase's convention leans toward throttling newly-added write endpoints by default,
    // not because of any specific abuse concern the sibling route lacks.
    Route::patch('/therapies/{therapyId}/strict-payment-gate', [TherapyController::class, 'updateStrictPaymentGate'])->name('therapies.strict_payment_gate.update')->middleware('throttle:30,1');
    Route::delete('/therapies/{therapyId}', [TherapyController::class, 'deleteTherapy'])->name('therapies.delete');
    Route::post('/therapies/{therapyId}', [TherapyController::class, 'endTherapy'])->name('therapies.end');

    Route::post('/therapies/{therapyId}/sessions', [SessionController::class, 'createSession'])->name('sessions.create');
    Route::patch('/therapies/{therapyId}/sessions/{sessionId}', [SessionController::class, 'updateSession'])->name('sessions.update');
    Route::delete('/therapies/{therapyId}/sessions/{sessionId}', [SessionController::class, 'deleteSession'])->name('sessions.delete');

    Route::get('/group-therapies/{groupTherapyId}/chat', [GroupTherapyController::class, 'chat'])->name('group.therapies.chat');
    Route::patch('/group-therapies/{groupTherapyId}', [GroupTherapyController::class, 'updateGroupTherapy'])->name('group.therapies.update');
    Route::delete('/group-therapies/{groupTherapyId}', [GroupTherapyController::class, 'deleteGroupTherapy'])->name('group.therapies.delete');
    Route::post('/group-therapies/{groupTherapyId}', [GroupTherapyController::class, 'endGroupTherapy'])->name('group.therapies.end');

    Route::post('/group-therapies/{groupTherapyId}/sessions', [SessionController::class, 'createSession'])->name('sessions.create');
    Route::patch('/group-therapies/{groupTherapyId}/sessions/{sessionId}', [SessionController::class, 'updateSession'])->name('sessions.update');
    Route::delete('/group-therapies/{groupTherapyId}/sessions/{sessionId}', [SessionController::class, 'deleteSession'])->name('sessions.delete');

    Route::post('/sessions/{sessionId}/in_session', [SessionController::class, 'getInSession'])->name('sessions.in_session');
    Route::post('/sessions/{sessionId}/end', [SessionController::class, 'endSession'])->name('sessions.end');
    Route::post('/sessions/{sessionId}/fail', [SessionController::class, 'failSession'])->name('sessions.fail');
    Route::post('/sessions/{sessionId}/abandon', [SessionController::class, 'abandonSession'])->name('sessions.abandon');

    // SCRUM-197/TT-2.2b: a counsellor's own private notes on a session -- never exposed to the
    // client/participant side, see SessionNoteController's own comment on how counsellor_id is
    // always derived server-side, never accepted as input.
    Route::get('/sessions/{sessionId}/notes', [SessionNoteController::class, 'index'])->name('session.notes.index');
    Route::post('/sessions/{sessionId}/notes', [SessionNoteController::class, 'store'])->name('session.notes.store');
    Route::patch('/sessions/notes/{noteId}', [SessionNoteController::class, 'update'])->name('session.notes.update');
    Route::delete('/sessions/notes/{noteId}', [SessionNoteController::class, 'destroy'])->name('session.notes.destroy');

    // throttle: this calls out to Paystack's own API per request, on top of being a real
    // money-initiation endpoint -- unlike most routes here, an unthrottled version is a genuine
    // abuse vector, not just noise.
    Route::post('/therapies/{therapyId}/transactions', [TransactionController::class, 'initiate'])->name('transactions.initiate.therapy')->middleware('throttle:20,1');
    Route::post('/group-therapies/{groupTherapyId}/transactions', [TransactionController::class, 'initiate'])->name('transactions.initiate.group_therapy')->middleware('throttle:20,1');
    Route::post('/sessions/{sessionId}/transactions', [TransactionController::class, 'initiate'])->name('transactions.initiate.session')->middleware('throttle:20,1');
    Route::get('/transactions/callback', [TransactionController::class, 'callback'])->name('transactions.callback')->middleware('throttle:30,1');

    // throttle: read-only and non-money-moving, but this is the first endpoint that lets any
    // authenticated user enumerate every verified org on the platform -- a higher cap than the
    // write endpoints below, but still bounded (security review, SCRUM-161).
    Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index')->middleware('throttle:60,1');
    Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
    Route::get('/organizations/{organizationId}', [OrganizationController::class, 'show'])->name('organizations.show');
    Route::patch('/organizations/{organizationId}', [OrganizationController::class, 'update'])->name('organizations.update');

    // "My organizations" (SCRUM-160/TT-6.6b, SCRUM-167/TT-6.5b) -- self-scoped to the caller, no
    // throttle beyond the default web group (same reasoning as organizations.show). /mine/...
    // rather than a bare {organizationId}-shaped segment, so these paths can never collide with
    // the /organizations/{organizationId}/... routes below -- MUST stay registered before any of
    // them, including organizations.dashboard immediately below: a literal "/mine/dashboard"
    // would otherwise be captured by "/{organizationId}/dashboard" with organizationId="mine"
    // (caught by MyOrganizationsControllerTest when organizations.mine.dashboard was first added
    // after organizations.dashboard instead of before it).
    Route::get('/organizations/mine/counsellor-affiliations', [OrganizationController::class, 'myCounsellorAffiliations'])->name('organizations.mine.counsellor_affiliations');
    Route::get('/organizations/mine/memberships', [OrganizationController::class, 'myMemberships'])->name('organizations.mine.memberships');
    Route::get('/organizations/mine/administered', [OrganizationController::class, 'myAdministeredOrganizations'])->name('organizations.mine.administered');
    Route::get('/organizations/mine/dashboard', [OrganizationController::class, 'myOrganizationsDashboard'])->name('organizations.mine.dashboard');
    Route::get('/organizations/mine/requests', [OrganizationController::class, 'myOrganizationRequestQueue'])->name('organizations.mine.requests');

    // Org admin dashboard page (SCRUM-165/TT-6.5a) -- the browser-navigable Inertia page;
    // organizations.show above stays the JSON-only API endpoint, unchanged.
    Route::get('/organizations/{organizationId}/dashboard', [OrganizationController::class, 'dashboard'])->name('organizations.dashboard');

    // Admin-only org-scoped lists (SCRUM-159/TT-6.6a) -- no throttle beyond the default web
    // group, matching organizations.show above: an authenticated-admin-gated GET, not a
    // money/spam vector like the invite/apply routes below.
    Route::get('/organizations/{organizationId}/members', [OrganizationMemberController::class, 'index'])->name('organizations.members.index');
    Route::get('/organizations/{organizationId}/counsellors', [OrganizationCounsellorController::class, 'index'])->name('organizations.counsellors.index');
    Route::get('/organizations/{organizationId}/requests', [OrganizationController::class, 'requestQueue'])->name('organizations.requests.index');

    // throttle: uncapped, either of these could be used to spam an org's admins with invites,
    // or spam every provider org on the platform with applications (SCRUM-120 security review).
    Route::post('/organizations/{organizationId}/counsellor-invites', [OrganizationCounsellorController::class, 'invite'])->name('organizations.counsellors.invite')->middleware('throttle:30,1');
    Route::post('/organizations/{organizationId}/counsellor-applications', [OrganizationCounsellorController::class, 'apply'])->name('organizations.counsellors.apply')->middleware('throttle:30,1');

    Route::get('/organization-counsellors/{organizationCounsellorId}/compensations', [OrganizationCounsellorCompensationController::class, 'index'])->name('organization_counsellors.compensations.index');
    Route::post('/organization-counsellors/{organizationCounsellorId}/compensations', [OrganizationCounsellorCompensationController::class, 'store'])->name('organization_counsellors.compensations.store')->middleware('throttle:30,1');
    Route::post('/requests/{requestId}/compensation-counter-offer', [OrganizationCounsellorCompensationController::class, 'counterOffer'])->name('requests.compensation_counter_offer')->middleware('throttle:30,1');
    Route::get('/organization-counsellors/{organizationCounsellorId}/compensations/negotiation-state', [OrganizationCounsellorCompensationController::class, 'negotiationState'])->name('organization_counsellors.compensations.negotiation_state');

    // throttle: same reasoning as the counsellor-invite/apply routes above (SCRUM-124).
    Route::post('/organizations/{organizationId}/member-invites', [OrganizationMemberController::class, 'invite'])->name('organizations.members.invite')->middleware('throttle:30,1');
    Route::post('/organizations/{organizationId}/member-applications', [OrganizationMemberController::class, 'apply'])->name('organizations.members.apply')->middleware('throttle:30,1');

    // Owner-only co-admin management (SCRUM-163). throttle: mutating actions on shared org state,
    // same modest cap as the other Organization write routes above.
    Route::post('/organizations/{organizationId}/admins', [OrganizationAdminController::class, 'store'])->name('organizations.admins.store')->middleware('throttle:30,1');
    Route::patch('/organizations/{organizationId}/admins/{userId}', [OrganizationAdminController::class, 'update'])->name('organizations.admins.update')->middleware('throttle:30,1');
    Route::delete('/organizations/{organizationId}/admins/{userId}', [OrganizationAdminController::class, 'destroy'])->name('organizations.admins.destroy')->middleware('throttle:30,1');

    Route::post('/organization-members/{organizationMemberId}/billing-configs', [OrganizationMemberBillingConfigController::class, 'store'])->name('organization_members.billing_configs.store')->middleware('throttle:30,1');

    Route::get('/preferences', [PreferenceController::class, 'show'])->name('preferences');
    Route::post('/preferences', [PreferenceController::class, 'set'])->name('preferences.set');

    // Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');

    Route::post('/counsellor/{counsellorId}', [CounsellorController::class, 'updateCounsellor'])->name('counsellor.update');
    Route::post('/counsellor/{counsellorId}/pricings', [CounsellorPricingController::class, 'store'])->name('counsellor.pricings.store')->middleware('throttle:30,1');
    Route::delete('/counsellor/{counsellorId}/pricings', [CounsellorPricingController::class, 'destroy'])->name('counsellor.pricings.destroy')->middleware('throttle:30,1');
    // TT-7.6d/SCRUM-228: deliberately no {counsellorId} route param -- self-service only, always
    // the authenticated user's own counsellor/payout (security requirement carried from TT-7.6a's
    // review). TT-7.6e's admin-on-behalf-of trigger reuses this same route via counsellorId in
    // the request body instead, which GetPayoutTargetCounsellorAction only honors for an admin.
    Route::post('/payout-destination', [PayoutController::class, 'onboardDestination'])->name('payout.destination.store')->middleware('throttle:30,1');
    // A tighter ceiling than the form-submission rate above -- this one actually moves money
    // (security-engineer finding; TriggerCounsellorPayoutAction's own locked transaction already
    // prevents a double-spend, this is just a lower ceiling on attempts).
    Route::post('/payout/trigger', [PayoutController::class, 'triggerPayout'])->name('payout.trigger')->middleware('throttle:6,1');
    Route::post('/counsellor/{counsellorId}/verify', [CounsellorController::class, 'verifyCounsellor'])->name('counsellor.verify');
    Route::post('/counsellor/{counsellorId}/verify-email', [CounsellorController::class, 'sendVerificationEmail'])->name('counsellor.email.verification');
    // SCRUM-134: was missing entirely -- the frontend's delete button has always called this
    // route name, it just never existed.
    Route::delete('/counsellor/{counsellorId}', [CounsellorController::class, 'deleteCounsellor'])->name('counsellor.delete');

    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    // Self-service only -- always operates on $request->user(), never a route-supplied user id,
    // so there's no other-user id to check/enumerate (SCRUM-182/TT-10.6).
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar.update');
});

require __DIR__.'/auth.php';
