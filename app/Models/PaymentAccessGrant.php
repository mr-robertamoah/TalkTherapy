<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// SCRUM-218/TT-7.5a: a permanent, once-off fact -- "this user was granted access to this
// payable" -- never mutated or deleted after creation. Not an append-only/versioned history
// table (unlike TransactionStatusHistory or organization_counsellor_compensations): there is
// exactly one row per (user, for), enforced by a unique index, and it is never superseded.
class PaymentAccessGrant extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'for_type', 'for_id', 'transaction_id', 'granted_at'];

    protected $casts = [
        'granted_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Mirrors Transaction::for() -- the payable this grant covers, either a Therapy (per-therapy
    // payment) or a Session (per-session payment). GroupTherapy is out of scope (TT-7.5b).
    public function for()
    {
        return $this->morphTo();
    }

    // Kept only for audit traceability -- never read to determine whether access is still valid
    // (that's the whole point of this table existing independently of Transaction.status).
    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
