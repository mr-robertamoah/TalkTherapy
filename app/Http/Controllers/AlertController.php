<?php

namespace App\Http\Controllers;

use App\Actions\GetModelWithModelNameAndIdAction;
use App\DTOs\AlertServiceDTO;
use App\Enums\AlertStatusEnum;
use App\Models\Alert;
use App\Services\AlertService;
use Exception;
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
                    )
                ])
            );
            
            return response()->json([
                'status' => true
            ]);
        } catch (Throwable $th) {
            $message = $th->getCode() == 500 ? "Something unfortunate happened. Please try again shortly." : $th->getMessage();
            
            ds($th);

            throw new Exception($message);
        }
    }
}
