<?php

namespace App\Actions\Admin;

use App\Actions\Action;
use App\Models\User;
use Closure;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as Notifier;

// SCRUM-32 (shared with SCRUM-33's help escalation): tells every administrator (or every super
// administrator) who isn't involved in something. $involves(User $admin): bool decides who is left
// out. Best effort: anything that fails here is reported, never thrown -- the action it follows has
// already happened. Nobody left to tell is logged as critical, never dropped silently (TT-4.3b adds a
// fallback mailbox). Returns how many were told.
class NotifyUninvolvedAdminsAction extends Action
{
    public function execute(Closure $involves, Notification $notification, bool $superOnly = false, string $about = 'an alert'): int
    {
        return rescue(function () use ($involves, $notification, $superOnly, $about) {
            $admins = self::recipients($involves, $superOnly);

            if ($admins->isEmpty()) {
                Log::critical("Nobody could be told of {$about}: every ".($superOnly ? 'super ' : '').'administrator is involved, or there are none.');

                return 0;
            }

            Notifier::send($admins, $notification);

            return $admins->count();
        }, 0, report: true);
    }

    // The administrators (never one whose account is suspended -- User::scopeWhereAdmin) not involved.
    /** @return Collection<int, User> */
    public static function recipients(Closure $involves, bool $superOnly = false): Collection
    {
        return User::query()->whereAdmin()->when($superOnly, fn ($query) => $query->whereSuperAdmin())->get()
            ->reject(fn (User $admin) => $involves($admin))
            ->values();
    }
}
