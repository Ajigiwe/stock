<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginLog extends Model
{
    protected $table = 'login_logs';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'user_id',
        'email',
        'name',
        'ip',
        'user_agent',
        'device',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
