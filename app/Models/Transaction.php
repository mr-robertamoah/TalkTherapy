<?php

namespace App\Models;

use App\Enums\TransactionStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = ['for_type', 'for_id', 'user_id', 'organization_id', 'reference', 'amount', 'currency', 'status'];

    protected $casts = [
        'amount' => 'integer',
    ];

    public function for()
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Null when this transaction was paid personally, not through an organization.
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function statusHistories()
    {
        return $this->hasMany(TransactionStatusHistory::class);
    }

    // TT-7.6b/SCRUM-226: never populated for an org-financed transaction (organization_id set) --
    // that split is TT-7.3b's job, layered on top of this payout mechanism once it exists.
    public function earnings()
    {
        return $this->hasMany(CounsellorEarning::class);
    }

    public function isSuccessful(): bool
    {
        return $this->status === TransactionStatusEnum::success->value;
    }

    public function scopeWhereReference($query, string $reference)
    {
        return $query->where('reference', $reference);
    }
}
