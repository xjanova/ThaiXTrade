<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CarbonRetirement extends Model
{
    use HasFactory;

    protected $fillable = [
        'carbon_credit_id',
        'retiree_address',
        'beneficiary_name',
        'retirement_reason',
        'amount',
        'certificate_hash',
        'tx_hash',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function credit()
    {
        return $this->belongsTo(CarbonCredit::class, 'carbon_credit_id');
    }
}
