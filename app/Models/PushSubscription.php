<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'endpoint',
        'p256dh',
        'auth',
        'user_agent',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Shape expected by the Vercel /api/push/send endpoint (matches
     * the W3C PushSubscription.toJSON() output).
     */
    public function toPushPayload(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'keys'     => [
                'p256dh' => $this->p256dh,
                'auth'   => $this->auth,
            ],
        ];
    }
}
