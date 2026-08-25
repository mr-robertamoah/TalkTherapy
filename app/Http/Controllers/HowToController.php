<?php

namespace App\Http\Controllers;

use App\DTOs\CreateHowToDTO;
use App\DTOs\GetHowToDTO;
use App\Http\Requests\CreateHowToRequest;
use App\Http\Resources\HowToResource;
use App\Models\HowTo;
use App\Services\HowToService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Throwable;

class HowToController extends Controller
{
    public function getHowTos(Request $request)
    {
        return HowToService::new()->getHowTos(
            GetHowToDTO::new()->fromArray([
                'user' => $request->user(),
                'name' => $request->name,
                'pageLike' => $request->pageLike,
            ])
        );
    }

    public function createHowTo(CreateHowToRequest $request)
    {
        try {
            $howTo = HowToService::new()->createHowTo(
                CreateHowToDTO::new()->fromArray([
                    'user' => $request->user(),
                    'name' => $request->name,
                    'description' => $request->description,
                    'page' => $request->page,
                    'howToSteps' => $request->howToSteps,
                ])
            );

            return $this->returnSuccess($request, $howTo);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    public function updateHowTo(Request $request)
    {
        try {
            $howTo = HowToService::new()->updateHowTo(
                CreateHowToDTO::new()->fromArray([
                    'user' => $request->user(),
                    'name' => $request->name,
                    'description' => $request->description,
                    'page' => $request->page,
                    'howTo' => HowTo::find($request->howToId),
                    'howToSteps' => $request->howToSteps ?? [],
                    'addedHowToSteps' => $request->addedSteps ?? [],
                    'deletedHowToSteps' => $request->deletedSteps ?? [],
                ])
            );

            return $this->returnSuccess($request, $howTo);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    public function deleteHowTo(Request $request)
    {
        try {
            HowToService::new()->deleteHowTo(
                CreateHowToDTO::new()->fromArray([
                    'user' => $request->user(),
                    'howTo' => $howTo = HowTo::find($request->howToId),
                ])
            );

            return $this->returnSuccess($request, $howTo);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    private function returnSuccess(Request $request, HowTo $howTo)
    {
        $howTo = new HowToResource($howTo);

        if ($request->acceptsJson()) {
            return response()->json(['howTo' => $howTo]);
        }

        return Redirect::back()->with(['howTo' => $howTo]);
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
