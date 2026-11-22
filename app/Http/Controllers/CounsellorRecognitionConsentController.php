<?php

namespace App\Http\Controllers;

use App\DTOs\CounsellorRecognitionDTO;
use App\Http\Requests\ChangeRecognitionConsentRequest;
use App\Http\Resources\CounsellorRecognitionResource;
use App\Services\CounsellorRecognitionService;
use Illuminate\Validation\ValidationException;
use Throwable;

// TT-7.11d1/SCRUM-473: a counsellor withdrawing their featuring consent for a recognition, or opting back in. Their
// first answer is the consent request (requests.respond).
class CounsellorRecognitionConsentController extends Controller
{
    public function __invoke(ChangeRecognitionConsentRequest $request, int $recognition)
    {
        try {
            $updated = CounsellorRecognitionService::new()->changeConsent(CounsellorRecognitionDTO::new()->fromArray([
                'user' => $request->user(),
                'recognitionId' => $recognition,
                'consentAction' => $request->validated('action'),
            ]));

            return $this->successResponse($request, ['recognition' => (new CounsellorRecognitionResource($updated))->resolve($request)]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }
}
