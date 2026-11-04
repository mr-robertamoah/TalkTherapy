<?php

namespace App\Http\Controllers;

use App\DTOs\CreateHowToDTO;
use App\DTOs\GetHowToDTO;
use App\Http\Requests\CreateHowToRequest;
use App\Http\Resources\HowToResource;
use App\Models\HowTo;
use App\Services\HowToService;
use Illuminate\Http\Request;
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

            return $this->successResponse($request, ['howTo' => new HowToResource($howTo)]);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }

    // Reads $request->route('howToId') rather than the magic ->howToId property -- see the
    // identical fix/rationale in SessionController (SCRUM-116/SCRUM-130/SCRUM-133).
    public function updateHowTo(Request $request)
    {
        try {
            $howTo = HowToService::new()->updateHowTo(
                CreateHowToDTO::new()->fromArray([
                    'user' => $request->user(),
                    'name' => $request->name,
                    'description' => $request->description,
                    'page' => $request->page,
                    'howTo' => HowTo::find($request->route('howToId')),
                    'howToSteps' => $request->howToSteps ?? [],
                    'addedHowToSteps' => $request->addedSteps ?? [],
                    'deletedHowToSteps' => $request->deletedSteps ?? [],
                ])
            );

            return $this->successResponse($request, ['howTo' => new HowToResource($howTo)]);
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
                    'howTo' => $howTo = HowTo::find($request->route('howToId')),
                ])
            );

            return $this->successResponse($request, ['howTo' => new HowToResource($howTo)]);
        } catch (Throwable $th) {

            return $this->returnFailure($request, $th);
        }
    }
}
