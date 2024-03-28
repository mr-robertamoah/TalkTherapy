<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Message extends Model
{
    use HasFactory;

    protected $fillable = ['content'];

    public function from()
    {
        return $this->morphTo();
    }

    public function to()
    {
        return $this->morphTo();
    }

    public function for()
    {
        return $this->morphTo();
    }

    public function therapyTopic()
    {
        return $this->belongsTo(TherapyTopic::class);
    }

    public function files(): MorphToMany
    {
        return $this
            ->morphToMany(File::class, 'fileable', 'fileables')
            ->withTimestamps();
    }
}
