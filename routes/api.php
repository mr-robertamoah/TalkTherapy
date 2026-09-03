<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\AdministratorController;
use App\Http\Controllers\AdminPayoutController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CounsellorController;
use App\Http\Controllers\DiscussionController;
use App\Http\Controllers\GroupTherapyController;
use App\Http\Controllers\HowToController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\LicensingAuthorityController;
use App\Http\Controllers\LikeController;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MessageNoteController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfessionController;
use App\Http\Controllers\ReligionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SessionNoteController;
use App\Http\Controllers\SessionScheduleProposalController;
use App\Http\Controllers\TestimonialController;
use App\Http\Controllers\TherapyCaseController;
use App\Http\Controllers\TherapyController;
use App\Http\Controllers\TherapyTopicController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/therapies/{therapyId}/topics', [TherapyTopicController::class, 'getTherapyTopics'])->name('api.topics.get');
Route::get('/therapies/{therapyId}/sessions', [SessionController::class, 'getSessions'])->name('api.sessions.get');

Route::get('/therapies/random', [TherapyController::class, 'getRandomTherapies'])->name('api.therapies.random');
Route::get('/therapies/public', [TherapyController::class, 'getPublicTherapies'])->name('api.therapies.public');
Route::get('/group-therapies/random', [GroupTherapyController::class, 'getRandomGroupTherapies'])->name('api.group.therapies.random');
Route::get('/counsellors/random', [CounsellorController::class, 'getRandomCounsellors'])->name('api.counsellors.random');
// SCRUM-177: the general 'api' RateLimiter is disabled (see RouteServiceProvider/bootstrap/app.php),
// so this search endpoint had no rate limiting despite gaining more UI call sites (SCRUM-172) --
// 60/minute matches the existing precedent for a similar read/search route (organizations.index).
Route::get('/counsellors', [CounsellorController::class, 'getCounsellors'])->name('api.counsellors')->middleware('throttle:60,1');

Route::get('/testimonials', [TestimonialController::class, 'getTestimonials'])->name('api.testimonials');

Route::post('/contacts', [ContactController::class, 'createContact'])->name('api.contacts.create');

// Unauthenticated by design -- Paystack's server calls this directly, not a browser. Trust
// boundary is the signature check inside TransactionService::handleWebhook(), not auth:sanctum.
// Throttled generously (not per-user, since there's no auth) purely as abuse protection, not to
// constrain legitimate Paystack retries.
Route::post('/paystack/webhook', [TransactionController::class, 'webhook'])->name('api.paystack.webhook')->middleware('throttle:120,1');

Route::get('/therapy-cases', [TherapyCaseController::class, 'getCases'])->name('cases.get');
Route::get('/languages', [LanguageController::class, 'getLanguages'])->name('languages.get');
Route::get('/religions', [ReligionController::class, 'getReligions'])->name('religions.get');
Route::get('/professions', [ProfessionController::class, 'getProfessions'])->name('professions.get');

Route::get('how-tos', [HowToController::class, 'getHowTos'])->name('api.how-tos');

Route::get('/posts', [PostController::class, 'getPosts'])->name('api.posts');

Route::get('/likes', [LikeController::class, 'getLikes'])->name('api.likes');

Route::get('/comments', [CommentController::class, 'getComments'])->name('api.comments');

Route::get('/about/stats', [AboutController::class, 'getStats'])->name('api.about.stats');

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/links', [LinkController::class, 'getLinks'])->name('api.links');
    Route::post('/links', [LinkController::class, 'createLink'])->name('api.links.create');
    Route::post('/links/multiple', [LinkController::class, 'createLink'])->name('api.links.createmultiple');
    Route::post('/links/{linkId}/status', [LinkController::class, 'changeLinkStatus'])->name('api.links.status');
    Route::post('/links/status/multiple', [LinkController::class, 'changeMultipleLinkStatuses'])->name('api.links.statusmultiple');
    Route::post('/links/{linkId}/delete', [LinkController::class, 'deleteLink'])->name('api.links.delete');
    Route::post('/links/delete/multiple', [LinkController::class, 'deleteMultipleLinks'])->name('api.links.deletemultiple');

    // SCRUM-177: see the matching comment on api.counsellors above.
    Route::get('/users', [UserController::class, 'getUsers'])->name('api.users')->middleware('throttle:60,1');
    Route::get('/users/guardianship', [UserController::class, 'getGuardianship'])->name('api.users.guardianship');
    Route::post('/users/{userId}/guardianship', [UserController::class, 'sendGuardianshipRequest'])->name('api.users.guardianshiprequest');
    Route::delete('/guardianship/{guardianshipId}', [UserController::class, 'deleteGuardianship'])->name('api.guardianship.delete');

    Route::get('/administrator/verification/requests', [AdministratorController::class, 'getVerificationRequests'])->name('admin.verification.requests');
    Route::get('/administrator/counsellors', [AdministratorController::class, 'getCounsellors'])->name('admin.counsellors');
    Route::get('/administrator/counsellors/{counsellorId}/stats', [AdministratorController::class, 'getCounsellorStats'])->name('admin.counsellors.stats');
    // SCRUM-134: admin-triggered counsellor deletion (e.g. revoking a counsellor found practicing
    // without a valid license) -- reuses the same CounsellorService::deleteCounsellor()/
    // EnsureCanDeleteCounsellorAction chain as self-service deletion, which already requires
    // isSuperAdmin() for the admin branch, mirroring admin.users.delete's super-admin gate below.
    Route::delete('/administrator/counsellors/{counsellorId}', [AdministratorController::class, 'deleteCounsellor'])->name('admin.counsellors.delete');
    Route::get('/administrator/users', [AdministratorController::class, 'getUsers'])->name('admin.users');
    Route::post('/administrator/users/{userId}', [AdministratorController::class, 'updateUser'])->name('admin.users.update');
    Route::delete('/administrator/users/{userId}', [AdministratorController::class, 'deleteUser'])->name('admin.users.delete');

    // TT-7.6e/SCRUM-229: mirrors admin.counsellors's own convention -- a non-admin caller gets a
    // silently-empty result (getPayoutsForAdmin's own guard), not an exception.
    Route::get('/administrator/payouts', [AdminPayoutController::class, 'payouts'])->name('admin.payouts');
    Route::get('/administrator/payouts/counsellors/{counsellorId}/overview', [AdminPayoutController::class, 'counsellorOverview'])->name('admin.payouts.counsellor-overview');

    Route::post('/administrator/how-tos', [HowToController::class, 'createHowTo'])->name('admin.how-tos.create');
    Route::post('/administrator/how-tos/{howToId}', [HowToController::class, 'updateHowTo'])->name('admin.how-tos.update');
    Route::delete('/administrator/how-tos/{howToId}', [HowToController::class, 'deleteHowTo'])->name('admin.how-tos.delete');

    Route::get('/requests/counsellors', [CounsellorController::class, 'getRequestCounsellors'])->name('counsellors.request.get');

    Route::post('/alerts', [AlertController::class, 'waitingForAlert'])->name('alert.wait');

    Route::get('/licensing_authorities', [LicensingAuthorityController::class, 'getLicensingAuthorities'])->name('licensing_authorities');
    Route::post('/licensing_authorities', [LicensingAuthorityController::class, 'createLicensingAuthority'])->name('licensing_authorities.create');

    Route::get('/requests', [RequestController::class, 'getRequests'])->name('requests.get');
    // Throttled (security review, SCRUM-207): every accept/reject now also runs a lockForUpdate()
    // transaction (session-schedule-proposal accept/reject reuse this same generic endpoint), so
    // this needed the same rate limit already applied to the newer schedule-proposal routes below.
    Route::post('/requests/{requestId}', [RequestController::class, 'respond'])->name('requests.respond')->middleware('throttle:30,1');

    Route::post('/testimonials', [TestimonialController::class, 'createTestimonial'])->name('api.testimonials.create');
    Route::delete('/testimonials/{testimonialId}', [TestimonialController::class, 'deleteTestimonial'])->name('api.testimonials.delete');
    Route::post('/testimonials/{testimonialId}', [TestimonialController::class, 'updateTestimonial'])->name('api.testimonials.update');
    Route::post('/testimonials/{testimonialId}/mark', [TestimonialController::class, 'markTestimonial'])->name('api.testimonials.mark');

    Route::delete('/contacts/{contactId}', [ContactController::class, 'deleteContact'])->name('api.contacts.delete');
    Route::post('/contacts/{contactId}', [ContactController::class, 'updateContact'])->name('api.contacts.update');
    Route::get('/contacts', [ContactController::class, 'getContacts'])->name('api.contacts');

    Route::get('/reports', [ReportController::class, 'getReports'])->name('api.reports');
    Route::get('/reports/{reportId}', [ReportController::class, 'getReport'])->name('api.reports.get');
    Route::post('/reports', [ReportController::class, 'createReport'])->name('api.reports.create');
    Route::post('/reports/{reportId}', [ReportController::class, 'updateReport'])->name('api.reports.update');
    Route::delete('/reports/{reportId}', [ReportController::class, 'deleteReport'])->name('api.reports.delete');

    Route::post('/posts', [PostController::class, 'createPost'])->name('api.posts.create');
    Route::post('/posts/{postId}', [PostController::class, 'updatePost'])->name('api.posts.update');
    Route::delete('/posts/{postId}', [PostController::class, 'deletePost'])->name('api.posts.delete');

    Route::post('/comments', [CommentController::class, 'createComment'])->name('api.comments.create');
    Route::delete('/comments/{commentId}', [CommentController::class, 'deleteComment'])->name('api.comments.delete');

    Route::post('/likes', [LikeController::class, 'like'])->name('api.likes.create');
    Route::post('/likes/delete', [LikeController::class, 'dislike'])->name('api.likes.delete');

    Route::post('/therapies/{therapyId}/assist', [TherapyController::class, 'sendAssistanceRequest'])->name('therapies.assist');
    Route::get('/therapies', [TherapyController::class, 'show'])->name('api.therapies');
    Route::get('/therapies/{therapyId}', [TherapyController::class, 'getTherapy'])->name('api.therapies.get');
    Route::get('/user/therapies', [TherapyController::class, 'getUserTherapies'])->name('api.therapies.user');
    Route::get('/ward/therapies', [TherapyController::class, 'getWardTherapies'])->name('api.therapies.ward');
    Route::get('/counsellor/therapies', [TherapyController::class, 'getCounsellorTherapies'])->name('api.therapies.counsellor');
    Route::patch('/therapies/{therapyId}', [TherapyController::class, 'updateTherapy'])->name('api.therapies.update');
    Route::delete('/therapies/{therapyId}', [TherapyController::class, 'deleteTherapy'])->name('api.therapies.delete');
    Route::post('/therapies/{therapyId}', [TherapyController::class, 'endTherapy'])->name('api.therapies.end');
    Route::post('/therapies', [TherapyController::class, 'createTherapy'])->name('api.therapies.create');

    Route::post('/therapies/{therapyId}/sessions', [SessionController::class, 'createSession'])->name('api.sessions.create');
    Route::patch('/sessions/{sessionId}', [SessionController::class, 'updateSession'])->name('api.sessions.update');
    Route::delete('/sessions/{sessionId}', [SessionController::class, 'deleteSession'])->name('api.sessions.delete');
    Route::post('/sessions/{sessionId}/end', [SessionController::class, 'endSession'])->name('api.sessions.end');
    Route::post('/sessions/{sessionId}/in_session', [SessionController::class, 'getInSession'])->name('api.sessions.in_session');
    Route::post('/sessions/{sessionId}/fail', [SessionController::class, 'failSession'])->name('api.sessions.fail');
    Route::post('/sessions/{sessionId}/topics/set', [SessionController::class, 'setCurrentTopic'])->name('api.session.topic.set');
    Route::post('/sessions/{sessionId}/topics/unset', [SessionController::class, 'unsetCurrentTopic'])->name('api.session.topic.unset');
    Route::post('/sessions/{sessionId}/abandon', [SessionController::class, 'abandonSession'])->name('api.sessions.abandon');

    // SCRUM-212/TT-2.6a: a counsellor's own sessions aggregated across every Therapy/GroupTherapy
    // they're currently assigned to, date-range bounded for a calendar week/month view.
    Route::get('/counsellor/calendar/sessions', [SessionController::class, 'getCalendarSessions'])->name('api.sessions.calendar');

    // SCRUM-206/TT-2.5a: a client or counsellor proposing a session day/time for a Therapy --
    // creates a pending Request only, never a Session directly (that only happens on accept,
    // TT-2.5b). No web.php duplicate registration -- see SCRUM-200 for why that pattern is
    // avoided now.
    Route::post('/therapies/{therapyId}/schedule-proposals', [SessionScheduleProposalController::class, 'store'])->name('api.session_schedule_proposals.store')->middleware('throttle:30,1');
    // SCRUM-207/TT-2.5b: accept/reject reuse the existing generic requests.respond endpoint
    // (RespondToRequestAction's per-type dispatch already covers this type) -- only
    // counter-offer needs its own endpoint, mirroring requests.compensation_counter_offer.
    Route::post('/requests/{requestId}/schedule-counter-offer', [SessionScheduleProposalController::class, 'counterOffer'])->name('api.session_schedule_proposals.counter_offer')->middleware('throttle:30,1');

    // SCRUM-198/TT-2.2c: fetched/mutated via axios from the counsellor's already-loaded therapy
    // chat page (TherapyComponent.vue), same pattern as api.session.messages.get below -- never
    // broadcast, never reaches the client/participant side of the therapy.
    Route::get('/sessions/{sessionId}/notes', [SessionNoteController::class, 'index'])->name('api.session.notes.index');
    Route::post('/sessions/{sessionId}/notes', [SessionNoteController::class, 'store'])->name('api.session.notes.store');
    Route::patch('/sessions/notes/{noteId}', [SessionNoteController::class, 'update'])->name('api.session.notes.update');
    Route::delete('/sessions/notes/{noteId}', [SessionNoteController::class, 'destroy'])->name('api.session.notes.destroy');

    Route::post('/therapies/{therapyId}/topics', [TherapyTopicController::class, 'createTherapyTopic'])->name('api.topics.create');
    Route::patch('/topics/{topicId}', [TherapyTopicController::class, 'updateTherapyTopic'])->name('api.topics.update');
    Route::delete('/topics/{topicId}', [TherapyTopicController::class, 'deleteTherapyTopic'])->name('api.topics.delete');

    // SCRUM-74: these three were previously registered outside the auth:sanctum group, meaning
    // a fully unauthenticated request could read a private, non-public session/topic's full
    // message history (including confidential messages and files) -- moved behind auth here,
    // matching every other message-reading route in this group.
    Route::get('/sessions/{sessionId}/messages', [MessageController::class, 'getSessionMessages'])->name('api.session.messages.get');
    Route::get('/topics/{topicId}/messages', [MessageController::class, 'getTopicMessages'])->name('api.topic.messages.get');
    Route::get('/messages/{messageId}/replies', [MessageController::class, 'getMessageReplies'])->name('api.message.replies.get');
    Route::get('/messages/discussions/{discussionId}', [MessageController::class, 'getDiscussionMessages'])->name('api.discussion.messages.get');
    Route::post('/messages', [MessageController::class, 'createMessage'])->name('api.messages.create')->middleware('throttle:messages');
    Route::post('/messages/{messageId}', [MessageController::class, 'updateMessage'])->name('api.messages.update')->middleware('throttle:messages');
    Route::delete('/messages/{messageId}', [MessageController::class, 'deleteMessage'])->name('api.messages.delete')->middleware('throttle:messages');
    Route::delete('/messages/{messageId}/me', [MessageController::class, 'deleteMessageForMe'])->name('api.messages.delete.me')->middleware('throttle:messages');

    // SCRUM-202/TT-2.3a: a counsellor's own private note on one specific chat Message -- never
    // exposed to the client/other participants, see MessageNoteController's own comment on how
    // counsellor_id is always derived server-side, never accepted as input. Registered only here
    // (not also in web.php) -- the analogous SessionNote web.php registration turned out to be an
    // orphaned duplicate never actually used by the frontend (tracked as SCRUM-200); this avoids
    // repeating that mistake.
    Route::get('/messages/{messageId}/notes', [MessageNoteController::class, 'index'])->name('api.message.notes.index');
    Route::post('/messages/{messageId}/notes', [MessageNoteController::class, 'store'])->name('api.message.notes.store')->middleware('throttle:messages');
    Route::patch('/messages/notes/{noteId}', [MessageNoteController::class, 'update'])->name('api.message.notes.update')->middleware('throttle:messages');
    Route::delete('/messages/notes/{noteId}', [MessageNoteController::class, 'destroy'])->name('api.message.notes.destroy')->middleware('throttle:messages');

    Route::post('/discussions', [DiscussionController::class, 'createDiscussion'])->name('api.discussions.create');
    Route::post('/discussions/{discussionId}', [DiscussionController::class, 'updateDiscussion'])->name('api.discussions.update');
    Route::delete('/discussions/{discussionId}', [DiscussionController::class, 'deleteDiscussion'])->name('api.discussions.delete');
    Route::get('/discussions', [DiscussionController::class, 'getDiscussions'])->name('api.discussions');
    Route::get('/discussions/{discussionId}/counsellors', [DiscussionController::class, 'getDiscussionCounsellors'])->name('api.discussions.counsellors');
    Route::post('/discussions/{discussionId}/removecounsellor', [DiscussionController::class, 'removeCounsellor'])->name('api.discussions.removecounsellor');
    Route::post('/discussions/{discussionId}/request', [DiscussionController::class, 'sendCounsellorRequest'])->name('api.discussions.request');
    Route::post('/discussions/{discussionId}/in_session', [DiscussionController::class, 'getInDiscussion'])->name('api.discussions.in_session');
    Route::post('/discussions/{discussionId}/abandon', [DiscussionController::class, 'abandonDiscussion'])->name('api.discussions.abandon');
    Route::post('/discussions/{discussionId}/end', [DiscussionController::class, 'endDiscussion'])->name('api.discussions.end');

    Route::get('/group-therapies/{groupTherapyId}', [GroupTherapyController::class, 'getGroupTherapy'])->name('api.group.therapies.get');
    Route::get('/counsellor/group-therapies', [GroupTherapyController::class, 'getCounsellorTherapies'])->name('api.group.therapies.counsellor');
    Route::patch('/group-therapies/{groupTherapyId}', [GroupTherapyController::class, 'updateGroupTherapy'])->name('api.group.therapies.update');
    Route::delete('/group-therapies/{groupTherapyId}', [GroupTherapyController::class, 'deleteGroupTherapy'])->name('api.group.therapies.delete');
    Route::post('/group-therapies/{groupTherapyId}', [GroupTherapyController::class, 'endGroupTherapy'])->name('api.group.therapies.end');
    Route::post('/group-therapies', [GroupTherapyController::class, 'createGroupTherapy'])->name('group.therapies.create');
    Route::post('/group-therapies/{groupTherapyId}/join', [GroupTherapyController::class, 'joinGroupTherapy'])->name('api.group.therapies.join');
    Route::get('/user/group-therapies', [GroupTherapyController::class, 'getUserGroupTherapies'])->name('api.group.therapies.user');
    Route::get('/ward/group-therapies', [GroupTherapyController::class, 'getWardGroupTherapies'])->name('api.group.therapies.ward');
    Route::get('/counsellor/group-therapies', [GroupTherapyController::class, 'getCounsellorGroupTherapies'])->name('api.group.therapies.counsellor');

    Route::post('/counsellors', [CounsellorController::class, 'createCounsellor'])->name('counsellors.create');

    Route::post('/professions', [ProfessionController::class, 'createProfession'])->name('professions.create');

    Route::post('/religions', [ReligionController::class, 'createReligion'])->name('religions.create');

    Route::post('/languages', [LanguageController::class, 'createLanguage'])->name('languages.create');

    Route::post('/therapy-cases', [TherapyCaseController::class, 'createCase'])->name('therapy-cases.create');
});
