<?php

namespace OGame\Services;

use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OGame\Enums\DarkMatterTransactionType;
use OGame\Enums\Officer;
use OGame\Models\User;

/**
 * Class OfficerService.
 *
 * Handles hiring of officers (Commander, Admiral, Engineer, Geologist, Technocrat)
 * with Dark Matter. Hired officers remain active for a fixed amount of weeks; hiring
 * again while active extends the expiry.
 *
 * @package OGame\Services
 */
class OfficerService
{
    /**
     * Maximum amount of weeks an officer can be hired for in a single purchase.
     */
    public const MAX_WEEKS_PER_PURCHASE = 13;

    /**
     * Seconds in a week and a day, for expiry calculations.
     */
    private const SECONDS_PER_WEEK = 604800;
    private const SECONDS_PER_DAY = 86400;

    /**
     * OfficerService constructor.
     *
     * @param DarkMatterService $darkMatterService
     */
    public function __construct(
        private DarkMatterService $darkMatterService
    ) {
    }

    /**
     * Get the total cost to hire the given officer for the given amount of weeks.
     *
     * @param Officer $officer
     * @param int $weeks
     * @return int
     */
    public function getHireCost(Officer $officer, int $weeks = 1): int
    {
        return $officer->getCostPerWeek() * $weeks;
    }

    /**
     * Check if the user can afford to hire the given officer.
     *
     * @param User $user
     * @param Officer $officer
     * @param int $weeks
     * @return bool
     */
    public function canAfford(User $user, Officer $officer, int $weeks = 1): bool
    {
        return $this->darkMatterService->canAfford($user, $this->getHireCost($officer, $weeks));
    }

    /**
     * Hire (or extend) an officer for the given amount of weeks.
     *
     * If the officer is still active, the expiry is extended from the current expiry;
     * otherwise a fresh period starts from now.
     *
     * @param User $user
     * @param Officer $officer
     * @param int $weeks
     * @return void
     * @throws Exception
     */
    public function hire(User $user, Officer $officer, int $weeks = 1): void
    {
        if ($weeks < 1 || $weeks > self::MAX_WEEKS_PER_PURCHASE) {
            throw new Exception('Invalid amount of weeks to hire officer for');
        }

        $cost = $this->getHireCost($officer, $weeks);

        // Check if user can afford the hire
        if (!$this->darkMatterService->canAfford($user, $cost)) {
            throw new Exception('Not enough Dark Matter to hire ' . $officer->getName());
        }

        DB::transaction(function () use ($user, $officer, $weeks, $cost) {
            // Debit the Dark Matter (internally locks the user row for update)
            $this->darkMatterService->debit(
                $user,
                $cost,
                DarkMatterTransactionType::COMMANDING_STAFF->value,
                'Hired ' . $officer->getName() . ' officer for ' . $weeks . (($weeks === 1) ? ' week' : ' weeks')
            );

            // Lock the user row and extend the officer expiry. The new period starts
            // from the current expiry if the officer is still active, else from now.
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();
            if ($lockedUser === null) {
                throw new Exception('User not found.');
            }

            $column = $officer->getExpiresAtColumn();
            $lockedUser->{$column} = $this->calculateExtendedExpiry($lockedUser, $officer, $weeks * self::SECONDS_PER_WEEK);
            $lockedUser->save();

            // Reflect the change on the caller's user instance
            $user->{$column} = $lockedUser->{$column};
        });
    }

    /**
     * Grant (or extend) an officer for the given amount of days without charging
     * Dark Matter. Used for reward grants such as the Starter Aid "Commanding Staff"
     * reward. If the officer is still active, the expiry is extended from the current
     * expiry; otherwise a fresh period starts from now.
     *
     * @param User $user
     * @param Officer $officer
     * @param int $days
     * @return void
     * @throws Exception
     */
    public function grant(User $user, Officer $officer, int $days): void
    {
        if ($days < 1) {
            throw new Exception('Invalid amount of days to grant officer for');
        }

        DB::transaction(function () use ($user, $officer, $days): void {
            // Lock the user row and extend the officer expiry, same as a paid hire.
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();
            if ($lockedUser === null) {
                throw new Exception('User not found.');
            }

            $column = $officer->getExpiresAtColumn();
            $lockedUser->{$column} = $this->calculateExtendedExpiry($lockedUser, $officer, $days * self::SECONDS_PER_DAY);
            $lockedUser->save();

            // Reflect the change on the caller's user instance
            $user->{$column} = $lockedUser->{$column};
        });
    }

    /**
     * Calculate the new officer expiry when extending by the given amount of seconds.
     * The new period starts from the current expiry if the officer is still active,
     * else from now.
     *
     * @param User $lockedUser User row already locked for update.
     * @param Officer $officer
     * @param int $seconds
     * @return Carbon
     */
    private function calculateExtendedExpiry(User $lockedUser, Officer $officer, int $seconds): Carbon
    {
        $column = $officer->getExpiresAtColumn();
        $currentExpiry = $lockedUser->{$column};
        $baseTime = ($currentExpiry !== null && $currentExpiry->isFuture()) ? $currentExpiry : now();

        return $baseTime->addSeconds($seconds);
    }

    /**
     * Get the amount of full days the given officer is still active for the user.
     *
     * @param User $user
     * @param Officer $officer
     * @return int
     */
    public function getRemainingDays(User $user, Officer $officer): int
    {
        $expiresAt = $user->{$officer->getExpiresAtColumn()};

        if (!$expiresAt instanceof Carbon) {
            return 0;
        }

        $secondsLeft = $expiresAt->getTimestamp() - now()->getTimestamp();

        if ($secondsLeft <= 0) {
            return 0;
        }

        return (int)ceil($secondsLeft / 86400);
    }
}
