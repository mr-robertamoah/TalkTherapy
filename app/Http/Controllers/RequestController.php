<?php

namespace App\Http\Controllers;

use App\DTOs\RequestResponseDTO;
use App\Models\Request as ModelsRequest;
use App\Services\RequestService;
use Illuminate\Http\Request;
use Throwable;

class RequestController extends Controller
{
    public function getRequests(Request $request)
    {
        return RequestService::new()->getRequests(
            $request->status ?? '',
            $request->user()
        );
    }

    public function respond(Request $request)
    {
        try {
            $requestResource = RequestService::new()->respondToRequest(
                RequestResponseDTO::new()->fromArray([
                    'user' => $request->user(),
                    'response' => $request->response,
                    'request' => ModelsRequest::find($request->requestId),
                ])
            );

            return response()->json([
                'status' => true,
                'request' => $requestResource,
            ], 201);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json([
                'status' => false,
                'request' => null,
                'error' => $message,
            ], $status);
        }
    }
}
