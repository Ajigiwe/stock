<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionItem extends Model
{
    protected $table = 'transaction_items';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'transaction_id',
        'phone_model_id',
        'direction',
        'qty',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function phoneModel(): BelongsTo
    {
        return $this->belongsTo(PhoneModel::class, 'phone_model_id');
    }
}
