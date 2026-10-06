<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionEvent extends Model
{
    protected $table = 'transaction_events';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'transaction_id',
        'actor_id',
        'action',
        'details',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'details' => 'array',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
