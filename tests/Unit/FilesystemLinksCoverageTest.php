<?php

// SCRUM-182/TT-10.5: this project links each upload subdirectory individually into
// public/storage/ via config/filesystems.php's `links` array (not Laravel's default single
// public/storage -> storage/app/public symlink) -- docker/php/entrypoint.sh re-runs
// `storage:link --force` off that array on every boot. Adding a new upload path (a new
// FileUploadDTO 'path' value) without adding a matching `links` entry compiles fine, passes
// every existing Pest test (Storage::fake() never touches real symlinks), and uploads succeed
// and save correctly -- the only symptom is every file of that type 404ing when rendered in a
// real browser. This exact bug shipped in SCRUM-186 (organization logo) and was only caught by
// manual Playwright testing in SCRUM-187. This test catches it automatically instead, by
// scanning the actual source for every FileUploadDTO 'path' literal in use.

// Upload paths on private disks -- deliberately never symlinked into public/. TT-4.11a/SCRUM-302's
// identity documents first; SCRUM-300 added counsellor license/ID documents ('licenses', on the
// private license_documents disk). Shared by both tests below so they can't drift apart.
const PRIVATE_DOCUMENT_UPLOAD_PATHS = ['identity_documents', 'licenses'];

test('every upload path used in app/Actions and app/Services has a matching filesystems.links entry', function () {
    $configuredPaths = collect(config('filesystems.links'))
        ->keys()
        ->map(fn (string $publicPath) => basename($publicPath))
        ->all();

    // TT-4.11a/SCRUM-302: identity-verification documents are the one deliberate exception to
    // this test's own invariant -- they must NEVER be reachable via a public symlink (see
    // config/filesystems.php's 'identity_documents' disk and its own comment), unlike every
    // other upload path this test protects. Excluded explicitly here, not by accident of the
    // regex missing it, so a future path added to this same private disk doesn't silently need
    // its own allowlist entry rediscovered from scratch.
    $privateDiskPaths = PRIVATE_DOCUMENT_UPLOAD_PATHS;

    $usedPaths = [];

    foreach ([app_path('Actions'), app_path('Services')] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (preg_match_all("/'path'\s*=>\s*'([a-z_]+)'/", $contents, $matches)) {
                foreach ($matches[1] as $path) {
                    $usedPaths[$path] = true;
                }
            }
        }
    }

    $usedPaths = array_values(array_diff(array_keys($usedPaths), $privateDiskPaths));

    expect($usedPaths)->not->toBeEmpty();
    expect($usedPaths)->each(
        fn ($path) => expect($configuredPaths)->toContain($path->value)
    );
});

// SCRUM-300: the inverse guarantee -- a private-document upload path must never regain a public
// symlink (licenses/ was publicly linked, serving national ID scans to anyone with the URL).
test('no private-document upload path is symlinked into public/', function () {
    $linkedPaths = collect(config('filesystems.links'))->keys()->map(fn (string $publicPath) => basename($publicPath));

    foreach (PRIVATE_DOCUMENT_UPLOAD_PATHS as $privatePath) {
        expect($linkedPaths)->not->toContain($privatePath);
    }
});
