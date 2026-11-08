<?php

namespace App\Actions\Notification;

use App\Actions\Action;
use App\Models\DatabaseNotification;
use App\Models\User;
use App\Support\NotificationCenter;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Str;

// SCRUM-39: a page of the viewer's notifications, newest first -- a cursor (not page numbers), so "Show
// more" neither repeats nor skips one when new ones arrive meanwhile.
class ListNotificationsAction extends Action
{
    public const PER_PAGE = 15;

    public function execute(User $viewer, ?string $cursor = null): array
    {
        $page = NotificationCenter::query($viewer)
            ->orderByDesc('created_at')->orderByDesc('id')
            // Never named "cursor": with no (valid) cursor, Laravel would read the raw ?cursor= itself.
            ->cursorPaginate(self::PER_PAGE, ['id', 'type', 'notifiable_type', 'data', 'read_at', 'created_at'], 'notifications_cursor', self::cursor($cursor));

        return [
            'notifications' => collect($page->items())->map(fn (DatabaseNotification $notification) => NotificationCenter::present($notification))->all(),
            'nextCursor' => $page->nextCursor()?->encode(),
        ];
    }

    // A cursor this list made, or none (the first page): a tampered one is never an error (security review).
    private static function cursor(?string $encoded): ?Cursor
    {
        $cursor = $encoded === null ? null : rescue(fn () => Cursor::fromEncoded($encoded), null, report: false);
        if (! $cursor instanceof Cursor) {
            return null;
        }

        $parameters = $cursor->toArray();
        $valid = is_bool($parameters['_pointsToNextItems'] ?? null)
            && is_string($parameters['created_at'] ?? null) && strtotime($parameters['created_at']) !== false
            && is_string($parameters['id'] ?? null) && Str::isUuid($parameters['id'])
            && count($parameters) === 3;

        return $valid ? $cursor : null;
    }
}
