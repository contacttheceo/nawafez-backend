<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'user_id', 'listing_id', 'payment_type',
        'amount', 'currency', 'gateway', 'gateway_ref',
        'status', 'webhook_data', 'metadata', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'webhook_data' => 'array',
            'metadata'     => 'array',
            'paid_at'      => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function listing()
    {
        return $this->belongsTo(Listing::class);
    }

    // Amount in SAR (stored as halalas)
    public function getAmountInSarAttribute(): float
    {
        return $this->amount / 100;
    }
}
