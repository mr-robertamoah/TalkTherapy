<?php

namespace App\Models;

use App\Enums\LinkStateEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Link extends Model
{
    use HasFactory;

    protected $fillable = ['type', 'state', 'uuid'];

    public function getUrlAttribute()
    {
        return "www.talktherapy.tech/links/{$this->uuid}";
    }

    public function addedby()
    {
        return $this->morphTo('addedby');
    }

    public function for()
    {
        return $this->morphTo('for');
    }

    public function to()
    {
        return $this->morphTo('to');
    }

    public function deactivate()
    {
        $this->state = LinkStateEnum::inactive->value;
        $this->save();
    }

    public function activate()
    {
        $this->state = LinkStateEnum::active->value;
        $this->save();
    }

    public function scopeWhereType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeWhereState($query, $state)
    {
        return $query->where('state', $state);
    }

    public function scopeWhereAddedby($query, $addedby)
    {
        if (is_null($addedby)) return $query;
        
        return $query
            ->where('addedby_type', $addedby::class)
            ->where('addedby_id', $addedby->id);
    }

    public function scopeWhereTo($query, $to)
    {
        if (is_null($to)) return $query;
        
        return $query
            ->where('to_type', $to::class)
            ->where('to_id', $to->id);
    }

    public function scopeWhereFor($query, $for)
    {
        if (is_null($for)) return $query;
        
        return $query
            ->where('for_type', $for::class)
            ->where('for_id', $for->id);
    }
}
