<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Queries\Anchors\AnchorType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueryAnchor extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'query_anchors';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = ['query_id', 'type', 'message_ts', 'blocks_json', 'attachments_json'];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'blocks_json' => 'array',
        'attachments_json' => 'array',
        'type' => AnchorType::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the query that owns this anchor.
     */
    public function parentQuery(): BelongsTo
    {
        return $this->belongsTo(Query::class, 'query_id');
    }
}
