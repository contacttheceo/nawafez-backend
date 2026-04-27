<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Listing extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id', 'section', 'listing_type',
        'title_ar', 'title_en', 'description_ar', 'description_en',
        'city', 'region', 'price', 'currency', 'price_type',
        'status', 'rejection_reason',
        'dynamic_data', 'media',
        'is_featured', 'is_financing_eligible', 'is_ready_to_operate',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'dynamic_data'           => 'array',
            'media'                  => 'array',
            'is_featured'            => 'boolean',
            'is_financing_eligible'  => 'boolean',
            'is_ready_to_operate'    => 'boolean',
            'expires_at'             => 'datetime',
            'price'                  => 'integer',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function interactions()
    {
        return $this->hasMany(Interaction::class);
    }

    public function bids()
    {
        return $this->hasMany(Interaction::class)->where('type', 'bid');
    }

    public function bookmarks()
    {
        return $this->hasMany(Interaction::class)->where('type', 'bookmark');
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
                     ->where(function ($q) {
                         $q->whereNull('expires_at')
                           ->orWhere('expires_at', '>', now());
                     });
    }

    public function scopeSection($query, string $section)
    {
        return $query->where('section', $section);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeInCity($query, string $city)
    {
        return $query->where('city', $city);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->whereFullText(
            ['title_ar', 'title_en', 'description_ar', 'description_en'],
            $term
        );
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function getPrimaryImageAttribute(): ?string
    {
        $media = $this->media ?? [];
        foreach ($media as $item) {
            if ($item['type'] === 'image' && ($item['is_primary'] ?? false)) {
                return $item['path'];
            }
        }
        return $media[0]['path'] ?? null;
    }

    public function incrementViews(): void
    {
        $this->increment('views_count');
    }

    // Auto-compute financing eligibility for M&A listings
    public function computeFinancingEligibility(): bool
    {
        if ($this->section !== 'ma') return false;

        $data = $this->dynamic_data ?? [];
        return ($this->price >= 500000)
            && (($data['business_age'] ?? 0) >= 2)
            && !empty($data['monthly_revenue']);
    }

    // Auto-compute ready-to-operate for fleet listings
    public function computeReadyToOperate(): bool
    {
        if ($this->section !== 'fleet') return false;

        $data = $this->dynamic_data ?? [];
        return ($data['operation_card_status'] ?? '') === 'active'
            && in_array($data['plate_type'] ?? '', ['white', 'yellow']);
    }
}
