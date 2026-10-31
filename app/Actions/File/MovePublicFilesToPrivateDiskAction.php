<?php

namespace App\Actions\File;

use App\Actions\Action;
use App\Models\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

// SCRUM-300, generalised by SCRUM-336: one-off move of an upload category from the public disk
// (a directory symlinked into public/) onto its private disk. Run by each category's own data
// migration; kept as an action so it's testable and re-runnable. Idempotent: a file already moved
// is no longer on the public disk and is skipped. A row whose file is missing from disk is still
// switched to the private disk -- it was unreachable anyway, and leaving it on 'public' would keep
// File::url resolving a public link for it.
class MovePublicFilesToPrivateDiskAction extends Action
{
    /**
     * @param  string  $relation  the File relation that identifies the category ('licenses', 'messages')
     * @param  string  $publicDirectory  its directory on the public disk
     * @param  string  $privateDisk  the private disk it moves to
     */
    public function execute(string $relation, string $publicDirectory, string $privateDisk): int
    {
        $moved = 0;

        File::query()
            ->where('storage', 'public')
            ->whereHas($relation)
            // By id, not offset: each moved row drops out of this where('storage', 'public') filter,
            // which would make offset paging skip rows.
            ->lazyById()
            ->each(function (File $file) use (&$moved, $privateDisk) {
                $path = $file->diskPath();

                if (Storage::disk('public')->exists($path)) {
                    $this->copyToPrivate($path, $privateDisk);
                } else {
                    Log::warning('File missing from the public disk while moving it to private storage.', ['file_id' => $file->id, 'disk' => $privateDisk]);
                }

                // Row first, then the public copy: if deleting fails, the file is merely orphaned
                // on the public disk, never left as a row pointing at a public location.
                $file->update(['storage' => $privateDisk]);
                $this->deletePublicCopy($path);

                $moved++;
            });

        // Anything else still under the directory (no matching relation on its row, or no row at
        // all: an interrupted upload, manual testing, a crash between the row update and the delete
        // above) was served by the same public symlink, so it moves too.
        foreach (Storage::disk('public')->allFiles($publicDirectory) as $orphan) {
            $this->copyToPrivate($orphan, $privateDisk);
            $this->deletePublicCopy($orphan);
            $moved++;
        }

        if ($left = Storage::disk('public')->allFiles($publicDirectory)) {
            Log::error('Files are still on the public disk after the move -- remove them by hand.', ['paths' => $left]);
        }

        return $moved;
    }

    // For the data migrations only, never tests (real files): migrations run as root (docker
    // compose exec) or the deploy user (EC2), not the web server user who must read and write
    // these files -- so hand the tree to whoever owns the public storage directory. Only root can
    // chown; anyone else is told to.
    public function handOwnershipToWebServer(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }

        $owner = fileowner(storage_path('app/public'));
        $group = filegroup(storage_path('app/public'));
        $paths = [$root, ...array_map(
            fn ($item) => $item->getPathname(),
            iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST)),
        )];

        foreach ($paths as $path) {
            if (! (@chown($path, $owner) && @chgrp($path, $group))) {
                Log::warning("Could not hand {$root} to the web server user -- run: chown -R \$(stat -c %U:%G storage/app/public) {$root}");

                return;
            }
        }
    }

    private function copyToPrivate(string $path, string $privateDisk): void
    {
        $stream = Storage::disk('public')->readStream($path);

        try {
            Storage::disk($privateDisk)->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    // The public disk doesn't throw (config/filesystems.php), so a failed delete would otherwise be
    // silent -- leaving a private file behind in a publicly-served directory.
    private function deletePublicCopy(string $path): void
    {
        if (! Storage::disk('public')->delete($path)) {
            Log::error('Could not delete a file\'s public copy after moving it to private storage -- remove it by hand.', ['path' => $path]);
        }
    }
}
