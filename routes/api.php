<?php

use App\Http\Controllers\AdministratorController;
use App\Http\Controllers\CounsellorController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\LicensingAuthorityController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ProfessionController;
use App\Http\Controllers\ReligionController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\TherapyCaseController;
use App\Http\Controllers\TherapyController;
use App\Http\Controllers\TherapyTopicController;
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

Route::get('/sessions/{sessionId}/messages', [MessageController::class, 'getSessionMessages'])->name('api.session.messages.get');
Route::get('/topics/{topicId}/messages', [MessageController::class, 'getTopicMessages'])->name('api.topic.messages.get');
Route::get('/messages/{messageId}/replies', [MessageController::class, 'getMessageReplies'])->name('api.message.replies.get');

Route::get('/therapy-cases', [TherapyCaseController::class, 'getCases'])->name('cases.get');
Route::get('/languages', [LanguageController::class, 'getLanguages'])->name('languages.get');
Route::get('/religions', [ReligionController::class, 'getReligions'])->name('religions.get');
Route::get('/professions', [ProfessionController::class, 'getProfessions'])->name('professions.get');

Route::middleware('auth:sanctum')->group(function () {
    
    Route::get('/administrator/verification/requests', [AdministratorController::class, 'getVerificationRequests'])->name('admin.verification.requests');
    Route::get('/administrator/counsellors', [AdministratorController::class, 'getCounsellors'])->name('admin.counsellors');
    Route::get('/administrator/counsellors/{counsellorId}/stats', [AdministratorController::class, 'getCounsellorStats'])->name('admin.counsellors.stats');
    
    Route::get('/requests/counsellors', [CounsellorController::class, 'getCounsellors'])->name('counsellors.request.get');
    
    Route::get('/licensing_authorities', [LicensingAuthorityController::class, 'getLicensingAuthorities'])->name('licensing_authorities');
    Route::post('/licensing_authorities', [LicensingAuthorityController::class, 'createLicensingAuthority'])->name('licensing_authorities.create');
    
    Route::get('/requests', [RequestController::class, 'getRequests'])->name('requests.get');
    Route::post('/requests/{requestId}', [RequestController::class, 'respond'])->name('requests.respond');

    
    Route::get('/therapies', [TherapyController::class, 'show'])->name('api.therapies');
    Route::get('/therapies/{therapyId}', [TherapyController::class, 'getTherapy'])->name('api.therapies.get');
    Route::patch('/therapies/{therapyId}', [TherapyController::class, 'updateTherapy'])->name('api.therapies.update');
    Route::delete('/therapies/{therapyId}', [TherapyController::class, 'deleteTherapy'])->name('api.therapies.delete');
    Route::post('/therapies/{therapyId}', [TherapyController::class, 'endTherapy'])->name('api.therapies.end');

    Route::post('/therapies/{therapyId}/sessions', [SessionController::class, 'createSession'])->name('api.sessions.create');
    Route::patch('/sessions/{sessionId}', [SessionController::class, 'updateSession'])->name('api.sessions.update');
    Route::delete('/sessions/{sessionId}', [SessionController::class, 'deleteSession'])->name('api.sessions.delete');
    Route::post('/sessions/{sessionId}/end', [SessionController::class, 'endSession'])->name('api.sessions.end');
    Route::post('/sessions/{sessionId}/fail', [SessionController::class, 'failSession'])->name('api.sessions.fail');
    Route::post('/sessions/{sessionId}/abandon', [SessionController::class, 'abandonSession'])->name('api.sessions.abandon');

    Route::post('/therapies/{therapyId}/topics', [TherapyTopicController::class, 'createTherapyTopic'])->name('api.topics.create');
    Route::patch('/topics/{topicId}', [TherapyTopicController::class, 'updateTherapyTopic'])->name('api.topics.update');
    Route::delete('/topics/{topicId}', [TherapyTopicController::class, 'deleteTherapyTopic'])->name('api.topics.delete');

    Route::get('/discussions/{discussionId}/messages', [MessageController::class, 'getDiscussionMessages'])->name('api.discussion.messages.get');
    Route::post('messages', [MessageController::class, 'createMessage'])->name('api.messages.create');
    Route::post('/messages/{messageId}', [MessageController::class, 'updateMessage'])->name('api.messages.update');
    Route::delete('/messages/{messageId}', [MessageController::class, 'deleteMessage'])->name('api.messages.delete');
    Route::delete('/messages/{messageId}/me', [MessageController::class, 'deleteMessageForMe'])->name('api.messages.delete.me');

    Route::post('/therapies/{therapyId}/assist', [TherapyController::class, 'sendAssistanceRequest'])->name('therapies.assist');
    Route::post('/therapies', [TherapyController::class, 'createTherapy'])->name('therapies.create');

    Route::post('/counsellors', [CounsellorController::class, 'createCounsellor'])->name('counsellors.create');

    Route::post('/professions', [ProfessionController::class, 'createProfession'])->name('professions.create');

    Route::post('/religions', [ReligionController::class, 'createReligion'])->name('religions.create');

    Route::post('/languages', [LanguageController::class, 'createLanguage'])->name('languages.create');

    Route::post('/therapy-cases', [TherapyCaseController::class, 'createCase'])->name('therapy-cases.create');
});
