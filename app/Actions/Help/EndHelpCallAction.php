<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Actions\Video\EndVideoSessionAction;
use App\Models\Session;
use Throwable;

// SCRUM-33: ends any audio call still open in a help chat's session when the chat ends. Best effort:
// the chat has ended either way, and a provider hiccup must not undo that.
class EndHelpCallAction extends Action
{
    public function execute(Session $session): void
    {
        try {
            EndVideoSessionAction::new()->execute($session);
        } catch (Throwable $th) {
            report($th);
        }
    }
}
