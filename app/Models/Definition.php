<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Definition model
 *
 * Represents a user-provided business definition.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $user_id
 * @property int|null $thread_id
 * @property int $priority
 * @property string $subject
 * @property string $definition
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Thread|null $thread
 */
class Definition extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'definitions';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = ['tenant_id', 'user_id', 'thread_id', 'priority', 'subject', 'definition'];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'priority' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the tenant that owns this definition.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the thread associated with this definition.
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }
}
