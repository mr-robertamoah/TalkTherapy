<?php

namespace App\Http\Controllers;

use App\Actions\Suspension\SubmitSuspensionAppealAction;
use App\Models\Suspension;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

// SCRUM-359: the suspended person's own page -- what the suspension covers, until when, the reason
// written for them, and their one appeal. Reachable while the account is suspended.
class SuspensionController extends Controller
{
    public function show(Request $request)
    {
        $suspension = $request->user()->anyActiveSuspension();

        if (! $suspension) {
            return Redirect::route('home');
        }

        return Inertia::render('Suspended', [
            'suspension' => $suspension->presentForPerson(),
        ]);
    }

    public function appeal(Request $request, int $suspension)
    {
        try {
            $validated = $request->validate(['appeal' => ['required', 'string', 'max:3000']]);

            SubmitSuspensionAppealAction::new()->execute($request->user(), Suspension::find($suspension), $validated['appeal']);

            return Redirect::back()->with('message', 'Your appeal has been sent. You will be emailed the decision.');
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $th) {
            return Redirect::back()->withErrors(['appeal' => $this->messageFor($th, $this->statusFor($th))]);
        }
    }
}
