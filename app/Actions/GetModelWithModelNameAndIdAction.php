<?php

namespace App\Actions;

class GetModelWithModelNameAndIdAction extends Action
{
    public function execute(String|int|null $modelName, String|int|null $modelId)
    {
        if (is_null($modelId) || is_null($modelName)) return null;


        $class = "App\\Models\\" . ucfirst(strtolower($modelName));
        if (!class_exists($class)) return null;

        return $class::find($modelId);
    }
}