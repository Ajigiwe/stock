<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountItem extends Model
{
    protected $table = 'stock_count_items';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'count_id',
        'phone_model_id',
        'expected_qty',
        'counted_qty',
    ];

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'count_id');
    }

    public function phoneModel(): BelongsTo
    {
        return $this->belongsTo(PhoneModel::class, 'phone_model_id');
    }
}
