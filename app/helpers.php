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
    // TT-4.11a/SCRUM-302 (security review, defense-in-depth): every other disk's files are
    // intentionally public and safe to resolve this way (SCRUM-300 is the pre-existing ticket
    // for hardening those too) -- but a file on the private 'identity_documents' disk must NEVER
    // get a public URL, regardless of what calls this helper. A future careless ->url/getUrlFor()
    // call on an identity document (bypassing the dedicated authorized route) fails loudly here
    // instead of silently producing a working public link.
    if ($file->storage === 'identity_documents') {
        throw new RuntimeException('Identity-verification documents have no public URL -- use the authorized requests.documents.show route instead.');
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
