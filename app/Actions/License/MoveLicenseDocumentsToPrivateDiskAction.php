<?php

namespace App\Actions\License;

use App\Actions\Action;
use App\Models\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

// SCRUM-300: one-off move of every license document uploaded before the fix, from the public disk
// (licenses/, symlinked into public/) onto the private license_documents disk. Run by its own
// migration; kept as an action so it's testable and re-runnable. Idempotent: a file already moved
// is no longer on the public disk and is skipped. A row whose file is missing from disk is still
// switched to the private disk -- it was unreachable anyway, and leaving it on 'public' would keep
// File::url resolving a public link for it.
class MoveLicenseDocumentsToPrivateDiskAction extends Action
{
    private const PUBLIC_DIRECTORY = 'licenses';

    public function execute(): int
    {
        $moved = 0;

        File::query()
            ->where('storage', 'public')
            ->whereHas('licenses')
            // By id, not offset: each moved row drops out of this where('storage', 'public') filter,
            // which would make offset paging skip rows.
            ->lazyById()
            ->each(function (File $file) use (&$moved) {
                $path = $file->diskPath();
                $public = Storage::disk('public');

                if ($public->exists($path)) {
                    $this->copyToPrivate($path);
                } else {
                    Log::warning('License document missing from the public disk while moving it to private storage.', ['file_id' => $file->id]);
                }

                // Row first, then the public copy: if deleting fails, the file is merely orphaned
                // on the public disk, never left as a row pointing at a public location.
                $file->update(['storage' => 'license_documents']);
                $this->deletePublicCopy($path);

                $moved++;
            });

        // Anything else still under licenses/ (no license attached to its row, or no row at all: an
        // interrupted upload, manual testing, a crash between the row update and the delete above)
        // was served by the same public symlink -- only ever license documents, so it moves too.
        foreach (Storage::disk('public')->allFiles(self::PUBLIC_DIRECTORY) as $orphan) {
            $this->copyToPrivate($orphan);
            $this->deletePublicCopy($orphan);
            $moved++;
        }

        if ($left = Storage::disk('public')->allFiles(self::PUBLIC_DIRECTORY)) {
            Log::error('License documents are still on the public disk after the move -- remove them by hand.', ['paths' => $left]);
        }

        return $moved;
    }

    private function copyToPrivate(string $path): void
    {
        $stream = Storage::disk('public')->readStream($path);

        try {
            Storage::disk('license_documents')->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    // The public disk doesn't throw (config/filesystems.php), so a failed delete would otherwise be
    // silent -- leaving an identity document behind in a publicly-served directory.
    private function deletePublicCopy(string $path): void
    {
        if (! Storage::disk('public')->delete($path)) {
            Log::error('Could not delete a license document\'s public copy after moving it to private storage -- remove it by hand.', ['path' => $path]);
        }
    }
}
