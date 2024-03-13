<?php

use App\Models\File;

function constructName(
    ?string $firstName = null,
    ?string $lastName = null,
    ?string $otherNames = null,
) {
    $name = '';

    if ($firstName) $name = $firstName;

    if ($lastName) $name .= (strlen($firstName) ? ' ' : '') . $lastName;

    if ($otherNames) $name .= (strlen($name) ? ', ' : '') . $otherNames;

    return $name;
}

function getUrlFor(File $file) {
    $path = $file->path;

    if ($path) $path .= '/';

    return asset($path . $file->name);
}