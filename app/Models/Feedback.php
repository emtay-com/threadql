<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Feedback model
 *
 * Represents user feedback and ratings for query results
 * to improve the system's performance.
 */
class Feedback extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'feedback';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = ['tenant_id', 'query_id', 'user_id', 'score', 'category', 'note'];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'score' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the tenant that owns this feedback.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the query that this feedback is for.
     */
    public function queryResult(): BelongsTo
    {
        return $this->belongsTo(Query::class);
    }
}
