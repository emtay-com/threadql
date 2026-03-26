<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Table model
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $schema_name
 * @property string $name
 * @property int $priority
 * @property ?int $row_count
 * @property ?float $size_mb
 * @property ?string $ddl_sql
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property ?Carbon $deleted_at
 * @property-read Tenant $tenant
 *
 * Represents a database table within a tenant's schema
 * with metadata and DDL information.
 */
class Table extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'tables';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = ['tenant_id', 'schema_name', 'name', 'priority', 'row_count', 'size_mb', 'ddl_sql'];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'priority' => 'integer',
        'row_count' => 'integer',
        'size_mb' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the tenant that owns this table.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
