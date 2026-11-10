<?php

namespace App\Support;

class ImageUploadRules
{
    // SCRUM-182/TT-10.8: single source of truth for the size/MIME limits shared by every image
    // upload endpoint (counsellor avatar/cover, organization logo, user avatar), so the four
    // FormRequests don't each hardcode their own copy of the same magic numbers. There's no way
    // to literally share this with the frontend's client-side check (different runtime), but
    // resources/js/Constants/imageUploadLimits.js mirrors these exact values -- keep both in sync
    // if either changes.
    public const MAX_SIZE_KB = 2048;

    public const ALLOWED_MIMES = ['jpg', 'jpeg', 'png', 'webp'];

    public static function rules(): array
    {
        return [
            'nullable',
            'file',
            'mimes:'.implode(',', self::ALLOWED_MIMES),
            'max:'.self::MAX_SIZE_KB,
        ];
    }
}
