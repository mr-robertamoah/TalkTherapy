<?php

namespace App\Http\Controllers;

use App\DTOs\RequestResponseDTO;
use App\Http\Resources\AdminCounsellorVerificationRequestResource;
use App\Http\Resources\RequestResource;
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
            $req = RequestService::new()->respondToRequest(
                RequestResponseDTO::new()->fromArray([
                    'user' => $request->user(),
                    'response' => $request->response,
                    'request' => ModelsRequest::find($request->requestId)
                ])
            );

            return response()->json([
                'status' => true,
                'request' => new AdminCounsellorVerificationRequestResource($req)
            ], 201);
        } catch (Throwable $th) {
            $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();

            return response()->json([
                'status' => true,
                'request' => null,
                'error' => $message
            ], 500);
        }
    }
}
