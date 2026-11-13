<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\DTOs\TransactionDTO;
use App\Enums\TransactionStatusEnum;
use App\Enums\TransactionStatusSourceEnum;
use App\Exceptions\TransactionException;
use App\Models\Transaction;
use App\Services\Paystack\PaystackClient;
use Illuminate\Http\Client\HttpClientException;

// TT-7.3b-c/SCRUM-234, SCRUM-420: TransactionService::initiateCharge() routes every org-financed
// charge, groups included, through ChargeOrganizationForModelAction (the org's own saved
// instrument, at actual cost) -- this action is the personal-pay path only.
class InitiatePaystackChargeAction extends Action
{
    /**
     * @return array{transaction: Transaction, authorizationUrl: string}
     */
    public function execute(TransactionDTO $dto): array
    {
        $payable = GetPayableAmountAction::new()->execute($dto->for);

        // Paystack expects the smallest currency unit (e.g. pesewas, not cedis), never a float.
        $minorUnitsAmount = (int) round($payable['amount'] * 100);

        try {
            $response = PaystackClient::new()->initializeTransaction([
                'email' => $dto->user->email,
                'amount' => $minorUnitsAmount,
                'currency' => $payable['currency'],
                'callback_url' => $dto->callbackUrl,
            ]);
        } catch (HttpClientException $exception) { // SCRUM-272: an error response or no response at all
            throw new TransactionException('Unable to start the payment right now. Please try again shortly.', 502, $exception);
        }

        $transaction = Transaction::query()->create([
            'for_type' => $dto->for::class,
            'for_id' => $dto->for->id,
            'user_id' => $dto->user->id,
            'reference' => $response['data']['reference'],
            'amount' => $minorUnitsAmount,
            'currency' => $payable['currency'],
            'status' => TransactionStatusEnum::pending->value,
        ]);

        $transaction->statusHistories()->create([
            'status' => TransactionStatusEnum::pending->value,
            'source' => TransactionStatusSourceEnum::initiate->value,
            'message' => 'Charge initiated.',
        ]);

        return [
            'transaction' => $transaction,
            'authorizationUrl' => $response['data']['authorization_url'],
        ];
    }
}
