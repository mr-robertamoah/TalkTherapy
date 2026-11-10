<?php

namespace App\Actions\Admin;

use App\Actions\Action;
use App\Models\User;
use App\Support\AdminNotifyResult;
use Closure;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as Notifier;
use Throwable;

// SCRUM-32 (shared with SCRUM-33's help escalation): tells every administrator (or every super
// administrator) who isn't involved in something. $involves(User $admin): bool decides who is left
// out. Best effort: anything that fails here is reported, never thrown -- the action it follows has
// already happened. Nobody left to tell is logged as critical, never dropped silently, and -- when a
// $fallbackMailbox is given (SCRUM-32 TT-4.3b) -- that mailbox is sent the same notification by email
// (the notification must accept an address as well as an account: AnonymousNotifiable). The result says
// what really happened (reviews: never record a fallback that didn't go out).
class NotifyUninvolvedAdminsAction extends Action
{
    public function execute(Closure $involves, Notification $notification, bool $superOnly = false, string $about = 'an alert', ?string $fallbackMailbox = null): AdminNotifyResult
    {
        try {
            $admins = self::recipients($involves, $superOnly);

            if ($admins->isNotEmpty()) {
                Notifier::send($admins, $notification);

                return new AdminNotifyResult(told: $admins->count());
            }

            if ($fallbackMailbox) {
                Notifier::route('mail', $fallbackMailbox)->notify($notification);
            }
            Log::critical("Nobody could be told of {$about}: every ".($superOnly ? 'super ' : '').'administrator is involved, or there are none.'
                .($fallbackMailbox ? ' The fallback mailbox was emailed.' : ' No fallback mailbox is set.'));

            return new AdminNotifyResult(fellBack: (bool) $fallbackMailbox);
        } catch (Throwable $th) {
            report($th);

            return new AdminNotifyResult(failed: true);
        }
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
