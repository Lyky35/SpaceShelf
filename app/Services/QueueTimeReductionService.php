<?php

namespace OGame\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Models\BuildingQueue;
use OGame\Models\ResearchQueue;
use OGame\Models\UnitQueue;

/**
 * Class QueueTimeReductionService.
 *
 * Reduces running construction/research/shipyard queues by a fixed amount of time,
 * mirroring the vanilla OGame robot items (KRAKEN, NEWTRON and DETROID): the
 * reduction is applied to the currently running order first and any leftover
 * reduction carries over into the following orders in the queue.
 *
 * @package OGame\Services
 */
class QueueTimeReductionService
{
    /**
     * Reduce the building queue of the given planet by the given amount of seconds.
     *
     * @param PlanetService $planet
     * @param int $seconds
     * @return int The amount of seconds actually reduced.
     */
    public function reduceBuildingTime(PlanetService $planet, int $seconds): int
    {
        $query = BuildingQueue::query()
            ->where('planet_id', $planet->getPlanetId())
            ->where('processed', 0)
            ->where('canceled', 0)
            ->where('building', 1);

        return $this->reduceQueue($query, $seconds, hasTimeProgress: false);
    }

    /**
     * Reduce the research queue of the given player by the given amount of seconds.
     *
     * The research queue is account wide (not per planet), so all planets of the
     * player are reduced.
     *
     * @param PlayerService $player
     * @param int $seconds
     * @return int The amount of seconds actually reduced.
     */
    public function reduceResearchTime(PlayerService $player, int $seconds): int
    {
        $query = ResearchQueue::query()
            ->join('planets', 'research_queues.planet_id', '=', 'planets.id')
            ->where('planets.user_id', $player->getId())
            ->where('research_queues.processed', 0)
            ->where('research_queues.canceled', 0)
            ->where('research_queues.building', 1)
            ->select('research_queues.*');

        return $this->reduceQueue($query, $seconds, hasTimeProgress: false);
    }

    /**
     * Reduce the unit queue of the given planet by the given amount of seconds.
     *
     * @param PlanetService $planet
     * @param int $seconds
     * @return int The amount of seconds actually reduced.
     */
    public function reduceUnitTime(PlanetService $planet, int $seconds): int
    {
        $query = UnitQueue::query()
            ->where('planet_id', $planet->getPlanetId())
            ->where('processed', 0);

        return $this->reduceQueue($query, $seconds, hasTimeProgress: true);
    }

    /**
     * Apply a time reduction to a queue, carried over from order to order.
     *
     * Queue items are chained: an item's time_start equals the previous item's
     * time_end. The reduction is first applied to the currently running order
     * (bounded by its remaining time) and then flows into the following orders
     * (bounded by each order's full duration). Every order is shifted back in
     * time by the total reduction consumed up to and including itself, which
     * keeps the queue chained and the per-unit rates of unit orders intact.
     * For unit orders time_progress is shifted along with the order so the
     * normal queue processor awards the units that correspond to the removed
     * time on the next update.
     *
     * @param Builder $query Base query selecting the unprocessed queue items.
     * @param int $seconds Reduction to apply in seconds.
     * @param bool $hasTimeProgress Whether the queue has a time_progress column (unit queue).
     * @return int The amount of seconds actually reduced.
     */
    private function reduceQueue(Builder $query, int $seconds, bool $hasTimeProgress): int
    {
        if ($seconds < 1) {
            return 0;
        }

        $now = (int)Date::now()->timestamp;

        /** @var int $applied */
        $applied = DB::transaction(function () use ($query, $seconds, $hasTimeProgress, $now): int {
            // Lock the queue items to prevent concurrent queue processing or other
            // time modifications (e.g. DM halving) from racing with this reduction.
            $items = $query->orderBy('time_start', 'asc')
                ->lockForUpdate()
                ->get();

            $remaining = $seconds;
            $shift = 0;
            $applied = 0;

            foreach ($items as $item) {
                if ($remaining <= 0) {
                    break;
                }

                // Orders already fully in the past are about to be processed by the
                // queue update; do not reduce them further.
                $available = (int)$item->getAttribute('time_end')
                    - max((int)$item->getAttribute('time_start'), $now);
                $reduction = min($remaining, max(0, $available));

                $shift += $reduction;
                $remaining -= $reduction;
                $applied += $reduction;

                if ($shift === 0) {
                    continue;
                }

                $item->setAttribute('time_start', (int)$item->getAttribute('time_start') - $shift);
                $item->setAttribute('time_end', (int)$item->getAttribute('time_end') - $shift);
                if ($hasTimeProgress) {
                    // Shifting time_progress along with the order makes the next queue
                    // update award the units corresponding to the reduced time. If the
                    // shifted value falls below time_start, the queue processor clamps
                    // it to time_start, which yields the correct award too.
                    $item->setAttribute('time_progress', (int)$item->getAttribute('time_progress') - $shift);
                }
                $item->save();
            }

            return $applied;
        });

        return $applied;
    }
}
