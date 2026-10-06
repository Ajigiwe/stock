<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyClose extends Model
{
    protected $table = 'daily_closes';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'shop_id',
        'close_date',
        'status',
        'expected_cash',
        'expected_mobile_money',
        'expected_other',
        'counted_cash',
        'counted_mobile_money',
        'counted_other',
        'notes',
        'submitted_by',
        'submitted_at',
        'locked_by',
        'locked_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'close_date' => 'date',
        'expected_cash' => 'decimal:2',
        'expected_mobile_money' => 'decimal:2',
        'expected_other' => 'decimal:2',
        'counted_cash' => 'decimal:2',
        'counted_mobile_money' => 'decimal:2',
        'counted_other' => 'decimal:2',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
