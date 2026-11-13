<?php

namespace App\Actions\Auth;

use App\Actions\Action;
use App\Jobs\StoreVisitationJob;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

// SCRUM-34b: signs the person in once every step has passed -- straight after the password, or after the two-factor
// code. A new session id, and the visit recorded.
class CompleteLoginAction extends Action
{
    public function execute(Request $request, User $user, bool $remember): void
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();

        StoreVisitationJob::dispatch($user, $request->ip());
    }
}
