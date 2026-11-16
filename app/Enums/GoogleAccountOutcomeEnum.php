<?php

namespace App\Enums;

// SCRUM-453: what a Google sign-in leads to (ResolveGoogleAccountAction). SCRUM-454 adds "start a new account".
enum GoogleAccountOutcomeEnum
{
    case SignIn;
    case Refused;
}
