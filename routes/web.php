<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\AdminContentAccessController;
use App\Http\Controllers\AdminEmergencyNumberController;
use App\Http\Controllers\AdministratorController;
use App\Http\Controllers\AdminOrganizationBillingController;
use App\Http\Controllers\AdminPayoutController;
use App\Http\Controllers\AdminRefundRequestController;
use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\AdminRequestController;
use App\Http\Controllers\AdminSafeguardingExceptionController;
use App\Http\Controllers\AdminSuspensionController;
use App\Http\Controllers\CounsellorController;
use App\Http\Controllers\CounsellorDashboardController;
use App\Http\Controllers\CounsellorPricingController;
use App\Http\Controllers\DiscussionController;
use App\Http\Controllers\GroupTherapyController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\HelpRequestController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\OrganizationAdminController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationCounsellorCompensationController;
use App\Http\Controllers\OrganizationCounsellorController;
use App\Http\Controllers\OrganizationMemberBillingConfigController;
use App\Http\Controllers\OrganizationMemberController;
use App\Http\Controllers\OrganizationPaymentInstrumentController;
use App\Http\Controllers\OrganizationReconciliationController;
use App\Http\Controllers\PayoutController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SuspensionController;
use App\Http\Controllers\TherapyController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\VideoConsentController;
use App\Http\Controllers\VideoSessionController;
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
        return 'done';
    } catch (Throwable $th) {
    }
});

Route::get('/', [HomeController::class, 'goHome'])
    ->name('home');
Route::get('/about', AboutController::class)
    ->name('about');
// SCRUM-361
Route::get('/privacy', PrivacyController::class)->name('privacy');
Route::get('/public-therapies', function () {
    return Inertia::render('PublicTherapies');
})->name('public.therapies');

Route::get('/therapies/{therapyId}', [TherapyController::class, 'getTherapy'])->name('therapies.get');
Route::get('/group-therapies/{groupTherapyId}', [GroupTherapyController::class, 'getGroupTherapy'])->name('group.therapies.get');

Route::get('/counsellor/{counsellorId}/verify-email/{hash}', [CounsellorController::class, 'verifyEmail'])
    ->middleware(['signed', 'throttle:6,1,counsellor.verification.verify'])
    ->name('counsellor.verification.verify');
// SCRUM-213/TT-2.6b: a counsellor's own cross-therapy session calendar -- must be registered
// before the /counsellor/{counsellorId} route below, or "calendar" gets swallowed as a
// counsellorId route parameter instead (Laravel matches routes in registration order).
Route::middleware('auth')->get('/counsellor/calendar', [SessionController::class, 'calendar'])->name('counsellor.calendar');
// SCRUM-391: before /counsellor/{counsellorId}, like the calendar.
Route::middleware('auth')->get('/counsellor/dashboard', CounsellorDashboardController::class)->name('counsellor.dashboard');
Route::get('/counsellor/{counsellorId}', [CounsellorController::class, 'show'])->name('counsellor.show');

Route::get('/posts/{postId}', [PostController::class, 'getPost'])->name('posts.get');

// SCRUM-33: the "I need help now" dialog's emergency numbers -- for everyone, signed in or not.
Route::get('/help/emergency-numbers', [HelpController::class, 'emergencyNumbers'])->name('help.emergency_numbers')->middleware('throttle:60,1,help.emergency_numbers');

// SCRUM-33: the quick help chat (signed in; open to a suspended account too -- see
// EnsureAccountIsNotSuspended). JSON, apart from the chat page.
Route::middleware('auth')->prefix('help')->name('help.')->group(function () {
    Route::get('/requests/current', [HelpRequestController::class, 'current'])->name('requests.current')->middleware('throttle:60,1,help.requests.current');
    Route::post('/requests', [HelpRequestController::class, 'store'])->name('requests.store')->middleware('throttle:10,1,help.requests.store');
    Route::get('/requests/{helpRequest}', [HelpRequestController::class, 'show'])->name('requests.show')->whereNumber('helpRequest')->middleware('throttle:120,1,help.requests.show');
    Route::post('/requests/{helpRequest}/cancel', [HelpRequestController::class, 'cancel'])->name('requests.cancel')->whereNumber('helpRequest')->middleware('throttle:20,1,help.requests.cancel');
    Route::post('/requests/{helpRequest}/accept', [HelpRequestController::class, 'accept'])->name('requests.accept')->whereNumber('helpRequest')->middleware('throttle:20,1,help.requests.accept');
    Route::post('/requests/{helpRequest}/end', [HelpRequestController::class, 'end'])->name('requests.end')->whereNumber('helpRequest')->middleware('throttle:20,1,help.requests.end');
    Route::get('/requests/{helpRequest}/messages', [HelpRequestController::class, 'messages'])->name('requests.messages')->whereNumber('helpRequest')->middleware('throttle:120,1,help.requests.messages');
    Route::post('/requests/{helpRequest}/messages', [HelpRequestController::class, 'sendMessage'])->name('requests.messages.store')->whereNumber('helpRequest')->middleware('throttle:60,1,help.requests.messages.store');
    Route::get('/chat/{helpRequest}', [HelpRequestController::class, 'page'])->name('chat')->whereNumber('helpRequest');
    Route::get('/requests/ringing', [HelpRequestController::class, 'ringing'])->name('requests.ringing')->middleware('throttle:30,1,help.requests.ringing');
    Route::post('/availability', [HelpRequestController::class, 'availability'])->name('availability')->middleware('throttle:10,1,help.availability');
});

Route::middleware('auth')->group(function () {

    Route::get('/links/{uuid}', [LinkController::class, 'performAction'])->name('links.get');

    Route::get('/discussions/{discussionId}/chat', [DiscussionController::class, 'showChat'])->name('discussions.chat');

    Route::get('/administrator', [AdministratorController::class, 'show'])->name('administrator')->middleware('admin');
    // SCRUM-387: the admin area's own pages -- the old /administrator tabs, each with a URL now, and
    // the "requests to resolve" queue. The `admin` middleware refuses a non-admin (the controllers
    // check again).
    Route::middleware('admin')->group(function () {
        Route::get('/administrator/requests', [AdminRequestController::class, 'index'])->name('administrator.requests');
        Route::get('/administrator/users', [AdministratorController::class, 'users'])->name('administrator.users');
        Route::get('/administrator/counsellors', [AdministratorController::class, 'counsellors'])->name('administrator.counsellors');
        Route::get('/administrator/counsellor-verification', [AdministratorController::class, 'counsellorVerification'])->name('administrator.counsellor_verification');
        Route::get('/administrator/testimonials', [AdministratorController::class, 'testimonials'])->name('administrator.testimonials');
        Route::get('/administrator/how-tos', [AdministratorController::class, 'howTos'])->name('administrator.how_tos');
        // SCRUM-33: the emergency numbers (every admin sees them; a super administrator edits).
        Route::get('/administrator/emergency-numbers', [AdminEmergencyNumberController::class, 'index'])->name('administrator.emergency_numbers');
        Route::post('/administrator/emergency-numbers', [AdminEmergencyNumberController::class, 'store'])->name('admin.emergency_numbers.store')->middleware('throttle:20,1,admin.emergency_numbers.store');
        Route::post('/administrator/emergency-numbers/{number}', [AdminEmergencyNumberController::class, 'update'])->name('admin.emergency_numbers.update')->whereNumber('number')->middleware('throttle:20,1,admin.emergency_numbers.update');
        Route::post('/administrator/emergency-numbers/{number}/delete', [AdminEmergencyNumberController::class, 'destroy'])->name('admin.emergency_numbers.delete')->whereNumber('number')->middleware('throttle:20,1,admin.emergency_numbers.delete');
        // The sidebar's badges (GetAdminToDoCountsAction::badges()), as JSON: AdminLayout.vue fetches
        // them after each page, so no page waits on (or re-runs its own queries for) the counts.
        Route::get('/administrator/badges', [AdministratorController::class, 'badges'])->name('admin.badges')->middleware('throttle:60,1,admin.badges');
    });
    // TT-7.6e/SCRUM-229: admin-facing payout page -- platform-fee/minimum-payout settings form,
    // admin-on-behalf-of trigger UI, payout audit table. Settings updates return Redirect::back()
    // (Inertia's native response shape), so they live here rather than routes/api.php, matching
    // payout.trigger/payout.destination.store's own precedent (SCRUM-228).
    Route::get('/administrator/payouts', [AdminPayoutController::class, 'index'])->name('administrator.payouts')->middleware('admin');
    Route::post('/administrator/settings/platform-fee', [AdminPayoutController::class, 'updatePlatformFee'])->name('admin.settings.platform-fee.update')->middleware(['admin', 'throttle:10,1,admin.settings.platform-fee.update']);
    Route::post('/administrator/settings/minimum-payout', [AdminPayoutController::class, 'updateMinimumPayoutAmounts'])->name('admin.settings.minimum-payout.update')->middleware(['admin', 'throttle:10,1,admin.settings.minimum-payout.update']);

    // TT-7.3b-followup/SCRUM-245: the manual "resolve this" surface SCRUM-238 left unbuilt --
    // platform-admin only (mirrors administrator.payouts' own convention exactly). Write routes
    // throttled to match admin.settings.*'s real-effect rate, not the read-only page's default.
    Route::get('/administrator/organization-billing', [AdminOrganizationBillingController::class, 'index'])->name('administrator.organization_billing')->middleware('admin');
    Route::post('/administrator/organization-invoices/{organizationInvoiceId}/retry-settlement', [AdminOrganizationBillingController::class, 'retrySettlement'])->name('admin.organization_invoices.retry_settlement')->middleware(['admin', 'throttle:10,1,admin.organization_invoices.retry_settlement']);
    Route::post('/administrator/organizations/{organizationId}/lift-billing-suspension', [AdminOrganizationBillingController::class, 'liftSuspension'])->name('admin.organizations.lift_billing_suspension')->middleware(['admin', 'throttle:10,1,admin.organizations.lift_billing_suspension']);

    // TT-7.7c/SCRUM-251: read-only list page -- approve/reject post directly to the existing
    // generic requests.respond endpoint (routes/api.php), not a route here.
    Route::get('/administrator/refund-requests', [AdminRefundRequestController::class, 'index'])->name('administrator.refund_requests')->middleware('admin');

    // SCRUM-340: the complaint-review queue and per-report page. Status/party changes are Inertia
    // form posts; reading the reported conversation is a JSON route in routes/api.php.
    Route::get('/administrator/reports', [AdminReportController::class, 'index'])->name('administrator.reports')->middleware('admin');
    Route::get('/administrator/reports/{report}', [AdminReportController::class, 'show'])->name('administrator.reports.show')->whereNumber('report')->middleware('admin');
    Route::post('/administrator/reports/{report}/status', [AdminReportController::class, 'transition'])->name('admin.reports.status')->whereNumber('report')->middleware(['admin', 'throttle:30,1,admin.reports.status']);
    Route::post('/administrator/reports/{report}/party', [AdminReportController::class, 'recordParty'])->name('admin.reports.party')->whereNumber('report')->middleware(['admin', 'throttle:30,1,admin.reports.party']);
    // SCRUM-360: the admin content-read log (super admins only).
    Route::get('/administrator/content-accesses', [AdminContentAccessController::class, 'index'])->name('administrator.content-accesses')->middleware('admin');
    // SCRUM-359: suspensions -- applied from a resolved report, lifted early, appeals decided.
    Route::get('/administrator/suspensions', [AdminSuspensionController::class, 'index'])->name('administrator.suspensions')->middleware('admin');
    Route::post('/administrator/reports/{report}/suspensions', [AdminSuspensionController::class, 'apply'])->name('admin.suspensions.apply')->whereNumber('report')->middleware(['admin', 'throttle:30,1,admin.suspensions.apply']);
    Route::post('/administrator/suspensions/{suspension}/lift', [AdminSuspensionController::class, 'lift'])->name('admin.suspensions.lift')->whereNumber('suspension')->middleware(['admin', 'throttle:30,1,admin.suspensions.lift']);
    Route::post('/administrator/suspensions/{suspension}/appeal', [AdminSuspensionController::class, 'decideAppeal'])->name('admin.suspensions.appeal')->whereNumber('suspension')->middleware(['admin', 'throttle:30,1,admin.suspensions.appeal']);
    // SCRUM-381: safeguarding exceptions for minors paused for a missing guardian -- every admin
    // sees them; only the super administrator grants, renews (the same route) or ends one.
    Route::get('/administrator/safeguarding-exceptions', [AdminSafeguardingExceptionController::class, 'index'])->name('administrator.safeguarding_exceptions')->middleware('admin');
    Route::post('/administrator/safeguarding-exceptions/wards/{ward}', [AdminSafeguardingExceptionController::class, 'grant'])->name('admin.safeguarding_exceptions.grant')->whereNumber('ward')->middleware(['admin', 'throttle:30,1,admin.safeguarding_exceptions.grant']);
    Route::post('/administrator/safeguarding-exceptions/{exception}/end', [AdminSafeguardingExceptionController::class, 'end'])->name('admin.safeguarding_exceptions.end')->whereNumber('exception')->middleware(['admin', 'throttle:30,1,admin.safeguarding_exceptions.end']);
    // SCRUM-359: the only pages a suspended account can reach (EnsureAccountIsNotSuspended).
    Route::get('/suspended', [SuspensionController::class, 'show'])->name('suspended.show');
    Route::post('/suspended/{suspension}/appeal', [SuspensionController::class, 'appeal'])->name('suspended.appeal')->whereNumber('suspension')->middleware('throttle:10,1,suspended.appeal');

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
    Route::patch('/therapies/{therapyId}/strict-payment-gate', [TherapyController::class, 'updateStrictPaymentGate'])->name('therapies.strict_payment_gate.update')->middleware('throttle:30,1,therapies.strict_payment_gate.update');
    // TT-3.1e-f/SCRUM-285: the first HTTP-reachable surface for the guardian video-consent
    // Actions (TT-3.1e-b/c) -- see VideoConsentController's own docblock. The revoke endpoint is
    // throttled more tightly than its siblings on purpose -- SCRUM-282's own security review
    // flagged grant/revoke cycling as a real griefing risk once a route existed (repeatedly
    // force-ending an in-progress call), not just a generic new-endpoint precaution.
    Route::patch('/therapies/{therapyId}/video-consent-mode', [VideoConsentController::class, 'updateMode'])->name('therapies.video_consent.mode_update')->middleware('throttle:30,1,therapies.video_consent.mode_update');
    Route::post('/therapies/{therapyId}/video-consent', [VideoConsentController::class, 'grant'])->name('therapies.video_consent.grant')->middleware('throttle:30,1,therapies.video_consent.grant');
    // Named limiter, not throttle:10,1 -- keyed by ward, not by user/IP, since a ward can have
    // multiple guardians (see RouteServiceProvider's own comment on this limiter).
    Route::delete('/therapies/{therapyId}/video-consent', [VideoConsentController::class, 'revoke'])->name('therapies.video_consent.revoke')->middleware('throttle:video-consent-revoke');
    Route::get('/therapies/{therapyId}/video-consent/audit-trail', [VideoConsentController::class, 'auditTrail'])->name('therapies.video_consent.audit_trail')->middleware('throttle:60,1,therapies.video_consent.audit_trail');
    Route::delete('/therapies/{therapyId}', [TherapyController::class, 'deleteTherapy'])->name('therapies.delete');
    Route::post('/therapies/{therapyId}', [TherapyController::class, 'endTherapy'])->name('therapies.end');

    Route::post('/therapies/{therapyId}/sessions', [SessionController::class, 'createSession'])->name('sessions.create');
    Route::patch('/therapies/{therapyId}/sessions/{sessionId}', [SessionController::class, 'updateSession'])->name('sessions.update');
    Route::delete('/therapies/{therapyId}/sessions/{sessionId}', [SessionController::class, 'deleteSession'])->name('sessions.delete');

    Route::get('/group-therapies/{groupTherapyId}/chat', [GroupTherapyController::class, 'chat'])->name('group.therapies.chat');
    Route::patch('/group-therapies/{groupTherapyId}', [GroupTherapyController::class, 'updateGroupTherapy'])->name('group.therapies.update');
    // TT-7.5b-b1/SCRUM-265: mirrors therapies.strict_payment_gate.update's own route -- deliberately
    // separate from group.therapies.update above, see GroupTherapyService::updateGroupTherapyPaymentGate()'s
    // own comment for why.
    Route::patch('/group-therapies/{groupTherapyId}/payment-gate', [GroupTherapyController::class, 'updateGroupTherapyPaymentGate'])->name('group.therapies.payment_gate.update')->middleware('throttle:30,1,group.therapies.payment_gate.update');
    Route::delete('/group-therapies/{groupTherapyId}', [GroupTherapyController::class, 'deleteGroupTherapy'])->name('group.therapies.delete');
    Route::post('/group-therapies/{groupTherapyId}', [GroupTherapyController::class, 'endGroupTherapy'])->name('group.therapies.end');

    Route::post('/group-therapies/{groupTherapyId}/sessions', [SessionController::class, 'createSession'])->name('sessions.create');
    Route::patch('/group-therapies/{groupTherapyId}/sessions/{sessionId}', [SessionController::class, 'updateSession'])->name('sessions.update');
    Route::delete('/group-therapies/{groupTherapyId}/sessions/{sessionId}', [SessionController::class, 'deleteSession'])->name('sessions.delete');

    Route::post('/sessions/{sessionId}/in_session', [SessionController::class, 'getInSession'])->name('sessions.in_session');
    Route::post('/sessions/{sessionId}/end', [SessionController::class, 'endSession'])->name('sessions.end');
    Route::post('/sessions/{sessionId}/fail', [SessionController::class, 'failSession'])->name('sessions.fail');
    Route::post('/sessions/{sessionId}/abandon', [SessionController::class, 'abandonSession'])->name('sessions.abandon');

    // TT-3.1b/SCRUM-275: join AND end both call out to the active provider's own API
    // (Daily.co/Chime -- createRoom/createMeeting, deleteRoom/deleteMeeting respectively), on top
    // of join being the strict-payment-gated entry point -- both throttled like
    // transactions.initiate.* above, for the same reason (a real external-API-calling write, not
    // just DB noise). leave is a cheap, self-scoped local write with no such concern.
    // SCRUM-367: every route below that calls the video provider shares the `video-provider` counter.
    Route::post('/sessions/{sessionId}/video/join', [VideoSessionController::class, 'join'])->name('sessions.video.join')->middleware('throttle:20,1,video-provider');
    Route::post('/sessions/{sessionId}/video/leave', [VideoSessionController::class, 'leave'])->name('sessions.video.leave');
    Route::post('/sessions/{sessionId}/video/end', [VideoSessionController::class, 'end'])->name('sessions.video.end')->middleware('throttle:20,1,video-provider');
    // SCRUM-334: no provider call, so its own counter -- a group call looks up each tile's name once.
    Route::get('/sessions/{sessionId}/video/participants/{participantKey}', [VideoSessionController::class, 'participant'])->name('sessions.video.participants.show')->middleware('throttle:120,1,sessions.video.participants.show');
    // TT-3.2b/SCRUM-309: ejects one participant via the provider's own API (like join/end above,
    // a real external-API call, not just DB noise) -- same throttle tier as those.
    Route::post('/sessions/{sessionId}/video/participants/{participantKey}/remove', [VideoSessionController::class, 'removeParticipant'])->name('sessions.video.participants.remove')->middleware('throttle:20,1,video-provider');
    // TT-3.2f-f/SCRUM-323: grant/revoke call the provider's own API (same tier as remove above);
    // raise/lower are local writes but each one broadcasts, so they're throttled too.
    Route::post('/sessions/{sessionId}/video/participants/{participantKey}/grant-speaking', [VideoSessionController::class, 'grantSpeaking'])->name('sessions.video.participants.grant_speaking')->middleware('throttle:20,1,video-provider');
    Route::post('/sessions/{sessionId}/video/participants/{participantKey}/revoke-speaking', [VideoSessionController::class, 'revokeSpeaking'])->name('sessions.video.participants.revoke_speaking')->middleware('throttle:20,1,video-provider');
    Route::post('/sessions/{sessionId}/video/hand/raise', [VideoSessionController::class, 'raiseHand'])->name('sessions.video.hand.raise')->middleware('throttle:30,1,sessions.video.hand.raise');
    Route::post('/sessions/{sessionId}/video/hand/lower', [VideoSessionController::class, 'lowerHand'])->name('sessions.video.hand.lower')->middleware('throttle:30,1,sessions.video.hand.lower');
    // TT-3.2f-h/SCRUM-325: a periodic background ping -- its own named limiter so it never eats
    // into the per-user `throttle:N,1` bucket join/raise/grant share (SCRUM-323 security F6).
    Route::post('/sessions/{sessionId}/video/speaking-activity', [VideoSessionController::class, 'speakingActivity'])->name('sessions.video.speaking_activity')->middleware('throttle:video-speaking-activity');

    // throttle: this calls out to Paystack's own API per request, on top of being a real
    // money-initiation endpoint -- unlike most routes here, an unthrottled version is a genuine
    // abuse vector, not just noise.
    // SCRUM-367: the three Paystack initiations share one counter (one allowance across them).
    Route::post('/therapies/{therapyId}/transactions', [TransactionController::class, 'initiate'])->name('transactions.initiate.therapy')->middleware('throttle:20,1,transactions-initiate');
    Route::post('/group-therapies/{groupTherapyId}/transactions', [TransactionController::class, 'initiate'])->name('transactions.initiate.group_therapy')->middleware('throttle:20,1,transactions-initiate');
    Route::post('/sessions/{sessionId}/transactions', [TransactionController::class, 'initiate'])->name('transactions.initiate.session')->middleware('throttle:20,1,transactions-initiate');
    Route::get('/transactions/callback', [TransactionController::class, 'callback'])->name('transactions.callback')->middleware('throttle:30,1,transactions.callback');
    // TT-7.7b/SCRUM-250: throttled like the initiate.* routes above -- not itself a money-moving
    // call, but still a client-initiated write against a specific transaction.
    Route::post('/transactions/{transactionId}/refund-request', [TransactionController::class, 'requestRefund'])->name('transactions.refund_request.store')->middleware('throttle:20,1,transactions.refund_request.store');

    // throttle: read-only and non-money-moving, but this is the first endpoint that lets any
    // authenticated user enumerate every verified org on the platform -- a higher cap than the
    // write endpoints below, but still bounded (security review, SCRUM-161).
    Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index')->middleware('throttle:60,1,organizations.index');
    Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
    Route::get('/organizations/{organizationId}', [OrganizationController::class, 'show'])->name('organizations.show');
    Route::patch('/organizations/{organizationId}', [OrganizationController::class, 'update'])->name('organizations.update')->middleware('throttle:10,1,image-uploads'); // SCRUM-192: image uploads, own counter

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

    // Org-admin reconciliation view (SCRUM-241/TT-7.3b-j) -- admin-gated like the 3 routes above,
    // but throttled (security-engineer finding): unlike a membership/counsellor roster, this
    // surfaces real transaction amounts, counsellor compensation splits, and payout status --
    // matching organizations.index's own more conservative precedent for exactly this reasoning
    // ("the first endpoint that lets any authenticated user enumerate/see something sensitive"),
    // not the plain-roster routes' unthrottled default. reconciliation is the browser-navigable
    // Inertia page, .transactions/.invoices are the dedicated JSON list endpoints its own
    // pagination continues against (mirrors organizations.dashboard's own page-plus-JSON split).
    Route::get('/organizations/{organizationId}/reconciliation', [OrganizationReconciliationController::class, 'index'])->name('organizations.reconciliation')->middleware('throttle:60,1,organizations.reconciliation');
    Route::get('/organizations/{organizationId}/reconciliation/transactions', [OrganizationReconciliationController::class, 'transactions'])->name('organizations.reconciliation.transactions')->middleware('throttle:60,1,organizations.reconciliation.transactions');
    Route::get('/organizations/{organizationId}/reconciliation/invoices', [OrganizationReconciliationController::class, 'invoices'])->name('organizations.reconciliation.invoices')->middleware('throttle:60,1,organizations.reconciliation.invoices');

    // Org-admin payment-instrument onboarding UI (SCRUM-240/TT-7.3b-i) -- index is the
    // browser-navigable Inertia page (admin-gated, throttled like reconciliation above since it
    // discloses masked card details); initiate starts the real Paystack checkout redirect that
    // captures a reusable card authorization, throttled at the same rate as the other
    // real-money-movement transactions.initiate.* routes above, not reconciliation's read-only rate.
    Route::get('/organizations/{organizationId}/payment-instrument', [OrganizationPaymentInstrumentController::class, 'index'])->name('organizations.payment_instrument')->middleware('throttle:60,1,organizations.payment_instrument');
    Route::post('/organizations/{organizationId}/payment-instrument', [OrganizationPaymentInstrumentController::class, 'initiate'])->name('organizations.payment_instrument.initiate')->middleware('throttle:20,1,organizations.payment_instrument.initiate');

    // throttle: uncapped, either of these could be used to spam an org's admins with invites,
    // or spam every provider org on the platform with applications (SCRUM-120 security review).
    Route::post('/organizations/{organizationId}/counsellor-invites', [OrganizationCounsellorController::class, 'invite'])->name('organizations.counsellors.invite')->middleware('throttle:30,1,organizations.counsellors.invite');
    Route::post('/organizations/{organizationId}/counsellor-applications', [OrganizationCounsellorController::class, 'apply'])->name('organizations.counsellors.apply')->middleware('throttle:30,1,organizations.counsellors.apply');

    Route::get('/organization-counsellors/{organizationCounsellorId}/compensations', [OrganizationCounsellorCompensationController::class, 'index'])->name('organization_counsellors.compensations.index');
    Route::post('/organization-counsellors/{organizationCounsellorId}/compensations', [OrganizationCounsellorCompensationController::class, 'store'])->name('organization_counsellors.compensations.store')->middleware('throttle:30,1,organization_counsellors.compensations.store');
    Route::post('/requests/{requestId}/compensation-counter-offer', [OrganizationCounsellorCompensationController::class, 'counterOffer'])->name('requests.compensation_counter_offer')->middleware('throttle:30,1,requests.compensation_counter_offer');
    Route::get('/organization-counsellors/{organizationCounsellorId}/compensations/negotiation-state', [OrganizationCounsellorCompensationController::class, 'negotiationState'])->name('organization_counsellors.compensations.negotiation_state');

    // throttle: same reasoning as the counsellor-invite/apply routes above (SCRUM-124).
    Route::post('/organizations/{organizationId}/member-invites', [OrganizationMemberController::class, 'invite'])->name('organizations.members.invite')->middleware('throttle:30,1,organizations.members.invite');
    Route::post('/organizations/{organizationId}/member-applications', [OrganizationMemberController::class, 'apply'])->name('organizations.members.apply')->middleware('throttle:30,1,organizations.members.apply');

    // Owner-only co-admin management (SCRUM-163). throttle: mutating actions on shared org state,
    // same modest cap as the other Organization write routes above.
    Route::post('/organizations/{organizationId}/admins', [OrganizationAdminController::class, 'store'])->name('organizations.admins.store')->middleware('throttle:30,1,organizations.admins.store');
    Route::patch('/organizations/{organizationId}/admins/{userId}', [OrganizationAdminController::class, 'update'])->name('organizations.admins.update')->middleware('throttle:30,1,organizations.admins.update');
    Route::delete('/organizations/{organizationId}/admins/{userId}', [OrganizationAdminController::class, 'destroy'])->name('organizations.admins.destroy')->middleware('throttle:30,1,organizations.admins.destroy');

    Route::post('/organization-members/{organizationMemberId}/billing-configs', [OrganizationMemberBillingConfigController::class, 'store'])->name('organization_members.billing_configs.store')->middleware('throttle:30,1,organization_members.billing_configs.store');

    Route::get('/preferences', [PreferenceController::class, 'show'])->name('preferences');
    Route::post('/preferences', [PreferenceController::class, 'set'])->name('preferences.set');

    // Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');

    Route::post('/counsellor/{counsellorId}', [CounsellorController::class, 'updateCounsellor'])->name('counsellor.update')->middleware('throttle:30,1,counsellor-update'); // SCRUM-192: image uploads (shared by the four profile forms, so roomier), own counter
    Route::post('/counsellor/{counsellorId}/pricings', [CounsellorPricingController::class, 'store'])->name('counsellor.pricings.store')->middleware('throttle:30,1,counsellor.pricings.store');
    Route::delete('/counsellor/{counsellorId}/pricings', [CounsellorPricingController::class, 'destroy'])->name('counsellor.pricings.destroy')->middleware('throttle:30,1,counsellor.pricings.destroy');
    // TT-7.6d/SCRUM-228: deliberately no {counsellorId} route param -- self-service only, always
    // the authenticated user's own counsellor/payout (security requirement carried from TT-7.6a's
    // review). TT-7.6e's admin-on-behalf-of trigger reuses this same route via counsellorId in
    // the request body instead, which GetPayoutTargetCounsellorAction only honors for an admin.
    Route::post('/payout-destination', [PayoutController::class, 'onboardDestination'])->name('payout.destination.store')->middleware('throttle:30,1,payout.destination.store');
    // A tighter ceiling than the form-submission rate above -- this one actually moves money
    // (security-engineer finding; TriggerCounsellorPayoutAction's own locked transaction already
    // prevents a double-spend, this is just a lower ceiling on attempts).
    Route::post('/payout/trigger', [PayoutController::class, 'triggerPayout'])->name('payout.trigger')->middleware('throttle:6,1,payout.trigger');
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
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar.update')->middleware('throttle:10,1,image-uploads'); // SCRUM-192: image uploads, own counter
});

require __DIR__.'/auth.php';
