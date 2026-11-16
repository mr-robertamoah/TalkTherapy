<?php

namespace App\Enums;

// SCRUM-453: what a Google sign-in leads to (ResolveGoogleAccountAction). SCRUM-454: a Google account connected to
// nothing starts a new account (finished on "Finish setting up").
enum GoogleAccountOutcomeEnum
{
    case SignIn;
    case StartSignUp;
    case Refused;
}
