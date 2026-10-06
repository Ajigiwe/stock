<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shop extends Model
{
    protected $table = 'shops';

    public $incrementing = false;

    protected $keyType = 'string';

    /** No updated_at column: created_at comes from the table DEFAULT. */
    public $timestamps = false;

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'name',
        'location',
        'phone',
    ];

    /** @return HasMany<PhoneModel, $this> */
    public function phoneModels(): HasMany
    {
        return $this->hasMany(PhoneModel::class, 'shop_id');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'shop_id');
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'shop_id');
    }

    /** @return HasMany<StockAdjustment, $this> */
    public function stockAdjustments(): HasMany
    {
        return $this->hasMany(StockAdjustment::class, 'shop_id');
    }

    /** @return HasMany<StockRequest, $this> */
    public function stockRequests(): HasMany
    {
        return $this->hasMany(StockRequest::class, 'shop_id');
    }

    /** @return HasMany<SwappedPhone, $this> */
    public function swappedPhones(): HasMany
    {
        return $this->hasMany(SwappedPhone::class, 'shop_id');
    }

    /** @return HasMany<StockLog, $this> */
    public function stockLogs(): HasMany
    {
        return $this->hasMany(StockLog::class, 'shop_id');
    }

    /** @return HasMany<DailyClose, $this> */
    public function dailyCloses(): HasMany
    {
        return $this->hasMany(DailyClose::class, 'shop_id');
    }

    /** @return HasMany<StockCount, $this> */
    public function stockCounts(): HasMany
    {
        return $this->hasMany(StockCount::class, 'shop_id');
    }
}
