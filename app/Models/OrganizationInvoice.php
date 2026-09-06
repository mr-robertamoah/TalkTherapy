<?php

namespace App\Models;

use App\Enums\OrganizationInvoiceStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrganizationInvoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'currency', 'period_start', 'period_end', 'status', 'amount',
    ];

    protected $casts = [
        // date:Y-m-d, not a bare 'date' cast -- a bare 'date' cast still SERIALIZES via the
        // connection's full datetime format on write (only the read side truncates to a day), so
        // an OrganizationInvoice created with period_start => '2026-09-01' would actually persist
        // '2026-09-01 00:00:00'. RecordOrganizationInvoiceLineForSessionAction's own
        // firstOrCreate() lookup compares against a bare Y-m-d string, which would then never
        // match an existing row, causing a duplicate-invoice attempt (and a unique constraint
        // violation) on every session after the first one in a period.
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'amount' => 'integer',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function lines()
    {
        return $this->hasMany(OrganizationInvoiceLine::class);
    }

    // TT-7.3b-e/SCRUM-236: mirrors Therapy/Session/GroupTherapy's own transactions() relation --
    // this is what Transaction::for() polymorphically points at for a settlement charge.
    public function transactions()
    {
        return $this->morphMany(Transaction::class, 'for');
    }

    public function isOpen(): bool
    {
        return $this->status === OrganizationInvoiceStatusEnum::open->value;
    }
}
