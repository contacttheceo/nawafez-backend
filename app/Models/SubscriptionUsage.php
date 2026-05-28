<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionUsage extends Model
{
    use HasFactory;

    protected $table = 'subscription_usage';

    protected $fillable = [
        'user_id', 'period_yyyymm', 'listings_posted', 'featured_used', 'pins_used',
    ];

    protected $casts = [
        'listings_posted' => 'integer',
        'featured_used'   => 'integer',
        'pins_used'       => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
