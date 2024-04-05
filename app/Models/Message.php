<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use HasFactory,
    SoftDeletes;

    protected $fillable = ['content', 'type', 'confidential'];

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

    public function replying()
    {
        return $this->belongsTo(Message::class, 'message_id');
    }

    public function replies()
    {
        return $this->hasMany(Message::class, 'message_id');
    }

    public function files(): MorphToMany
    {
        return $this
            ->morphToMany(File::class, 'fileable', 'fileables')
            ->withTimestamps();
    }
}
