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
    public const PRIVATE_DISKS = ['identity_documents', 'license_documents', 'message_attachments', 'report_evidence'];

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

    // SCRUM-336: the messages a file is attached to (chat attachments).
    public function messages(): MorphToMany
    {
        return $this
            ->morphedByMany(Message::class, 'fileable', 'fileables')
            ->withTimestamps();
    }

    // SCRUM-337: the reports a file is evidence for.
    public function reports(): MorphToMany
    {
        return $this
            ->morphedByMany(Report::class, 'fileable', 'fileables')
            ->withTimestamps();
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
