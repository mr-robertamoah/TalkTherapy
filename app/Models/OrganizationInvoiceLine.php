<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrganizationInvoiceLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_invoice_id', 'session_id', 'counsellor_id', 'net_amount', 'fee_amount', 'currency',
    ];

    protected $casts = [
        'net_amount' => 'integer',
        'fee_amount' => 'integer',
    ];

    public function organizationInvoice()
    {
        return $this->belongsTo(OrganizationInvoice::class);
    }

    public function session()
    {
        return $this->belongsTo(Session::class);
    }

    public function counsellor()
    {
        // withTrashed: mirrors Therapy::counsellor()'s own precedent -- the settlement gap
        // between a session occurring and month-end settlement can be weeks (the whole reason
        // this line's amount is locked in at held-time rather than recomputed later), long enough
        // for the counsellor to have since deleted their account. An already-earned line must
        // still generate its CounsellorEarning regardless.
        return $this->belongsTo(Counsellor::class)->withTrashed();
    }
}
