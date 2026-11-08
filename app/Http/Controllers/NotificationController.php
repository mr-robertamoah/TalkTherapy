<?php

namespace App\Http\Controllers;

use App\Actions\Notification\ListNotificationsAction;
use App\Actions\Notification\MarkNotificationsReadAction;
use App\Support\NotificationCenter;
use Illuminate\Http\Request;

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
}
