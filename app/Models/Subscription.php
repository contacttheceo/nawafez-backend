<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'plan_id', 'status', 'billing_cycle',
        'started_at', 'expires_at', 'cancelled_at',
        'auto_renew', 'source', 'granted_by', 'last_payment_id', 'metadata',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'expires_at'   => 'datetime',
        'cancelled_at' => 'datetime',
        'auto_renew'   => 'boolean',
        'metadata'     => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && (!$this->expires_at || $this->expires_at->isFuture());
    }

    public function daysUntilExpiry(): ?int
    {
        if (!$this->expires_at) return null;
        return (int) max(0, now()->diffInDays($this->expires_at, false));
    }
}
