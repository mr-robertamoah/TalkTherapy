<?php

use App\Http\Controllers\AdministratorController;
use App\Http\Controllers\CounsellorController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\LicensingAuthorityController;
use App\Http\Controllers\ProfessionController;
use App\Http\Controllers\ReligionController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\TherapyCaseController;
use App\Http\Controllers\TherapyController;
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

Route::get('/therapy-cases', [TherapyCaseController::class, 'getCases'])->name('cases.get');
Route::get('/languages', [LanguageController::class, 'getLanguages'])->name('languages.get');
Route::get('/religions', [ReligionController::class, 'getReligions'])->name('religions.get');
Route::get('/professions', [ProfessionController::class, 'getProfessions'])->name('professions.get');

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/administrator/verification/requests', [AdministratorController::class, 'getVerificationRequests'])->name('admin.verification.requests');
    Route::get('/administrator/counsellors', [AdministratorController::class, 'getCounsellors'])->name('admin.counsellors');
    Route::get('/administrator/counsellors/{counsellorId}/stats', [AdministratorController::class, 'getCounsellorStats'])->name('admin.counsellors.stats');
    
    Route::get('/licensing_authorities', [LicensingAuthorityController::class, 'getLicensingAuthorities'])->name('licensing_authorities');
    Route::post('/licensing_authorities', [LicensingAuthorityController::class, 'createLicensingAuthority'])->name('licensing_authorities.create');
    
    Route::get('/requests', [RequestController::class, 'getRequests'])->name('requests.get');
    Route::post('/requests/{requestId}', [RequestController::class, 'respond'])->name('requests.respond');

    Route::post('/therapies', [TherapyController::class, 'createTherapy'])->name('therapies.create');

    Route::post('/counsellors', [CounsellorController::class, 'createCounsellor'])->name('counsellors.create');

    Route::post('/professions', [ProfessionController::class, 'createProfession'])->name('professions.create');

    Route::post('/religions', [ReligionController::class, 'createReligion'])->name('religions.create');

    Route::post('/languages', [LanguageController::class, 'createLanguage'])->name('languages.create');

    Route::post('/therapy-cases', [TherapyCaseController::class, 'createCase'])->name('therapy-cases.create');
});
