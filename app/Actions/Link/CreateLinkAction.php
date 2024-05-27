<?php

namespace App\Actions\Link;

use App\Actions\Action;
use App\DTOs\CreateLinkDTO;
use App\Enums\LinkStateEnum;
use Illuminate\Support\Str;

class CreateLinkAction extends Action
{
    public function execute(CreateLinkDTO $createLinkDTO)
    {
        $link = $createLinkDTO->addedby->addedLinks()->create([
            'state' => LinkStateEnum::active->value,
            'type' => $createLinkDTO->type,
            'uuid' => Str::uuid()
        ]);

        if ($createLinkDTO->for)
            $link->for()->associate($createLinkDTO->for);

        if ($createLinkDTO->to)
            $link->to()->associate($createLinkDTO->to);

        $link->save();

        return $link->refresh();
    }
}