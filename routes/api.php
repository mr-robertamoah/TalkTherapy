<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\AdministratorController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CounsellorController;
use App\Http\Controllers\DiscussionController;
use App\Http\Controllers\HowToController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\LicensingAuthorityController;
use App\Http\Controllers\LikeController;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfessionController;
use App\Http\Controllers\ReligionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\TestimonialController;
use App\Http\Controllers\TherapyCaseController;
use App\Http\Controllers\TherapyController;
use App\Http\Controllers\TherapyTopicController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
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
Route::get('/counsellors/random', [CounsellorController::class, 'getRandomCounsellors'])->name('api.counsellors.random');
Route::get('/counsellors', [CounsellorController::class, 'getCounsellors'])->name('api.counsellors');

Route::get('/sessions/{sessionId}/messages', [MessageController::class, 'getSessionMessages'])->name('api.session.messages.get');
Route::get('/topics/{topicId}/messages', [MessageController::class, 'getTopicMessages'])->name('api.topic.messages.get');
Route::get('/messages/{messageId}/replies', [MessageController::class, 'getMessageReplies'])->name('api.message.replies.get');

Route::get('/testimonials', [TestimonialController::class, 'getTestimonials'])->name('api.testimonials');

Route::post('/contacts', [ContactController::class, 'createContact'])->name('api.contacts.create');

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

    Route::get('/users', [UserController::class, 'getUsers'])->name('api.users');
    Route::get('/users/guardianship', [UserController::class, 'getGuardianship'])->name('api.users.guardianship');
    Route::post('/users/{userId}/guardianship', [UserController::class, 'sendGuardianshipRequest'])->name('api.users.guardianshiprequest');
    Route::delete('/guardianship/{guardianshipId}', [UserController::class, 'deleteGuardianship'])->name('api.guardianship.delete');

    Route::get('/administrator/verification/requests', [AdministratorController::class, 'getVerificationRequests'])->name('admin.verification.requests');
    Route::get('/administrator/counsellors', [AdministratorController::class, 'getCounsellors'])->name('admin.counsellors');
    Route::get('/administrator/counsellors/{counsellorId}/stats', [AdministratorController::class, 'getCounsellorStats'])->name('admin.counsellors.stats');
    Route::get('/administrator/users', [AdministratorController::class, 'getUsers'])->name('admin.users');
    Route::post('/administrator/users/{userId}', [AdministratorController::class, 'updateUser'])->name('admin.users.update');
    Route::delete('/administrator/users/{userId}', [AdministratorController::class, 'deleteUser'])->name('admin.users.delete');
    
    Route::post('/administrator/how-tos', [HowToController::class, 'createHowTo'])->name('admin.how-tos.create');
    Route::post('/administrator/how-tos/{howToId}', [HowToController::class, 'updateHowTo'])->name('admin.how-tos.update');
    Route::delete('/administrator/how-tos/{howToId}', [HowToController::class, 'deleteHowTo'])->name('admin.how-tos.delete');

    Route::get('/requests/counsellors', [CounsellorController::class, 'getRequestCounsellors'])->name('counsellors.request.get');

    Route::post('/alerts', [AlertController::class, 'waitingForAlert'])->name('alert.wait');
    
    Route::get('/licensing_authorities', [LicensingAuthorityController::class, 'getLicensingAuthorities'])->name('licensing_authorities');
    Route::post('/licensing_authorities', [LicensingAuthorityController::class, 'createLicensingAuthority'])->name('licensing_authorities.create');
    
    Route::get('/requests', [RequestController::class, 'getRequests'])->name('requests.get');
    Route::post('/requests/{requestId}', [RequestController::class, 'respond'])->name('requests.respond');
    
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
    
    Route::get('/therapies', [TherapyController::class, 'show'])->name('api.therapies');
    Route::get('/therapies/{therapyId}', [TherapyController::class, 'getTherapy'])->name('api.therapies.get');
    Route::get('/user/therapies', [TherapyController::class, 'getUserTherapies'])->name('api.therapies.user');
    Route::get('/ward/therapies', [TherapyController::class, 'getWardTherapies'])->name('api.therapies.ward');
    Route::get('/counsellor/therapies', [TherapyController::class, 'getCounsellorTherapies'])->name('api.therapies.counsellor');
    Route::patch('/therapies/{therapyId}', [TherapyController::class, 'updateTherapy'])->name('api.therapies.update');
    Route::delete('/therapies/{therapyId}', [TherapyController::class, 'deleteTherapy'])->name('api.therapies.delete');
    Route::post('/therapies/{therapyId}', [TherapyController::class, 'endTherapy'])->name('api.therapies.end');
    
    Route::post('/therapies/{therapyId}/sessions', [SessionController::class, 'createSession'])->name('api.sessions.create');
    Route::patch('/sessions/{sessionId}', [SessionController::class, 'updateSession'])->name('api.sessions.update');
    Route::delete('/sessions/{sessionId}', [SessionController::class, 'deleteSession'])->name('api.sessions.delete');
    Route::post('/sessions/{sessionId}/end', [SessionController::class, 'endSession'])->name('api.sessions.end');
    Route::post('/sessions/{sessionId}/in_session', [SessionController::class, 'getInSession'])->name('api.sessions.in_session');
    Route::post('/sessions/{sessionId}/fail', [SessionController::class, 'failSession'])->name('api.sessions.fail');
    Route::post('/sessions/{sessionId}/topics/set', [SessionController::class, 'setCurrentTopic'])->name('api.session.topic.set');
    Route::post('/sessions/{sessionId}/topics/unset', [SessionController::class, 'unsetCurrentTopic'])->name('api.session.topic.unset');
    Route::post('/sessions/{sessionId}/abandon', [SessionController::class, 'abandonSession'])->name('api.sessions.abandon');

    Route::post('/therapies/{therapyId}/topics', [TherapyTopicController::class, 'createTherapyTopic'])->name('api.topics.create');
    Route::patch('/topics/{topicId}', [TherapyTopicController::class, 'updateTherapyTopic'])->name('api.topics.update');
    Route::delete('/topics/{topicId}', [TherapyTopicController::class, 'deleteTherapyTopic'])->name('api.topics.delete');

    Route::get('/messages/discussions/{discussionId}', [MessageController::class, 'getDiscussionMessages'])->name('api.discussion.messages.get');
    Route::post('/messages', [MessageController::class, 'createMessage'])->name('api.messages.create');
    Route::post('/messages/{messageId}', [MessageController::class, 'updateMessage'])->name('api.messages.update');
    Route::delete('/messages/{messageId}', [MessageController::class, 'deleteMessage'])->name('api.messages.delete');
    Route::delete('/messages/{messageId}/me', [MessageController::class, 'deleteMessageForMe'])->name('api.messages.delete.me');

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

    Route::post('/therapies/{therapyId}/assist', [TherapyController::class, 'sendAssistanceRequest'])->name('therapies.assist');
    Route::post('/therapies', [TherapyController::class, 'createTherapy'])->name('therapies.create');

    Route::post('/counsellors', [CounsellorController::class, 'createCounsellor'])->name('counsellors.create');

    Route::post('/professions', [ProfessionController::class, 'createProfession'])->name('professions.create');

    Route::post('/religions', [ReligionController::class, 'createReligion'])->name('religions.create');

    Route::post('/languages', [LanguageController::class, 'createLanguage'])->name('languages.create');

    Route::post('/therapy-cases', [TherapyCaseController::class, 'createCase'])->name('therapy-cases.create');
});
