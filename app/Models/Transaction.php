<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    protected $table = 'transactions';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'shop_id',
        'staff_id',
        'customer_name',
        'customer_phone',
        'type',
        'payment_method',
        'amount',
        'date',
        'idempotency_key',
        'status',
        'listed_amount',
        'review_reason',
        'discount_reason',
        'payment_reference',
        'reviewed_by',
        'reviewed_at',
        'voided_by',
        'voided_at',
        'void_reason',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'amount' => 'decimal:2',
        'listed_amount' => 'decimal:2',
        'date' => 'date',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** @return HasMany<TransactionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class, 'transaction_id');
    }

    /** @return HasMany<TransactionEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TransactionEvent::class, 'transaction_id');
    }

    /** @return HasMany<SwappedPhone, $this> */
    public function swappedPhones(): HasMany
    {
        return $this->hasMany(SwappedPhone::class, 'transaction_id');
    }
}
