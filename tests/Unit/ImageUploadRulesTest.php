<?php

use App\Support\ImageUploadRules;

test('rules() returns the expected size and MIME constraints', function () {
    expect(ImageUploadRules::rules())->toBe([
        'nullable',
        'file',
        'mimes:jpg,jpeg,png,webp',
        'max:2048',
    ]);
});
