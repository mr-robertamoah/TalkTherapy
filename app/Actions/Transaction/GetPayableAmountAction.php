<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\Enums\SessionTypeEnum;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;

class GetPayableAmountAction extends Action
{
    // SCRUM-154 (TT-7.2b): CounsellorPricing (a counsellor's own listed, informational rate) is
    // strictly display-only and must NEVER be read here or anywhere else in app/Actions/Transaction/
    // -- the amount actually charged always comes from what the client proposed at booking time
    // (Therapy/GroupTherapy.payment_data), set via CreateTherapyRequest/CreateGroupTherapyRequest,
    // never from what a counsellor lists as their preferred rate.
    /**
     * @return array{paymentType: ?string, per: ?string, amount: ?float, currency: ?string}
     */
    public function execute(Therapy|GroupTherapy|Session $for): array
    {
        // A Session being charged (the PER_SESSION case) has no payment_data of its own -- its
        // price comes from the Therapy/GroupTherapy it belongs to, using inPersonAmount instead
        // of amount when the session itself is in-person.
        if ($for instanceof Session) {
            $paymentData = $for->for?->payment_data ?? [];

            return [
                'paymentType' => $for->payment_type,
                'per' => $paymentData['per'] ?? null,
                'amount' => $for->type === SessionTypeEnum::in_person->value
                    ? ($paymentData['inPersonAmount'] ?? $paymentData['amount'] ?? null)
                    : ($paymentData['amount'] ?? null),
                'currency' => $paymentData['currency'] ?? null,
            ];
        }

        $paymentData = $for->payment_data ?? [];

        return [
            'paymentType' => $for->payment_type,
            'per' => $paymentData['per'] ?? null,
            'amount' => $paymentData['amount'] ?? null,
            'currency' => $paymentData['currency'] ?? null,
        ];
    }
}
