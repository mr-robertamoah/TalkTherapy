<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HowToStep extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'user_id', 'how_to_id', 'position', 'file_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function file()
    {
        return $this->belongsTo(File::class);
    }

    public function howTo()
    {
        return $this->belongsTo(HowTo::class);
    }

    public function scopeWhereNotIds($query, array $ids)
    {
        return $query->whereNotIn('id', $ids);
    }
}
