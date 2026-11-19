<?php

namespace OGame\Services;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Enums\Officer;
use OGame\Enums\StarterAidReward;
use OGame\GameMessages\StarterAid;
use OGame\Models\StarterAidClaim;
use OGame\Models\User;

/**
 * Class StarterAidService.
 *
 * Manages the Starter Aid rewards on the /rewards page: rewards are dispatched
 * daily (starting on the second day after registration, one per day) and each can
 * be claimed exactly once. Claiming applies the reward payload to the player's
 * current planet/account and sends the Starter Aid game message.
 *
 * @package OGame\Services
 */
class StarterAidService
{
    /**
     * StarterAidService constructor.
     */
    public function __construct(
        private QueueTimeReductionService $queueTimeReductionService,
        private OfficerService $officerService,
        private MessageService $messageService
    ) {
    }

    /**
     * Get all Starter Aid rewards grouped by claim state for the rewards page.
     *
     * @return array{new: array<int, StarterAidReward>, not_reached: array<int, StarterAidReward>, collected: array<int, StarterAidReward>}
     */
    public function getGroupedRewards(PlayerService $player): array
    {
        $claimedRewards = StarterAidClaim::where('user_id', $player->getId())
            ->pluck('reward')
            ->toArray();

        $grouped = [
            'new' => [],
            'not_reached' => [],
            'collected' => [],
        ];

        foreach (StarterAidReward::ordered() as $reward) {
            if (in_array($reward->value, $claimedRewards, true)) {
                $grouped['collected'][] = $reward;
            } elseif ($this->isRewardUnlocked($player->getUser(), $reward)) {
                $grouped['new'][] = $reward;
            } else {
                $grouped['not_reached'][] = $reward;
            }
        }

        return $grouped;
    }

    /**
     * Check if the given reward has been dispatched yet (its registration day has
     * been reached) and is not yet claimed.
     */
    public function isRewardClaimable(PlayerService $player, StarterAidReward $reward): bool
    {
        return $this->isRewardUnlocked($player->getUser(), $reward)
            && !StarterAidClaim::where('user_id', $player->getId())
                ->where('reward', $reward->value)
                ->exists();
    }

    /**
     * Claim the given Starter Aid reward: applies the reward payload atomically
     * (once per user and reward) and sends the Starter Aid game message.
     *
     * @param PlayerService $player
     * @param StarterAidReward $reward
     * @return bool True when the reward was claimed, false when it was not claimable
     *              (not dispatched yet, already claimed or a concurrent claim won).
     */
    public function claim(PlayerService $player, StarterAidReward $reward): bool
    {
        /** @var bool $claimed */
        $claimed = DB::transaction(function () use ($player, $reward): bool {
            // Lock the user row so concurrent claims for the same player cannot both
            // pass the claimability check below.
            $lockedUser = User::where('id', $player->getId())->lockForUpdate()->first();
            if ($lockedUser === null) {
                return false;
            }

            if (!$this->isRewardUnlocked($lockedUser, $reward)) {
                return false;
            }

            // The unique index on (user_id, reward) is the final guard against double
            // claims across concurrent requests.
            $exists = StarterAidClaim::where('user_id', $player->getId())
                ->where('reward', $reward->value)
                ->exists();
            if ($exists) {
                return false;
            }

            $this->applyPayload($lockedUser, $player, $reward);

            StarterAidClaim::create([
                'user_id' => $player->getId(),
                'reward' => $reward->value,
                'claimed_at' => Date::now(),
            ]);

            $this->messageService->sendSystemMessageToPlayer(
                $player,
                StarterAid::class,
                ['text' => $this->getRewardMessage($reward, $player->getUsername(false))]
            );

            return true;
        });

        return $claimed;
    }

    /**
     * Get the Starter Aid message text for the given reward, personalized with the
     * given player name.
     */
    public function getRewardMessage(StarterAidReward $reward, string $name): string
    {
        return str_replace(':name', $name, (string)__('t_rewards.rewards.' . $reward->value . '.message'));
    }

    /**
     * Apply the reward payload to the player's current planet and account.
     */
    private function applyPayload(User $lockedUser, PlayerService $player, StarterAidReward $reward): void
    {
        $planet = $player->planets->current();

        $resourceGrant = $reward->getResourceGrant();
        if ($resourceGrant !== null) {
            $planet->addResourcesAtomic($resourceGrant);
        }

        $unitGrant = $reward->getUnitGrant();
        if ($unitGrant !== null) {
            $planet->addUnitAtomic($unitGrant['machine_name'], $unitGrant['amount']);
        }

        $queueType = $reward->getQueueReductionType();
        if ($queueType !== null) {
            $seconds = $reward->getQueueReductionSeconds();
            match ($queueType) {
                'building' => $this->queueTimeReductionService->reduceBuildingTime($planet, $seconds),
                'research' => $this->queueTimeReductionService->reduceResearchTime($player, $seconds),
                'unit' => $this->queueTimeReductionService->reduceUnitTime($planet, $seconds),
                default => null,
            };
        }

        $officerDays = $reward->getOfficerGrantDays();
        if ($officerDays > 0) {
            // The Commanding Staff reward grants all officers at once.
            foreach (Officer::cases() as $officer) {
                $this->officerService->grant($lockedUser, $officer, $officerDays);
            }
        }
    }

    /**
     * Check if the reward dispatch day has been reached for the given user.
     * Day 1 is the day of registration, so the reward for dispatch day N is
     * unlocked once N - 1 days have passed since registration.
     */
    private function isRewardUnlocked(User $user, StarterAidReward $reward): bool
    {
        if ($user->created_at === null) {
            return false;
        }

        $unlockAt = $user->created_at->copy()->addDays($reward->getDispatchDay() - 1);

        return Date::now()->gte($unlockAt);
    }
}
