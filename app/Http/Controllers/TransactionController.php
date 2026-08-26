<?php

namespace App\Http\Controllers;

use App\DTOs\TransactionDTO;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class TransactionController extends Controller
{
    public function initiate(Request $request)
    {
        try {
            $result = TransactionService::new()->initiateCharge(
                TransactionDTO::new()->fromArray([
                    'user' => $request->user(),
                    'for' => $this->getFor($request),
                    'callbackUrl' => route('transactions.callback'),
                ])
            );

            return response()->json([
                'transaction' => $result['transaction'],
                'authorizationUrl' => $result['authorizationUrl'],
            ]);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json(['message' => $message], $status);
        }
    }

    // The browser lands here after Paystack's checkout, regardless of whether the webhook has
    // already processed this reference or not -- RecordTransactionStatusAction's idempotency is
    // what makes it safe for both to race for the same reference.
    public function callback(Request $request)
    {
        $reference = $request->query('reference') ?: $request->query('trxref');

        try {
            $transaction = TransactionService::new()->verifyTransaction(
                TransactionDTO::new()->fromArray([
                    'user' => $request->user(),
                    'reference' => $reference,
                ])
            );

            return Redirect::to($this->redirectUrlFor($transaction))
                ->with(['transactionStatus' => $transaction->status]);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return Redirect::to('/')->withErrors(['alert' => $message]);
        }
    }

    // Unauthenticated by design -- this is Paystack's server calling us, not a browser. The
    // trust boundary is the signature check inside TransactionService::handleWebhook(), not
    // session/Sanctum auth.
    public function webhook(Request $request)
    {
        try {
            TransactionService::new()->handleWebhook(
                TransactionDTO::new()->fromArray([
                    'signature' => $request->header('x-paystack-signature'),
                    'payload' => $request->all(),
                    'rawBody' => $request->getContent(),
                ])
            );

            return response()->json(['message' => 'ok']);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json(['message' => $message], $status);
        }
    }

    // Deliberately reads $request->route(...) rather than the magic ->sessionId/etc. properties
    // SessionController/TherapyController use elsewhere: Request::__get() prefers a same-named
    // key from the parsed body/query over the route parameter, so a client could otherwise send
    // e.g. {"therapyId": null, "sessionId": 42} to a /therapies/{id}/transactions URL and have
    // this resolve to an entirely different Session than the URL says -- making the URL purely
    // decorative and the actual charge target fully client-controlled.
    private function getFor(Request $request)
    {
        if ($request->route('sessionId')) {
            return Session::find($request->route('sessionId'));
        }

        if ($request->route('groupTherapyId')) {
            return GroupTherapy::find($request->route('groupTherapyId'));
        }

        if ($request->route('therapyId')) {
            return Therapy::find($request->route('therapyId'));
        }

        return null;
    }

    private function redirectUrlFor(Transaction $transaction): string
    {
        $for = $transaction->for instanceof Session
            ? $transaction->for->for
            : $transaction->for;

        return $for instanceof GroupTherapy
            ? route('group.therapies.get', ['groupTherapyId' => $for->id])
            : route('therapies.get', ['therapyId' => $for->id]);
    }
}
