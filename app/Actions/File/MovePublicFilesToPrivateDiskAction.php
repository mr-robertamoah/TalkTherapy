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
     * @param  string  $directory  its directory on the source disk
     * @param  string  $privateDisk  the private disk it moves to
     * @param  string  $sourceDisk  where it lives now -- 'public' by default; SCRUM-341 also moves
     *                              host-run installs' rows off the default 'local' disk
     */
    public function execute(string $relation, string $directory, string $privateDisk, string $sourceDisk = 'public'): int
    {
        $moved = 0;

        File::query()
            ->where('storage', $sourceDisk)
            ->whereHas($relation)
            // By id, not offset: each moved row drops out of this where('storage', $sourceDisk) filter,
            // which would make offset paging skip rows.
            ->lazyById()
            ->each(function (File $file) use (&$moved, $privateDisk, $sourceDisk) {
                $path = $file->diskPath();

                if ($this->isSymlink($sourceDisk, $path)) {
                    Log::error('Refusing to move a symlinked file to private storage.', ['file_id' => $file->id, 'disk' => $sourceDisk]);
                } elseif (Storage::disk($sourceDisk)->exists($path)) {
                    $this->copyToPrivate($path, $privateDisk, $sourceDisk);
                } else {
                    Log::warning('File missing from its source disk while moving it to private storage.', ['file_id' => $file->id, 'from' => $sourceDisk, 'to' => $privateDisk]);
                }

                // Row first, then the source copy: if deleting fails, the file is merely orphaned
                // on the source disk, never left as a row pointing at the old location.
                $file->update(['storage' => $privateDisk]);
                $this->deletePublicCopy($path, $sourceDisk);

                $moved++;
            });

        // Anything else still under the directory (no matching relation on its row, or no row at
        // all: an interrupted upload, manual testing, a crash between the row update and the delete
        // above) was served by the same public symlink, so it moves too.
        foreach (Storage::disk($sourceDisk)->allFiles($directory) as $orphan) {
            if ($this->isSymlink($sourceDisk, $orphan)) {
                Log::error('Refusing to move a symlinked file to private storage.', ['disk' => $sourceDisk, 'path' => $orphan]);

                continue;
            }

            $this->copyToPrivate($orphan, $privateDisk, $sourceDisk);
            $this->deletePublicCopy($orphan, $sourceDisk);
            $moved++;
        }

        if ($left = Storage::disk($sourceDisk)->allFiles($directory)) {
            Log::error('Files are still on the source disk after the move -- remove them by hand.', ['disk' => $sourceDisk, 'paths' => $left]);
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
            // lchown/lchgrp: never follow a symlink planted in the tree to a file outside it
            // (security review -- this may run as root).
            if (! (@lchown($path, $owner) && @lchgrp($path, $group))) {
                Log::warning("Could not hand {$root} to the web server user -- run: chown -R \$(stat -c %U:%G storage/app/public) {$root}");

                return;
            }
        }
    }

    // Security review (SCRUM-342): a symlink in an uploads directory must never make this copy some
    // other file on the box into private storage, where a route would then serve it. Only local
    // disks can hold one.
    private function isSymlink(string $disk, string $path): bool
    {
        return config("filesystems.disks.{$disk}.driver") === 'local'
            && is_link(Storage::disk($disk)->path($path));
    }

    private function copyToPrivate(string $path, string $privateDisk, string $sourceDisk): void
    {
        $stream = Storage::disk($sourceDisk)->readStream($path);

        try {
            Storage::disk($privateDisk)->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    // The public (and local) disks don't throw (config/filesystems.php), so a failed delete would
    // otherwise be silent -- leaving a private file behind, possibly in a publicly-served directory.
    private function deletePublicCopy(string $path, string $sourceDisk): void
    {
        if (! Storage::disk($sourceDisk)->delete($path)) {
            Log::error('Could not delete a file\'s source copy after moving it to private storage -- remove it by hand.', ['disk' => $sourceDisk, 'path' => $path]);
        }
    }
}
