<?php

namespace App\Models;

use App\Enums\ConstantsEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class License extends Model
{
    use HasFactory;

    protected $fillable = ['number', 'validated'];

    protected $casts = [
        'documents_removed_at' => 'datetime',
    ];

    public function licensingAuthority()
    {
        return $this->belongsTo(LicensingAuthority::class);
    }

    public function addedby()
    {
        return $this->morphTo('addedby');
    }

    public function for()
    {
        return $this->morphTo();
    }

    public function files(): MorphToMany
    {
        return $this
            ->morphToMany(File::class, 'fileable', 'fileables')
            ->withTimestamps();
    }

    public function validate()
    {
        return $this->update([
            'validated' => true,
        ]);
    }

    public function invalidate()
    {
        return $this->update([
            'validated' => false,
        ]);
    }

    // SCRUM-338: the one rule for "is this a national ID" -- the same one
    // Counsellor::hasNationalIdentification() uses.
    public function isNationalId(): bool
    {
        return $this->licensingAuthority?->name == ConstantsEnum::nationalId->value;
    }
}
