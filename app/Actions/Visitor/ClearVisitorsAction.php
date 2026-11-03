<?php

namespace App\Actions\Visitor;

use App\Actions\Action;
use App\Models\Visitor;

// Scheduled daily: clears the previous day's visitor records. SCRUM-376: moved out of AppService.
class ClearVisitorsAction extends Action
{
    public function execute(): void
    {
        Visitor::query()
            ->whereUser()
            ->orWhere(function ($query) {
                $query->whereNonUser();
            })
            ->delete();
    }
}
