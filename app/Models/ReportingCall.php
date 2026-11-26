<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A call to a reporting tool. The rows it returned are never stored.
 *
 * @property int $id
 * @property string $connection_id
 * @property string $tool
 * @property string $status
 * @property int $rows
 * @property int $ms
 * @property CarbonImmutable|null $created_at
 */
class ReportingCall extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['connection_id', 'tool', 'status', 'rows', 'ms'];

    protected function casts(): array
    {
        return ['rows' => 'integer', 'ms' => 'integer', 'created_at' => 'datetime'];
    }
}
