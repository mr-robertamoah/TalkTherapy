<?php

namespace App\Http\Controllers;

use App\Actions\GetModelWithModelNameAndIdAction;
use App\DTOs\AlertServiceDTO;
use App\Enums\AlertStatusEnum;
use App\Services\AlertService;
use Illuminate\Http\Request;
use Throwable;

class AlertController extends Controller
{
    public function waitingForAlert(Request $request)
    {
        try {
            AlertService::new()->waitingForAlert(
                AlertServiceDTO::new()->fromArray([
                    'user' => $request->user(),
                    'status' => AlertStatusEnum::waiting->value,
                    'alertable' => GetModelWithModelNameAndIdAction::new()->execute(
                        $request->alertableType, $request->alertableId
                    ),
                ])
            );

            return response()->json([
                'status' => true,
            ]);
        } catch (Throwable $th) {
            $status = $this->statusFor($th);
            $message = $this->messageFor($th, $status);

            return response()->json(['message' => $message], $status);
        }
    }
}
