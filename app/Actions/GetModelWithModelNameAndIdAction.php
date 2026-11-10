<?php

namespace App\Actions;

use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\Organization;
use App\Models\Post;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

// SCRUM-349: resolves a client-named model from an explicit allowlist. This used to build the class
// as "App\Models\" . ucfirst(strtolower($name)), which (a) never found a multi-word model such as
// GroupTherapy on a case-sensitive filesystem -- silently disabling group alerts, discussions
// filters, reports... -- and (b) would resolve ANY single-word model, so the 500-vs-422 difference
// on a typed DTO told callers which ids existed in unrelated tables. Only the models some caller's
// DTO actually accepts are listed; names are matched case-insensitively ("therapy", "THERAPY",
// "GroupTherapy", "grouptherapy").
class GetModelWithModelNameAndIdAction extends Action
{
    private const MODELS = [
        'user' => User::class,
        'counsellor' => Counsellor::class,
        'therapy' => Therapy::class,
        'grouptherapy' => GroupTherapy::class,
        'discussion' => Discussion::class,
        'session' => Session::class,
        'post' => Post::class,
        'organization' => Organization::class,
    ];

    /**
     * @param  array<class-string<Model>>|null  $only  narrows the allowlist for a caller whose DTO
     *                                                 accepts fewer models, so a model it can't take
     *                                                 resolves to null (a clean 422) instead of a
     *                                                 TypeError 500
     */
    public function execute(mixed $modelName, mixed $modelId, ?array $only = null): ?Model
    {
        // Non-scalars (?likeableId[]=1) used to reach find() and return a Collection -- a 500.
        if (! is_string($modelName) || ! (is_int($modelId) || (is_string($modelId) && ctype_digit($modelId)))) {
            return null;
        }

        $class = self::MODELS[strtolower(str_replace(['_', '-', ' '], '', $modelName))] ?? null;

        if (! $class || ($only !== null && ! in_array($class, $only, true))) {
            return null;
        }

        return $class::find($modelId);
    }
}
