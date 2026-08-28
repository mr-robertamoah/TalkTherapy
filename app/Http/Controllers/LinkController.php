<?php

namespace App\Http\Controllers;

use App\Actions\GetModelWithModelNameAndIdAction;
use App\DTOs\CreateLinkDTO;
use App\DTOs\GetLinksDTO;
use App\Http\Resources\LinkResource;
use App\Models\Link;
use App\Services\LinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

            return $this->returnSuccess($request, $link);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    public function createMultipleLinks(Request $request)
    {
        try {
            $links = LinkService::new()->createMultipleLinks(
                CreateLinkDTO::new()->fromArray([
                    'user' => $request->user(),
                    'type' => $request->type,
                    'linksData' => $request->linksData,
                    'addedby' => GetModelWithModelNameAndIdAction::new()->execute($request->addedbyType, $request->addedbyId),
                    'to' => GetModelWithModelNameAndIdAction::new()->execute($request->toType, $request->toId),
                    'for' => GetModelWithModelNameAndIdAction::new()->execute($request->forType, $request->forId),
                ])
            );

            return response()->json(['links' => LinkResource::collection($links)]);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
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

            return $this->returnSuccess($request, $link);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    public function changeMultipleLinkStatuses(Request $request)
    {
        try {
            DB::beginTransaction();

            $links = LinkService::new()->changeMultipleLinkStatuses(
                CreateLinkDTO::new()->fromArray([
                    'user' => $request->user(),
                    'linksData' => $request->linksData,
                ])
            );

            DB::commit();

            return response()->json(['links' => LinkResource::collection($links)]);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
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

            return $this->returnSuccess($request, $link);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
        }
    }

    public function performAction(Request $request)
    {
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

            session()->put('alert', $message);

            if ($status == 422) {
                return Redirect::route('home');
            }

            return $this->returnFailure($request, $th);
        }
    }

    public function deleteMultipleLinks(Request $request)
    {
        try {
            DB::beginTransaction();

            LinkService::new()->deleteMultipleLinks(
                CreateLinkDTO::new()->fromArray([
                    'user' => $request->user(),
                    'linksData' => $request->linksData,
                ])
            );

            DB::commit();

            return response()->json(['links' => $request->linksData]);
        } catch (Throwable $th) {
            return $this->returnFailure($request, $th);
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
            return $this->returnFailure($request, $th);
        }
    }

    private function returnSuccess(Request $request, Link $link)
    {
        $link = new LinkResource($link);

        if ($request->acceptsJson()) {
            return response()->json(['link' => $link]);
        }

        return Redirect::back()->with(['link' => $link]);
    }

    private function returnFailure(Request $request, Throwable $th)
    {
        $status = $this->statusFor($th);
        $message = $this->messageFor($th, $status);

        if ($request->acceptsJson()) {
            return response()->json(['message' => $message], $status);
        }

        return Redirect::back()->withErrors(['alert' => $message]);
    }
}
