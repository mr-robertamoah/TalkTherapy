<?php

namespace App\Support;

use App\Enums\RefundStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\Session;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;

// TT-7.11b/SCRUM-471: which payments stand behind a session, for its star. A session is covered by a successful payment
// for it (per session) or for its therapy or group (per therapy). A payment stops counting once it has a successful
// full refund. Retainer settlements (a payment for an OrganizationInvoice) are not looked at here -- a session is held
// long before its invoice is settled.
final class SessionPayments
{
    // Held after its payment was refunded: it had at least one covering payment and none is left standing. A session
    // nobody paid for (free, or covered by an organisation's retainer) is not refunded -- free sessions earn stars too.
    public static function refunded(Session $session): bool
    {
        $covering = self::covering($session);

        return (clone $covering)->exists() && ! self::standing($covering)->exists();
    }

    // Another payment for the same thing as $transaction that still stands -- so refunding $transaction (one member of
    // a group) leaves what it paid for paid.
    public static function anotherStands(Transaction $transaction): bool
    {
        return self::standing(
            self::successful()
                ->where('for_type', $transaction->for_type)
                ->where('for_id', $transaction->for_id)
                ->whereKeyNot($transaction->id)
        )->exists();
    }

    // A payment with a successful refund of at least its amount is no longer standing.
    public static function standing(Builder $query): Builder
    {
        return $query->whereDoesntHave('refunds', fn (Builder $q) => $q
            ->where('status', RefundStatusEnum::success->value)
            ->whereColumn('refunds.amount', '>=', 'transactions.amount'));
    }

    private static function covering(Session $session): Builder
    {
        return self::successful()->where(fn (Builder $q) => $q
            ->where(fn (Builder $q) => $q->where('for_type', $session->getMorphClass())->where('for_id', $session->id))
            ->orWhere(fn (Builder $q) => $q->where('for_type', $session->for_type)->where('for_id', $session->for_id)));
    }

    private static function successful(): Builder
    {
        return Transaction::query()->where('status', TransactionStatusEnum::success->value);
    }
}
