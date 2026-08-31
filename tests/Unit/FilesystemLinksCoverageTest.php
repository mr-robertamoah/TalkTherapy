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

test('every upload path used in app/Actions and app/Services has a matching filesystems.links entry', function () {
    $configuredPaths = collect(config('filesystems.links'))
        ->keys()
        ->map(fn (string $publicPath) => basename($publicPath))
        ->all();

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

    $usedPaths = array_keys($usedPaths);

    expect($usedPaths)->not->toBeEmpty();
    expect($usedPaths)->each(
        fn ($path) => expect($configuredPaths)->toContain($path->value)
    );
});
