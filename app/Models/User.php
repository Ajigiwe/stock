<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Merges Supabase's auth.users (email/password) and public.users (profile)
 * into one row. `role` and `shop_id` are only ever written by owner-scoped
 * actions — the original enforced that with column-level GRANTs.
 */
class User extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'name',
        'email',
        'phone',
        'password',
        'role',
        'shop_id',
        'active',
        'deactivated_at',
        'deactivated_by',
    ];

    /** @var array<string, string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'active' => 'boolean',
        'deactivated_at' => 'datetime',
    ];

    public const ROLE_OWNER = 'owner';

    public const ROLE_ATTENDANT = 'attendant';

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }

    /** @return HasMany<StockLog, $this> */
    public function stockLogs(): HasMany
    {
        return $this->hasMany(StockLog::class, 'staff_id');
    }

    /** @return HasMany<LoginLog, $this> */
    public function loginLogs(): HasMany
    {
        return $this->hasMany(LoginLog::class, 'user_id');
    }
}
