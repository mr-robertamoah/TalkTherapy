<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\DTOs\OrganizationPaymentInstrumentDTO;
use App\Enums\TransactionStatusEnum;
use App\Enums\TransactionStatusSourceEnum;
use App\Exceptions\OrganizationException;
use App\Models\Transaction;
use App\Services\Paystack\PaystackClient;
use App\Services\SettingsService;
use Illuminate\Http\Client\RequestException;

// TT-7.3b-a/SCRUM-231: Paystack has no "just verify this card" call -- registering a reusable
// payment instrument means actually running one small, nominal charge through it via the SAME
// checkout flow InitiatePaystackChargeAction already uses (not reused directly: that action is
// coupled to GetPayableAmountAction, which expects a Therapy/Session/GroupTherapy "for" with its
// own payment_data, not an Organization). CaptureOrganizationPaymentInstrumentAction (invoked
// from RecordTransactionStatusAction, the existing single choke point for a transaction reaching
// SUCCESS) does the actual persistence once this charge is verified -- this action only starts it.
class InitiateOrganizationPaymentInstrumentRegistrationAction extends Action
{
    /**
     * @return array{transaction: Transaction, authorizationUrl: string}
     */
    public function execute(OrganizationPaymentInstrumentDTO $dto): array
    {
        EnsureCanRegisterOrganizationPaymentInstrumentAction::new()->execute($dto);

        $currency = strtoupper((string) $dto->currency);
        $minorUnitsAmount = SettingsService::new()->getOrganizationPaymentInstrumentVerificationAmount($currency);

        if ($minorUnitsAmount < 1) {
            throw new OrganizationException('Payment-method verification is not available in this currency right now.', 422);
        }

        try {
            $response = PaystackClient::new()->initializeTransaction([
                'email' => $dto->user->email,
                'amount' => $minorUnitsAmount,
                'currency' => $currency,
                'callback_url' => $dto->callbackUrl,
            ]);
        } catch (RequestException $exception) {
            throw new OrganizationException('Unable to start payment-method verification right now. Please try again shortly.', 502);
        }

        $transaction = Transaction::query()->create([
            'for_type' => $dto->organization::class,
            'for_id' => $dto->organization->id,
            'user_id' => $dto->user->id,
            'reference' => $response['data']['reference'],
            'amount' => $minorUnitsAmount,
            'currency' => $currency,
            'status' => TransactionStatusEnum::pending->value,
        ]);

        $transaction->statusHistories()->create([
            'status' => TransactionStatusEnum::pending->value,
            'source' => TransactionStatusSourceEnum::initiate->value,
            'message' => 'Payment-method verification charge initiated.',
        ]);

        return [
            'transaction' => $transaction,
            'authorizationUrl' => $response['data']['authorization_url'],
        ];
    }
}
