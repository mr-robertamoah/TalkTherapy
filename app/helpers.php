<?php

use App\Models\File;
use Illuminate\Support\Facades\Storage;

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
    $path = 'storage';
    
    if ($file->path) $path .= '/';
    
    $path .= $file->path . '/';
    
    return asset($path . $file->name);
}