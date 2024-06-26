<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\AdministratorController;
use App\Http\Controllers\CounsellorController;
use App\Http\Controllers\GroupTherapyController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LinkController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\TherapyController;
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


Route::get('/testing',function() {
    try {
        // AppService::new()->alertSuperAdminWithStatus();
        return 'done';
    } catch (Throwable $th) {
        ds($th);
    }
});


Route::get('/',[HomeController::class, 'goHome'])
    ->name('home');
Route::get('/about', AboutController::class)
    ->name('about');

Route::get('/therapies/{therapyId}', [TherapyController::class, 'getTherapy'])->name('therapies.get');
Route::get('/grouptherapies/{groupTherapyId}', [GroupTherapyController::class, 'getGroupTherapy'])->name('grouptherapies.get');

Route::get('/counsellor/{counsellorId}/verify-email/{hash}', [CounsellorController::class, 'verifyEmail'])
    ->middleware(['signed','throttle:6,1'])
    ->name('counsellor.verification.verify');
Route::get('/counsellor/{counsellorId}', [CounsellorController::class, 'show'])->name('counsellor.show');

Route::get('/posts/{postId}', [PostController::class, 'getPost'])->name('posts.get');

Route::middleware('auth')->group(function () {

    Route::get('/links/{uuid}', [LinkController::class, 'performAction'])->name('links.get');

    Route::get('/therapies', [TherapyController::class, 'show'])->name('therapies');

    Route::get('/administrator', [AdministratorController::class, 'show'])->name('administrator');

    Route::get('/therapies', [TherapyController::class, 'show'])->name('therapies');
    Route::patch('/therapies/{therapyId}', [TherapyController::class, 'updateTherapy'])->name('therapies.update');
    Route::delete('/therapies/{therapyId}', [TherapyController::class, 'deleteTherapy'])->name('therapies.delete');
    Route::post('/therapies/{therapyId}', [TherapyController::class, 'endTherapy'])->name('therapies.end');

    Route::post('/therapies/{therapyId}/sessions', [SessionController::class, 'createSession'])->name('sessions.create');
    Route::patch('/therapies/{therapyId}/sessions/{sessionId}', [SessionController::class, 'updateSession'])->name('sessions.update');
    Route::delete('/therapies/{therapyId}/sessions/{sessionId}', [SessionController::class, 'deleteSession'])->name('sessions.delete');
    
    Route::post('/sessions/{sessionId}/in_session', [SessionController::class, 'getInSession'])->name('sessions.in_session');
    Route::post('/sessions/{sessionId}/end', [SessionController::class, 'endSession'])->name('sessions.end');
    Route::post('/sessions/{sessionId}/fail', [SessionController::class, 'failSession'])->name('sessions.fail');
    Route::post('/sessions/{sessionId}/abandon', [SessionController::class, 'abandonSession'])->name('sessions.abandon');

    Route::get('/preferences', [PreferenceController::class, 'show'])->name('preferences');
    Route::post('/preferences', [PreferenceController::class, 'set'])->name('preferences.set');

    // Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');

    Route::post('/counsellor/{counsellorId}', [CounsellorController::class, 'updateCounsellor'])->name('counsellor.update');
    Route::post('/counsellor/{counsellorId}/verify', [CounsellorController::class, 'verifyCounsellor'])->name('counsellor.verify');
    Route::post('/counsellor/{counsellorId}/verify-email', [CounsellorController::class, 'sendVerificationEmail'])->name('counsellor.email.verification');

    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
