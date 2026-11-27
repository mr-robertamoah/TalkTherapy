<?php

namespace App\Http\Controllers;

use App\Support\Locales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;

// SCRUM-479 (TT-9.3a): choosing the language the app is shown in -- anyone, signed in or not. This browser remembers
// it (tt_locale), and a signed-in account keeps it too (users.locale), so it follows them to another device.
class LocaleController extends Controller
{
    public function update(Request $request): JsonResponse|RedirectResponse
    {
        $locale = $request->validate([
            'locale' => ['required', 'string', Rule::in(Locales::enabled())],
        ])['locale'];

        $request->user()?->forceFill(['locale' => $locale])->save();
        Locales::remember($locale);
        App::setLocale($locale);

        return $this->successResponse($request, ['locale' => $locale], back());
    }
}
