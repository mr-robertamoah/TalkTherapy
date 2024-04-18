<?php

namespace App\Models;

use App\Traits\Starreable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Discussion extends Model
{
    use HasFactory,
    Starreable;

    public function messages()
    {
        return $this->morphMany(Message::class, 'for');
    }

    public function isNotParticipant(User $user)
    {
        return !$this->isParticipant($user);
    }

    public function isParticipant(User $user)
    {
        return false; // TODO
    }
}
