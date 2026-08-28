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

    // Reads $request->route('requestId') rather than the magic ->requestId property --
    // Illuminate\Http\Request::__get() prefers a same-named parsed-body/query key over the route
    // parameter, so a client could otherwise accept/reject an arbitrary other pending request
    // (org invites/applications, group-therapy membership, guardianship, counsellor affiliation)
    // instead of the one the URL/UI shows (SCRUM-116/SCRUM-130/SCRUM-133).
    public function respond(Request $request)
    {
        try {
            $requestResource = RequestService::new()->respondToRequest(
                RequestResponseDTO::new()->fromArray([
                    'user' => $request->user(),
                    'response' => $request->response,
                    'request' => ModelsRequest::find($request->route('requestId')),
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
