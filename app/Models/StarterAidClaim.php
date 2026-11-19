<?php

namespace OGame\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $reward
 * @property Carbon $claimed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @mixin \Eloquent
 */
#[Fillable([
    'user_id',
    'reward',
    'claimed_at',
])]
class StarterAidClaim extends Model
{
    /**
     * Get the user that owns the claim.
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
        ];
    }
}
