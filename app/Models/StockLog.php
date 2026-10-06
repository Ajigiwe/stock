<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLog extends Model
{
    protected $table = 'stock_logs';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'shop_id',
        'phone_model_id',
        'staff_id',
        'action',
        'model_name',
        'condition',
        'details',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'details' => 'array',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'shop_id');
    }

    public function phoneModel(): BelongsTo
    {
        return $this->belongsTo(PhoneModel::class, 'phone_model_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }
}
