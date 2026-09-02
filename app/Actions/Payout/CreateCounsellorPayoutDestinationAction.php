<?php

namespace App\Actions\Payout;

use App\Actions\Action;
use App\DTOs\PayoutDestinationDTO;
use App\Exceptions\PayoutException;
use App\Models\CounsellorPayoutAccount;
use App\Notifications\PayoutDestinationChangedNotification;
use App\Services\Paystack\PaystackClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;

// TT-7.6a/SCRUM-225: verify-then-register, mirroring InitiatePaystackChargeAction's shape (one
// action owning both the Paystack call(s) and the resulting persistence) -- not split across
// several thinner actions, consistent with how this codebase already handles a single external
// charge-initiation call plus its DB write.
class CreateCounsellorPayoutDestinationAction extends Action
{
    public function execute(PayoutDestinationDTO $dto): CounsellorPayoutAccount
    {
        // Defense in depth (security review): this action's own safety must not depend entirely
        // on every future caller remembering to route through PayoutService::onboardDestination()
        // first -- re-running the same check here is cheap and makes this action safe even if a
        // later ticket (e.g. TT-7.6d's controller) ever calls it directly by mistake.
        EnsureCanOnboardPayoutDestinationAction::new()->execute($dto);

        $counsellor = $dto->user->counsellor;

        try {
            $resolved = PaystackClient::new()->resolveAccountNumber($dto->accountNumber, $dto->bankCode);
        } catch (RequestException $exception) {
            throw new PayoutException('Could not verify that account number. Please double-check the details and try again.', 422);
        }

        $accountName = $resolved['data']['account_name'] ?? null;

        if (! $accountName) {
            throw new PayoutException('Could not verify that account number. Please double-check the details and try again.', 422);
        }

        try {
            $recipient = PaystackClient::new()->createTransferRecipient([
                'type' => $dto->type->paystackValue(),
                'name' => $accountName,
                'account_number' => $dto->accountNumber,
                'bank_code' => $dto->bankCode,
                'currency' => $dto->currency,
            ]);
        } catch (RequestException $exception) {
            throw new PayoutException('Unable to set up this payout destination right now. Please try again shortly.', 502);
        }

        $recipientCode = $recipient['data']['recipient_code'] ?? null;

        if (! $recipientCode) {
            throw new PayoutException('Unable to set up this payout destination right now. Please try again shortly.', 502);
        }

        // A direct query, not $counsellor->payoutAccount -- a null-result relation is cached on
        // the model instance, so a caller who checks eligibility (EnsureCanOnboardPayoutDestinationAction,
        // which touches $dto->user->counsellor) and then calls this action using the SAME
        // in-memory $counsellor object across two separate onboarding calls (e.g. this exact
        // scenario is exercised in this file's own test suite) would otherwise see a stale
        // "no existing destination" even on a genuine replacement, silently skipping the
        // security-relevant destination-changed notification.
        $hadExistingDestination = CounsellorPayoutAccount::query()->where('counsellor_id', $counsellor->id)->exists();

        $payoutAccount = CounsellorPayoutAccount::query()->updateOrCreate(
            ['counsellor_id' => $counsellor->id],
            [
                'type' => $dto->type->value,
                'bank_code' => $dto->bankCode,
                // Paystack's real /bank/resolve response doesn't include bank_name (only
                // account_number/account_name) -- the recipient-creation response's
                // details.bank_name is the actual source; the first branch is a defensive
                // no-op kept only in case that ever changes.
                'bank_name' => $resolved['data']['bank_name'] ?? ($recipient['data']['details']['bank_name'] ?? ''),
                'account_name' => $accountName,
                'masked_account_number' => $this->mask($dto->accountNumber),
                'recipient_code' => $recipientCode,
                'currency' => $dto->currency,
            ]
        );

        // Only on a genuine CHANGE, not first-time onboarding -- see the notification's own
        // comment for why (nothing suspicious about a first-time setup).
        if ($hadExistingDestination) {
            $counsellor->notify(new PayoutDestinationChangedNotification);
        }

        return $payoutAccount;
    }

    private function mask(string $accountNumber): string
    {
        return '**** '.Str::substr($accountNumber, -4);
    }
}
