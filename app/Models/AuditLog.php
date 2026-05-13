<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'admin_id', 'action', 'target_type', 'target_id',
        'metadata', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
