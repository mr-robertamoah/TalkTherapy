<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HowTo extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'page', 'user_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function howToSteps()
    {
        return $this->hasMany(HowToStep::class);
    }

    public function scopeWhereNameLike($query, string $name)
    {
        return $query->where('name', "LIKE", "%{$name}%");
    }

    public function scopeWherePageLike($query, string $page)
    {
        return $query->where('page', "LIKE", "%{$page}%");
    }

    public function scopeWhereAll($query)
    {
        return $query->where('page', "all");
    }

    public function scopeOrWhereAll($query)
    {
        return $query->orWhere('page', "all");
    }
}
