<?php

namespace App\Http\Controllers;

use App\Actions\GetModelWithModelNameAndIdAction;
use App\DTOs\CreateLinkDTO;
use App\DTOs\GetLinksDTO;
use App\Http\Resources\LinkResource;
use App\Models\Link;
use App\Services\LinkService;
use App\Support\FailureResponse;
use App\Support\PendingLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class LinkController extends Controller
{
    public function createLink(Request $request)
    {
        try {
            $link = LinkService::new()->createLink(
                CreateLinkDTO::new()->fromArray([
                    'user' => $request->user(),
                    'type' => $request->type,
                    'addedby' => GetModelWithModelNameAndIdAction::new()->execute($request->addedbyType, $request->addedbyId),
                    'to' => GetModelWithModelNameAndIdAction::new()->execute($request->toType, $request->toId),
                    'for' => GetModelWithModelNameAndIdAction::new()->execute($request->forType, $request->forId),
                ])
            );

            return $this->successResponse($request, ['link' => new LinkResource($link)]);
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    // Reads $request->route('linkId'/'uuid') rather than the magic ->linkId/->uuid properties --
    // see the identical fix/rationale in SessionController (SCRUM-116/SCRUM-130/SCRUM-133).
    public function changeLinkStatus(Request $request)
    {
        try {
            $link = LinkService::new()->changeLinkStatus(
                CreateLinkDTO::new()->fromArray([
                    'user' => $request->user(),
                    'link' => Link::find($request->route('linkId')),
                ])
            );

            return $this->successResponse($request, ['link' => new LinkResource($link)]);
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    public function changeMultipleLinkStatuses(Request $request)
    {
        try {
            // All or none (LinkService). It used to open a transaction here that a failure never rolled back.
            $links = LinkService::new()->changeMultipleLinkStatuses(
                CreateLinkDTO::new()->fromArray([
                    'user' => $request->user(),
                    'linksData' => $request->linksData,
                ])
            );

            return response()->json(['links' => LinkResource::collection($links)]);
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    public function deleteLink(Request $request)
    {
        try {
            LinkService::new()->deleteLink(
                CreateLinkDTO::new()->fromArray([
                    'user' => $request->user(),
                    'link' => $link = Link::find($request->route('linkId')),
                ])
            );

            return $this->successResponse($request, ['link' => new LinkResource($link)]);
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    public function performAction(Request $request)
    {
        PendingLink::forget($request);

        try {
            return LinkService::new()->performAction(
                CreateLinkDTO::new()->fromArray([
                    'user' => $request->user(),
                    'link' => Link::where('uuid', $request->route('uuid'))->first(),
                ])
            );
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            // SCRUM-97: a link is opened by navigating to it (often from an email), so it always
            // lands on home with the alert; only a script gets JSON (see Controller).
            if ($request->wantsJsonResponse()) {
                // SCRUM-382: plus e.g. guardian_required, when the refusal has one.
                return response()->json(FailureResponse::withCode(['message' => $message], $th), $status);
            }

            session()->flash('alert', $message);

            return Redirect::route('home');
        }
    }

    // Confirming a link that opening only showed (LinkService::confirmLink): a group's member invitation
    // (SCRUM-383), an organisation join link (SCRUM-415), or a link that acts (SCRUM-418). JSON: what
    // happened and where to go next; a plain form post is taken there with it.
    public function confirmLink(Request $request)
    {
        try {
            $confirmation = LinkService::new()->confirmLink(
                CreateLinkDTO::new()->fromArray([
                    'user' => $request->user(),
                    'link' => Link::where('uuid', $request->route('uuid'))->first(),
                    'anonymous' => $request->boolean('anonymous'),
                ])
            );

            return $this->successResponse(
                $request,
                ['status' => true, 'message' => $confirmation->message, 'redirect' => $confirmation->redirect],
                Redirect::to($confirmation->redirect ?? route('home'))->with('message', $confirmation->message),
            );
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    public function deleteMultipleLinks(Request $request)
    {
        try {
            LinkService::new()->deleteMultipleLinks(
                CreateLinkDTO::new()->fromArray([
                    'user' => $request->user(),
                    'linksData' => $request->linksData,
                ])
            );

            return response()->json(['links' => $request->linksData]);
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }

    public function getLinks(Request $request)
    {
        try {
            return LinkService::new()->getLinks(
                GetLinksDTO::new()->fromArray([
                    'user' => $request->user(),
                    'type' => $request->type,
                    'state' => $request->state,
                    'addedby' => GetModelWithModelNameAndIdAction::new()
                        ->execute(
                            $request->addedbyType,
                            $request->addedbyId
                        ),
                    'to' => GetModelWithModelNameAndIdAction::new()
                        ->execute(
                            $request->toType,
                            $request->toId
                        ),
                    'for' => GetModelWithModelNameAndIdAction::new()
                        ->execute(
                            $request->forType,
                            $request->forId
                        ),
                ])
            );
        } catch (Throwable $th) {
            return $this->failureResponse($request, $th);
        }
    }
}
