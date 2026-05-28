<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name_ar', 'name_en', 'tagline_ar', 'tagline_en',
        'price_monthly', 'price_yearly', 'features',
        'display_order', 'is_active', 'is_default', 'badge_color',
    ];

    protected $casts = [
        'features'      => 'array',
        'price_monthly' => 'integer',
        'price_yearly'  => 'integer',
        'is_active'     => 'boolean',
        'is_default'    => 'boolean',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Read a feature with a default fallback — keeps controllers/middleware
     * concise: $plan->feature('max_listings', 3).
     *
     * Special: -1 means unlimited.
     */
    public function feature(string $key, mixed $default = null): mixed
    {
        return data_get($this->features, $key, $default);
    }

    public function hasUnlimited(string $key): bool
    {
        return (int) $this->feature($key) === -1;
    }
}
