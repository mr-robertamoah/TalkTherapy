<?php

namespace App\Models;

use App\Support\Countries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// SCRUM-33: one verified emergency number for a country, shown in the "I need help now" dialog.
class EmergencyNumber extends Model
{
    protected $fillable = ['country_code', 'label', 'number', 'notes', 'source_url', 'verified_by', 'verified_at', 'position'];

    protected $casts = [
        'verified_at' => 'date',
    ];

    public function scopeForCountry(Builder $query, string $code): Builder
    {
        return $query->where('country_code', $code)->orderBy('position')->orderBy('id');
    }

    // Due for a fresh check (config help.number_review_months); still shown meanwhile.
    public function isReviewDue(): bool
    {
        return $this->verified_at->lte(now()->subMonths((int) config('help.number_review_months')));
    }

    public function countryName(): ?string
    {
        return Countries::nameOf($this->country_code);
    }
}
