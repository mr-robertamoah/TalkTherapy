<?php

use App\Actions\License\MoveLicenseDocumentsToPrivateDiskAction;
use App\Enums\LicensingAuthorityTypeEnum;
use App\Enums\LicensingTypeEnum;
use App\Models\File;
use App\Models\License;
use App\Models\LicensingAuthority;
use Illuminate\Support\Facades\Storage;

// SCRUM-300: the one-off move of pre-fix license documents off the public disk.

function aPublicFileForLicenseMove(string $name, bool $attachToLicense = true, bool $onDisk = true): File
{
    if ($onDisk) {
        Storage::disk('public')->put("licenses/{$name}", "contents of {$name}");
    }

    $file = File::factory()->create(['name' => $name, 'path' => 'licenses', 'storage' => 'public', 'mime' => 'image/jpeg']);

    if ($attachToLicense) {
        $authority = LicensingAuthority::factory()->create([
            'name' => 'Authority',
            'type' => LicensingAuthorityTypeEnum::values()[0],
            'license_type' => LicensingTypeEnum::values()[0],
        ]);
        License::factory()->create(['licensing_authority_id' => $authority->id])->files()->attach($file->id);
    }

    return $file;
}

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('license_documents');
});

test('license documents move to the private disk and leave nothing behind publicly', function () {
    $first = aPublicFileForLicenseMove('id-1.jpg');
    $second = aPublicFileForLicenseMove('id-2.jpg');

    $moved = MoveLicenseDocumentsToPrivateDiskAction::new()->execute();

    expect($moved)->toBe(2);
    foreach ([$first, $second] as $file) {
        expect($file->fresh()->storage)->toBe('license_documents')
            ->and(Storage::disk('license_documents')->get("licenses/{$file->name}"))->toBe("contents of {$file->name}")
            ->and(Storage::disk('public')->exists("licenses/{$file->name}"))->toBeFalse();
    }
});

test('a public file row that is not a license document is left alone', function () {
    Storage::disk('public')->put('avatars/me.jpg', 'avatar');
    $avatar = File::factory()->create(['name' => 'me.jpg', 'path' => 'avatars', 'storage' => 'public', 'mime' => 'image/jpeg']);

    MoveLicenseDocumentsToPrivateDiskAction::new()->execute();

    expect($avatar->fresh()->storage)->toBe('public')
        ->and(Storage::disk('public')->exists('avatars/me.jpg'))->toBeTrue();
});

test('orphaned files under the public licenses directory are moved too', function () {
    Storage::disk('public')->put('licenses/orphan.jpg', 'orphan');

    expect(MoveLicenseDocumentsToPrivateDiskAction::new()->execute())->toBe(1)
        ->and(Storage::disk('public')->exists('licenses/orphan.jpg'))->toBeFalse()
        ->and(Storage::disk('license_documents')->get('licenses/orphan.jpg'))->toBe('orphan');
});

test('a license row whose file is already missing is still switched to the private disk', function () {
    $missing = aPublicFileForLicenseMove('gone.jpg', onDisk: false);

    MoveLicenseDocumentsToPrivateDiskAction::new()->execute();

    // Otherwise File::url would keep resolving a public link for it.
    expect($missing->fresh()->storage)->toBe('license_documents');
});

test('running it again is a no-op that keeps the private copy intact', function () {
    aPublicFileForLicenseMove('id-1.jpg');
    MoveLicenseDocumentsToPrivateDiskAction::new()->execute();

    expect(MoveLicenseDocumentsToPrivateDiskAction::new()->execute())->toBe(0)
        ->and(Storage::disk('license_documents')->get('licenses/id-1.jpg'))->toBe('contents of id-1.jpg');
});

test('a public copy left behind by a crash after the row update is swept on the next run', function () {
    $file = aPublicFileForLicenseMove('id-1.jpg');
    // The row was switched, but the public copy never got deleted.
    $file->update(['storage' => 'license_documents']);

    MoveLicenseDocumentsToPrivateDiskAction::new()->execute();

    expect(Storage::disk('public')->exists('licenses/id-1.jpg'))->toBeFalse()
        ->and(Storage::disk('license_documents')->get('licenses/id-1.jpg'))->toBe('contents of id-1.jpg');
});
