<?php

namespace App\Http\Controllers;

use App\Actions\Notification\ListNotificationsAction;
use App\Actions\Notification\MarkNotificationsReadAction;
use App\Actions\Notification\SetNotificationRetentionAction;
use App\Enums\NotificationCategoryEnum;
use App\Support\NotificationCenter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

// SCRUM-39: the signed-in person's notification center (JSON). Not open to a suspended account
// (EnsureAccountIsNotSuspended) -- their suspended page explains things.
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(ListNotificationsAction::new()->execute(
            $request->user(), is_string($request->query('cursor')) ? $request->query('cursor') : null,
        ));
    }

    public function count(Request $request)
    {
        return response()->json(['unread' => NotificationCenter::unreadCount($request->user())]);
    }

    public function read(Request $request, string $notification)
    {
        abort_unless(MarkNotificationsReadAction::new()->one($request->user(), $notification), 404);

        return response()->json(['status' => true]);
    }

    public function readAll(Request $request)
    {
        return response()->json(['marked' => MarkNotificationsReadAction::new()->all($request->user())]);
    }

    // SCRUM-39 (TT-5.4d): from the profile page -- one of the options, or none for the platform default.
    public function retention(Request $request)
    {
        $validated = $request->validate([
            'days' => ['present', 'nullable', 'integer', Rule::in(config('notifications.retention_options'))],
        ]);

        SetNotificationRetentionAction::new()->execute($request->user(), $validated['days'] ?? null);

        // The form says "Saved." itself (review: not a toast as well).
        return back();
    }

    // SCRUM-40 (TT-5.5): from the profile page -- for each category, email and in-app on or off. Only what is off is
    // kept; always-on notifications aren't categories, so can't be switched off here.
    public function channels(Request $request)
    {
        $categories = array_map(fn (NotificationCategoryEnum $c) => $c->value, NotificationCategoryEnum::cases());

        $validated = $request->validate([
            'channels' => ['present', 'array:'.implode(',', $categories)],
            'channels.*' => ['array:mail,database'],
            'channels.*.mail' => ['required', 'boolean'],
            'channels.*.database' => ['required', 'boolean'],
        ]);

        // The form always sends every category, so this replaces the choices; only what is off is kept.
        $off = collect($validated['channels'])
            ->map(fn (array $channels) => array_fill_keys(array_keys(array_filter($channels, fn (bool $on) => ! $on)), false))
            ->filter()
            ->all();

        $request->user()->forceFill(['notification_channels' => $off ?: null])->save();

        return back();
    }
}
