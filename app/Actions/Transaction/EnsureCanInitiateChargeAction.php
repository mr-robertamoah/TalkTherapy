<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\DTOs\TransactionDTO;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TransactionStatusEnum;
use App\Exceptions\TransactionException;
use App\Models\Session;

class EnsureCanInitiateChargeAction extends Action
{
    public function execute(TransactionDTO $dto)
    {
        // Who is allowed to pay is EnsureCanPayForModelAction's job, which runs before this --
        // by the time this runs, $dto->user is already known to be a legitimate payer for
        // $dto->for. This action is only about whether the item itself is payable right now.
        $payable = GetPayableAmountAction::new()->execute($dto->for);

        if ($payable['paymentType'] !== TherapyPaymentTypeEnum::paid->value) {
            throw new TransactionException('There is nothing to pay for here.', 422);
        }

        if (is_null($payable['amount']) || is_null($payable['currency'])) {
            throw new TransactionException('This does not have a valid price set yet.', 422);
        }

        // SCRUM-153 (TT-7.2a): defense-in-depth -- request-level validation already restricts
        // currency to config('currencies.supported') at Therapy/GroupTherapy creation/update
        // time, but this re-checks the value actually stored on payment_data before it ever
        // reaches Paystack, in case it was set through some other path (a legacy row, a direct
        // write, a future bypass of the request classes). config('currencies.supported') is
        // already normalized to uppercase, so only the stored value needs normalizing here.
        if (! in_array(strtoupper($payable['currency']), config('currencies.supported'))) {
            throw new TransactionException('This currency is not currently supported.', 422);
        }

        // A PER_THERAPY setup is charged once, for the Therapy/GroupTherapy itself -- never for
        // one of its individual Sessions, which would double the charge. A PER_SESSION setup is
        // the other way round: each Session is its own charge, the Therapy/GroupTherapy itself
        // is never charged directly. See EnsureTherapyDataIsValidAction for where `per` itself
        // is validated at creation time; this only enforces that the charge target matches it.
        $isSessionFor = $dto->for instanceof Session;

        if ($payable['per'] === TherapyPerPaymentEnum::therapy->value && $isSessionFor) {
            throw new TransactionException('This therapy is paid for as a whole, not per session.', 422);
        }

        if ($payable['per'] === TherapyPerPaymentEnum::session->value && ! $isSessionFor) {
            throw new TransactionException('This therapy is paid for per session, not as a whole.', 422);
        }

        if (
            $dto->for->transactions()
                ->where('status', TransactionStatusEnum::success->value)
                ->exists()
        ) {
            throw new TransactionException('This has already been paid for.', 422);
        }
    }
}
