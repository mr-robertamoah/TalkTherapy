<?php

use App\Http\Controllers\AdministratorController;
use App\Http\Controllers\CounsellorController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TherapyController;
use Illuminate\Foundation\Application;
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

Route::get('/',[HomeController::class, 'goHome'])
    // ->middleware(['auth', 'verified'])
    ->name('home');

Route::get('/counsellor/{counsellorId}', [CounsellorController::class, 'show'])->name('counsellor.show');

Route::middleware('auth')->group(function () {
    Route::get('/therapies', [TherapyController::class, 'index'])->name('therapies');

    Route::get('/administrator', [AdministratorController::class, 'show'])->name('administrator');

    Route::get('/therapies', [TherapyController::class, 'show'])->name('therapies');
    Route::get('/therapies/{therapyId}', [TherapyController::class, 'getTherapy'])->name('therapies.get');

    Route::get('/preferences', [PreferenceController::class, 'show'])->name('preferences');
    Route::post('/preferences', [PreferenceController::class, 'set'])->name('preferences.set');

    // Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::post('/counsellor/{counsellorId}', [CounsellorController::class, 'updateCounsellor'])->name('counsellor.update');
    Route::post('/counsellor/{counsellorId}/verify', [CounsellorController::class, 'verifyCounsellor'])->name('counsellor.verify');
    Route::post('/counsellor/{counsellorId}/verify-email', [CounsellorController::class, 'verifyCounsellorEmail'])->name('counsellor.email.verification');

    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
