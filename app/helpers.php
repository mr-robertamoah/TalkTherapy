<?php

use App\Models\File;

function constructName(
    ?string $firstName = null,
    ?string $lastName = null,
    ?string $otherNames = null,
) {
    $name = '';

    if ($firstName) {
        $name = $firstName;
    }

    if ($lastName) {
        $name .= (strlen($firstName) ? ' ' : '').$lastName;
    }

    if ($otherNames) {
        $name .= (strlen($name) ? ', ' : '').$otherNames;
    }

    return $name;
}

function getUrlFor(File $file)
{
    // TT-4.11a/SCRUM-302 (security review, defense-in-depth), widened by SCRUM-300/336: a file on a
    // private disk (identity, license documents, chat attachments) must NEVER get a public URL, regardless of what
    // calls this helper. A careless ->url/getUrlFor() on one (bypassing its dedicated authorized
    // route) fails loudly here instead of silently producing a working public link.
    if ($file->isOnPrivateDisk()) {
        throw new RuntimeException('Private documents have no public URL -- use their authorized retrieval route (requests.documents.show / licenses.documents.show / messages.attachments.show / reports.evidence.show) instead.');
    }

    $path = 'storage';

    if ($file->path) {
        $path .= '/';
    }

    $path .= $file->path.'/';

    return asset($path.$file->name);
}

function getArrayKey(string $key, array $array)
{

    return array_key_exists($key, $array) ? $array[$key] : null;
}
