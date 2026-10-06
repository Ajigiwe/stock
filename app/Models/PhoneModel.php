<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `available` is intentionally NOT fillable: the model_stock_normalize /
 * item_stock_change / stock_adjustment_change triggers own it (same as the
 * original, where the column-level GRANT blocked client writes).
 */
class PhoneModel extends Model
{
    protected $table = 'phone_models';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'shop_id',
        'model_name',
        'condition',
        'cost_price',
        'sale_price',
        'opening_stock',
        'bought_in',
        'low_stock_threshold',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'cost_price' => 'decimal:2',
        'sale_price' => 'decimal:2',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    /** @return HasMany<TransactionItem, $this> */
    public function transactionItems(): HasMany
    {
        return $this->hasMany(TransactionItem::class, 'phone_model_id');
    }

    /** @return HasMany<StockAdjustment, $this> */
    public function stockAdjustments(): HasMany
    {
        return $this->hasMany(StockAdjustment::class, 'phone_model_id');
    }

    /** @return HasMany<StockRequest, $this> */
    public function stockRequests(): HasMany
    {
        return $this->hasMany(StockRequest::class, 'phone_model_id');
    }

    /** @return HasMany<StockCountItem, $this> */
    public function stockCountItems(): HasMany
    {
        return $this->hasMany(StockCountItem::class, 'phone_model_id');
    }

    /** @return HasMany<StockLog, $this> */
    public function stockLogs(): HasMany
    {
        return $this->hasMany(StockLog::class, 'phone_model_id');
    }
}
