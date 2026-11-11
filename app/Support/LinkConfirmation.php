<?php

namespace App\Support;

// SCRUM-418: what confirming a link did, for the person who confirmed it -- a sentence, and where to go
// next (null: stay on the page). LinkController::confirmLink sends it as JSON; a plain form post is
// redirected there with the sentence.
final class LinkConfirmation
{
    public function __construct(
        public readonly string $message,
        public readonly ?string $redirect = null,
    ) {}
}
