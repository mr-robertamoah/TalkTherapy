<?php

namespace App\Actions\Transaction;

use App\Actions\Action;
use App\DTOs\TransactionDTO;
use App\Enums\TransactionStatusEnum;
use App\Enums\TransactionStatusSourceEnum;
use App\Exceptions\TransactionException;
use App\Models\Transaction;
use App\Services\Paystack\PaystackClient;
use Illuminate\Http\Client\RequestException;

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
        } catch (RequestException $exception) {
            throw new TransactionException('Unable to start the payment right now. Please try again shortly.', 502);
        }

        $transaction = Transaction::query()->create([
            'for_type' => $dto->for::class,
            'for_id' => $dto->for->id,
            'user_id' => $dto->user->id,
            'organization_id' => $dto->organization?->id,
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
