<?php

namespace OGame\Enums;

use OGame\Models\Resources;

/**
 * Starter Aid rewards displayed on the /rewards page.
 *
 * Rewards are dispatched daily starting on the second day after registration,
 * one per day, and can be collected manually on the page. Vanilla OGame dispatches
 * seven rewards; its own tooltip says "From the 7th day on, no further rewards will
 * be sent out" while also promising seven daily rewards starting on day 2, so the
 * dispatch days here run from day 2 through day 8 to satisfy the reward count.
 *
 * Payloads:
 * - The Bronze tier robots (KRAKEN/NEWTRON/DETROID) reduce the currently running
 *   building/research/shipyard order by 30 minutes, with any leftover reduction
 *   carrying over into the next order in the queue. This matches the documented
 *   Bronze tier item effects in OGame.
 * - The resource/defense payloads for Supplies, Merchant and Defense are not
 *   documented in any available source; the values below are best-effort defaults
 *   chosen to match the flavour text of each reward and can be adjusted here.
 */
enum StarterAidReward: string
{
    /**
     * "Let`s go Emperor": supplies from the colony ship. Value not documented upstream.
     */
    case Supplies = 'supplies';

    /**
     * "The colony is growing!": Bronze KRAKEN robot (current building time -30 min).
     */
    case Growth = 'growth';

    /**
     * "Supply and demand": gift to help the resource merchant's wares along.
     * Value not documented upstream; the flavour text points at a crystal shortage.
     */
    case Merchant = 'merchant';

    /**
     * "Progress through technology": rocket launchers to protect the new home.
     * Amount not documented upstream.
     */
    case Defense = 'defense';

    /**
     * "Progress through technology": Bronze NEWTRON robot (current research time -30 min).
     */
    case Science = 'science';

    /**
     * "Conquer outer space": Bronze DETROID robot (current shipyard time -30 min).
     */
    case Conquest = 'conquest';

    /**
     * "Expansion of the empire": all officers (Commanding Staff) for 3 days.
     */
    case Empire = 'empire';

    /**
     * All rewards in dispatch order.
     *
     * @return array<int, StarterAidReward>
     */
    public static function ordered(): array
    {
        return [
            self::Supplies,
            self::Growth,
            self::Merchant,
            self::Defense,
            self::Science,
            self::Conquest,
            self::Empire,
        ];
    }

    /**
     * The registration day (1 = day of registration) on which this reward is dispatched.
     */
    public function getDispatchDay(): int
    {
        return 2 + array_search($this, self::ordered(), true);
    }

    /**
     * CSS class for the reward tile image, matching the original page markup.
     */
    public function getIconClass(): string
    {
        return 'rewardlistimg_' . (1 << array_search($this, self::ordered(), true));
    }

    /**
     * Resources granted by this reward, or null.
     */
    public function getResourceGrant(): Resources|null
    {
        return match ($this) {
            // 50k metal as "colony ship supplies" (best-effort, not documented upstream).
            self::Supplies => new Resources(50000, 0, 0),
            // 50k crystal for the "no more crystals" flavour text (best-effort, not documented upstream).
            self::Merchant => new Resources(0, 50000, 0),
            default => null,
        };
    }

    /**
     * Defense units granted by this reward, or null.
     *
     * @return array{machine_name: string, amount: int}|null
     */
    public function getUnitGrant(): array|null
    {
        return match ($this) {
            // 20 rocket launchers (best-effort, not documented upstream).
            self::Defense => ['machine_name' => 'rocket_launcher', 'amount' => 20],
            default => null,
        };
    }

    /**
     * Queue type whose current order this reward reduces, or null.
     */
    public function getQueueReductionType(): string|null
    {
        return match ($this) {
            self::Growth => 'building',
            self::Science => 'research',
            self::Conquest => 'unit',
            default => null,
        };
    }

    /**
     * Seconds to reduce from the current (and, by carry over, following) queue
     * orders. 1800 = Bronze tier robot reduction (documented upstream).
     */
    public function getQueueReductionSeconds(): int
    {
        return $this->getQueueReductionType() !== null ? 1800 : 0;
    }

    /**
     * Number of days to grant all officers (Commanding Staff) for, or 0.
     */
    public function getOfficerGrantDays(): int
    {
        return match ($this) {
            self::Empire => 3,
            default => 0,
        };
    }
}
