<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'listing_id',
        'user_id',
        'parent_id',
        'body',
        'is_official_answer',
        'is_marked_helpful',
        'upvotes_count',
    ];

    protected function casts(): array
    {
        return [
            'is_official_answer' => 'boolean',
            'is_marked_helpful'  => 'boolean',
            'upvotes_count'      => 'integer',
        ];
    }

    /* ── Relationships ───────────────────────────────────────────────────── */

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_id')->orderBy('created_at');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(CommentVote::class);
    }

    /* ── Scopes ──────────────────────────────────────────────────────────── */

    public function scopeTopLevel($query)
    {
        return $query->whereNull('parent_id');
    }
}
