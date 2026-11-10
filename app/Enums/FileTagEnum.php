<?php

namespace App\Enums;

// SCRUM-380: the `fileables.tag` values -- one per single-file slot on the shared, tagged pivot
// (SCRUM-182/TT-10). Untagged (NULL) rows are the multi-file attachments (licences, posts, reports,
// messages). Migrations keep their literal strings: they must replay as they were written.
enum FileTagEnum: string
{
    case avatar = 'avatar';
    case cover = 'cover';
    case logo = 'logo';
    case identityDocument = 'identity-document';
    case step = 'step';
}
