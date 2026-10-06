<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockRequest extends Model
{
    protected $table = 'stock_requests';

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
        'type',
        'status',
        'model_name',
        'condition',
        'cost_price',
        'sale_price',
        'low_stock_threshold',
        'opening_stock',
        'phone_model_id',
        'delta',
        'reason',
        'decided_at',
        'decided_by',
        'error_note',
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

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function phoneModel(): BelongsTo
    {
        return $this->belongsTo(PhoneModel::class, 'phone_model_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
