<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class File extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'mime', 'size', 'path', 'storage',
    ];

    // Disks whose files must never get a public URL -- see config/filesystems.php. getUrlFor()
    // refuses them; each has its own authorized retrieval route instead.
    public const PRIVATE_DISKS = ['identity_documents', 'license_documents'];

    public function isOnPrivateDisk(): bool
    {
        return in_array($this->storage, self::PRIVATE_DISKS, true);
    }

    // The path within its disk, as stored by FileService::uploadFile().
    public function diskPath(): string
    {
        return (strlen($this->path) ? $this->path.'/' : '').$this->name;
    }

    public function getUrlAttribute()
    {
        return getUrlFor($this);
    }

    public function licenses(): MorphToMany
    {
        return $this
            ->morphedByMany(License::class, 'fileable', 'fileables')
            ->withTimestamps();
    }

    // TT-4.11a/SCRUM-302: mirrors licenses() above, for identity-verification document
    // attachments on a Request row.
    public function requests(): MorphToMany
    {
        return $this
            ->morphedByMany(Request::class, 'fileable', 'fileables')
            ->withTimestamps();
    }
}
