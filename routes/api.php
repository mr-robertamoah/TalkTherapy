<?php

use App\Http\Controllers\CounsellorController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\ProfessionController;
use App\Http\Controllers\ReligionController;
use App\Http\Controllers\TherapyCaseController;
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

    Route::post('/counsellors', [CounsellorController::class, 'createCounsellor'])->name('counsellors.create');

    Route::post('/professions', [ProfessionController::class, 'createProfession'])->name('professions.create');

    Route::post('/religions', [ReligionController::class, 'createReligion'])->name('religions.create');

    Route::post('/languages', [LanguageController::class, 'createLanguage'])->name('languages.create');

    Route::post('/therapy-cases', [TherapyCaseController::class, 'createCase'])->name('therapy-cases.create');
});
