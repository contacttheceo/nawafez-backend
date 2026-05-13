<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name_ar', 'name_en', 'email', 'password',
        'phone', 'avatar', 'role', 'business_verification',
        'subscription_data', 'is_trusted_payer',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at'     => 'datetime',
            'password'              => 'hashed',
            'business_verification' => 'array',
            'subscription_data'     => 'array',
            'is_trusted_payer'      => 'boolean',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────────────────

    public function listings()
    {
        return $this->hasMany(Listing::class);
    }

    public function interactions()
    {
        return $this->hasMany(Interaction::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isVerifiedBusiness(): bool
    {
        return $this->role === 'business'
            && isset($this->business_verification['status'])
            && $this->business_verification['status'] === 'approved';
    }

    public function hasActiveSubscription(): bool
    {
        if (!$this->subscription_data) return false;
        return now()->lt($this->subscription_data['expires_at'] ?? '2000-01-01');
    }

    public function getDisplayNameAttribute(): string
    {
        return app()->getLocale() === 'ar' ? $this->name_ar : $this->name_en;
    }

    // Always expose avatar as avatar_url so the frontend never needs to know
    // about the internal column name
    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar;
    }

    protected $appends = ['avatar_url'];
}
