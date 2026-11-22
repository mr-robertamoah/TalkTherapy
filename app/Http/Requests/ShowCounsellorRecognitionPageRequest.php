<?php

namespace App\Http\Requests;

// TT-7.11d2/SCRUM-474: the admin recognition page (any administrator). A month that isn't this one or last one sends
// the visit to the page's default month with the error, rather than "back" -- which, for a typed URL, is the same bad
// URL again.
class ShowCounsellorRecognitionPageRequest extends ViewCounsellorRecognitionRequest
{
    protected $redirectRoute = 'administrator.recognition';
}
