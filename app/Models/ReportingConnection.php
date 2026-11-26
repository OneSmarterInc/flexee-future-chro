<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One administrator's sign-in to the reporting connector. Tokens are kept only as hashes.
 *
 * @property string $id
 * @property string $client_id
 * @property int $user_id
 * @property string $scope
 * @property string $access_hash
 * @property CarbonImmutable $access_expires_at
 * @property string $refresh_hash
 * @property CarbonImmutable $refresh_expires_at
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $revoked_at
 * @property int|null $revoked_by_user_id
 * @property-read ReportingClient $client
 * @property-read User|null $user
 */
class ReportingConnection extends Model
{
    use HasUuids;

    protected $fillable = ['client_id', 'user_id', 'scope', 'access_hash', 'access_expires_at', 'refresh_hash', 'refresh_expires_at', 'last_used_at', 'revoked_at', 'revoked_by_user_id'];

    protected $hidden = ['access_hash', 'refresh_hash'];

    protected function casts(): array
    {
        return [
            'access_expires_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ReportingClient, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(ReportingClient::class, 'client_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    /** @return HasMany<ReportingCall, $this> */
    public function calls(): HasMany
    {
        return $this->hasMany(ReportingCall::class, 'connection_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->refresh_expires_at->isFuture();
    }
}
