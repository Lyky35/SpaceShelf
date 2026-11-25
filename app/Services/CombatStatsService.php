<?php

namespace OGame\Services;

use Illuminate\Support\Facades\DB;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\BattleEngine\Models\BattleResult;
use OGame\GameObjects\Models\Units\UnitCollection;

/**
 * Tracks the cumulative combat statistics behind the military highscore
 * sub-categories (vanilla types 4 lost / 5 built / 6 destroyed / 7 honor).
 *
 * Counters live on the users table and are copied into the highscore tables
 * by the scheduler commands (same lazy pattern as the other highscore values).
 *
 * Points use the same weights as the military highscore itself (verified via
 * the official points guide): 1 point per 1,000 resources of raw unit cost,
 * military ships + defense at 100%, civil ships at 50%.
 *
 * Honor follows the officially documented formula
 * HP = INT((cost of destroyed units ^ 0.9) / 1000) with these rules:
 * - Civil ships never award honor points (they do count towards the lost
 *   penalty for dishonourable attacks at their 50% weight).
 * - Defense only awards honor points when permanently destroyed (repaired
 *   defenses are excluded, vanilla 3.1 behaviour).
 * - The attacker gains HP on honourable fights and loses HP on dishonourable
 *   ones; the defender only ever gains HP (for destroyed attacker ships).
 * - Fights against alliance mates, buddies, vacation-mode players or oneself
 *   are neutral (no HP change, stats still tracked).
 *
 * Best-effort simplifications (NOT documented upstream, marked here for
 * honesty; the vanilla rank-based edge rules need data this fork computes
 * lazily):
 * - The "target within 100 military ranks" honourable condition is omitted;
 *   the remaining official conditions (fewer MP than target, target >= 50%
 *   of attacker MP, within 10 MP, target is bandit at <= -500 HP) are all
 *   implemented.
 * - In ACS fights every participating player is judged against the summed
 *   military points of the opposing side (official ACS rule) but each player
 *   receives the full per-battle HP amount instead of the 1%-share pro-rata
 *   split.
 * - Expedition and interplanetary missile battles do not change honor
 *   (vanilla since 3.1 grants no HP for IPM-destroyed defense; expedition
 *   NPC handling upstream is not observable). The lost/destroyed/built
 *   counters still apply everywhere.
 */
class CombatStatsService
{
    /**
     * Count built units towards the owner's "military built" highscore stat.
     * Called whenever the shipyard/defense queue awards units.
     */
    public function addBuilt(PlanetService $planet, string $machineName, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $points = (int) floor($this->unitCost($machineName, $amount, true) / 1000);
        if ($points <= 0) {
            return;
        }

        $owner = $planet->getPlayer();
        if ($owner === null) {
            return;
        }

        DB::table('users')
            ->where('id', $owner->getId())
            ->increment('military_built', $points);
    }

    /**
     * Count a lost unit collection (expedition battles: own losses only).
     */
    public function addLost(int $playerId, UnitCollection $unitsLost): void
    {
        $points = $this->collectionPoints($unitsLost, true);
        if ($points > 0) {
            DB::table('users')->where('id', $playerId)->increment('military_lost', $points);
        }
    }

    /**
     * Count destroyed enemy units (missile attacks: no BattleResult exists).
     */
    public function addDestroyed(int $playerId, UnitCollection $unitsDestroyed): void
    {
        $points = $this->collectionPoints($unitsDestroyed, true);
        if ($points > 0) {
            DB::table('users')->where('id', $playerId)->increment('military_destroyed', $points);
        }
    }

    /**
     * Record all combat statistics for a finished battle.
     *
     * @param BattleResult $result Battle engine result.
     * @param array<int|null> $attackerPlayerIds Players on the attacking side
     *                                            (nulls are dropped).
     * @param array<int|null> $defenderPlayerIds Players on the defending side
     *                                            (nulls are dropped).
     * @param bool $grantHonor Whether honor points may change (false for IPM
     *                         and espionage battles; destroyed/lost still apply).
     */
    public function recordBattle(BattleResult $result, array $attackerPlayerIds, array $defenderPlayerIds, bool $grantHonor = true): void
    {
        $attackerPlayerIds = array_values(array_unique(array_filter($attackerPlayerIds)));
        $defenderPlayerIds = array_values(array_unique(array_filter($defenderPlayerIds)));

        // --- Military destroyed / lost counters ---
        // Every participant is credited with the enemy side's total losses
        // (per-battle amount, ACS participants included - see class docblock).
        $defenderLossScore = $this->collectionPoints($result->defenderUnitsLost, true);
        $attackerLossScore = $this->collectionPoints($result->attackerUnitsLost, true);

        foreach ($attackerPlayerIds as $playerId) {
            DB::table('users')->where('id', $playerId)->increment('military_lost', $this->perFleetLostPoints($result->attackerFleetResults, $playerId, $result->attackerUnitsLost));
            if ($defenderLossScore > 0) {
                DB::table('users')->where('id', $playerId)->increment('military_destroyed', $defenderLossScore);
            }
        }
        foreach ($defenderPlayerIds as $playerId) {
            DB::table('users')->where('id', $playerId)->increment('military_lost', $this->perFleetLostPoints($result->defenderFleetResults, $playerId, $result->defenderUnitsLost));
            if ($attackerLossScore > 0) {
                DB::table('users')->where('id', $playerId)->increment('military_destroyed', $attackerLossScore);
            }
        }

        if (!$grantHonor) {
            return;
        }

        // --- Honor points ---
        $honorableFight = $this->isHonorableFight($result, $attackerPlayerIds, $defenderPlayerIds);

        if ($honorableFight === true) {
            // Attacker: gains HP for the destroyed defender units (civil
            // ships excluded, repaired defense deducted per vanilla 3.1).
            $gainCost = $this->collectionHonorCost($result->defenderUnitsLost, $result->repairedDefenses);
            $gain = $this->honorPoints($gainCost);
            foreach ($attackerPlayerIds as $playerId) {
                if ($gain > 0) {
                    DB::table('users')->where('id', $playerId)->increment('honor', $gain);
                }
            }
            // Defender earns HP for destroyed attacker units (never loses any).
            $defenderGain = $this->honorPoints($this->collectionHonorCost($result->attackerUnitsLost, new UnitCollection()));
            foreach ($defenderPlayerIds as $playerId) {
                if ($defenderGain > 0) {
                    DB::table('users')->where('id', $playerId)->increment('honor', $defenderGain);
                }
            }
        } elseif ($honorableFight === false) {
            // Dishonourable fight (farm attacks on much weaker targets): the
            // attacker loses HP; all destroyed defender units count for the
            // penalty at score weights.
            $penalty = $this->honorPoints((int) $this->collectionCostRaw($result->defenderUnitsLost, true));
            if ($penalty > 0) {
                foreach ($attackerPlayerIds as $playerId) {
                    DB::table('users')->where('id', $playerId)->decrement('honor', $penalty);
                }
            }
        }
        // $honorableFight === null means a neutral fight (alliance mates,
        // buddies, vacation mode, own attacks): no honor changes at all.
    }

    /**
     * Whether the fight counts as honourable. Returns null when the fight is
     * neutral (no HP changes at all).
     *
     * Official conditions (any one suffices):
     * - attacker has fewer military points than the target,
     * - target has >= 50% of the attacker's military points,
     * - target is within 10 military points of the attacker,
     * - target is a bandit (honor <= -500).
     * Neutrality: alliance mates, buddies, vacation mode, own attacks.
     */
    private function isHonorableFight(BattleResult $result, array $attackerPlayerIds, array $defenderPlayerIds): bool|null
    {
        if (count($attackerPlayerIds) === 0 || count($defenderPlayerIds) === 0) {
            return null;
        }

        $playerServiceFactory = resolve(PlayerServiceFactory::class);
        $highscoreService = resolve(HighscoreService::class);
        $buddyService = resolve(BuddyService::class);

        // Always resolve fresh player services: the battle just changed unit
        // amounts, and cached instances could carry stale pre-battle data.
        $attackers = [];
        foreach ($attackerPlayerIds as $playerId) {
            $attackers[] = $playerServiceFactory->make($playerId, true);
        }
        $defenders = [];
        foreach ($defenderPlayerIds as $playerId) {
            $defenders[] = $playerServiceFactory->make($playerId, true);
        }

        // Neutral: any overlap (self-attack), vacation mode, or (for 1 vs 1
        // fights) alliance membership / buddy relationships.
        $singleVsSingle = count($attackers) === 1 && count($defenders) === 1;
        foreach ($attackers as $attacker) {
            foreach ($defenders as $defender) {
                if ($attacker->getId() === $defender->getId()) {
                    return null;
                }
                if ($attacker->isInVacationMode() || $defender->isInVacationMode()) {
                    return null;
                }
                $attAllianceId = $attacker->getUser()->alliance_id;
                $defAllianceId = $defender->getUser()->alliance_id;
                if ($singleVsSingle) {
                    if ($attAllianceId !== null && $attAllianceId === $defAllianceId) {
                        return null;
                    }
                    if ($buddyService->areBuddies($attacker->getId(), $defender->getId())) {
                        return null;
                    }
                }
            }
        }

        $defenderHonor = (int) DB::table('users')->whereIn('id', $defenderPlayerIds)->min('honor');
        if ($defenderHonor <= -500) {
            return true; // Bandit target.
        }

        $attackerPoints = 0;
        foreach ($attackers as $attacker) {
            $attackerPoints += $highscoreService->getPlayerScoreMilitary($attacker);
        }
        $defenderPoints = 0;
        foreach ($defenders as $defender) {
            $defenderPoints += $highscoreService->getPlayerScoreMilitary($defender);
        }

        // Official ACS + solo conditions: honourable when the defending side
        // is at least half the attacking side, the attacker is not stronger
        // by more than 10 military points, or the attacker is weaker overall.
        if ($defenderPoints >= $attackerPoints) {
            return true;
        }
        if ($defenderPoints * 2 >= $attackerPoints) {
            return true;
        }
        if ($attackerPoints - $defenderPoints <= 10) {
            return true;
        }

        return false;
    }

    /**
     * Per-player lost points: prefer the exact per-fleet breakdown when the
     * battle engine produced one, otherwise attribute the aggregated losses.
     */
    private function perFleetLostPoints(array $fleetResults, int $playerId, UnitCollection $fallback): int
    {
        $total = 0;
        $found = false;
        foreach ($fleetResults as $fleetResult) {
            $ownerId = property_exists($fleetResult, 'playerId') ? $fleetResult->playerId : ($fleetResult->ownerId ?? null);
            if ($ownerId === $playerId) {
                $found = true;
                $total += $this->collectionPoints($fleetResult->unitsLost, true);
            }
        }

        return $found ? $total : $this->collectionPoints($fallback, true);
    }

    /**
     * Military-score-weighted points (1 per 1000 raw cost, civil ships 50%)
     * for a unit collection.
     */
    private function collectionPoints(UnitCollection $units, bool $includeCivilHalf): int
    {
        return (int) floor($this->collectionCostRaw($units, $includeCivilHalf) / 1000);
    }

    /**
     * Raw resource cost of a unit collection (optionally civil ships at 50%).
     */
    private function collectionCostRaw(UnitCollection $units, bool $civilHalf): float
    {
        $cost = 0.0;
        foreach ($units->units as $unit) {
            $machineName = $unit->unitObject->machine_name;
            $cost += $this->unitCost($machineName, $unit->amount, $civilHalf);
        }

        return $cost;
    }

    /**
     * Honor-eligible raw cost: civil ships excluded entirely, and defense
     * that got repaired after the battle deducted (vanilla 3.1: only
     * permanently destroyed defense awards HP).
     */
    private function collectionHonorCost(UnitCollection $units, UnitCollection $repaired): int
    {
        $repairedAmounts = [];
        foreach ($repaired->units as $unit) {
            $repairedAmounts[$unit->unitObject->machine_name] = ($repairedAmounts[$unit->unitObject->machine_name] ?? 0) + $unit->amount;
        }

        $cost = 0;
        foreach ($units->units as $unit) {
            $machineName = $unit->unitObject->machine_name;
            $amount = max(0, $unit->amount - ($repairedAmounts[$machineName] ?? 0));
            if ($amount <= 0) {
                continue;
            }
            if ($this->isCivilShip($machineName)) {
                continue; // Civil ships never award honor points.
            }
            $cost += (int) $this->unitCost($machineName, $amount, false);
        }

        return $cost;
    }

    /**
     * Official honor formula: INT((cost ^ 0.9) / 1000).
     */
    private function honorPoints(int $cost): int
    {
        if ($cost <= 0) {
            return 0;
        }

        return (int) floor(($cost ** 0.9) / 1000);
    }

    /**
     * Raw resource cost of `amount` units, with civil ships optionally at 50%
     * (military score weights). Defense structures are unit objects and count
     * at 100%.
     */
    private function unitCost(string $machineName, int $amount, bool $civilHalf): float
    {
        $price = ObjectService::getObjectRawPrice($machineName);

        $cost = $price->sum();
        if ($civilHalf && $this->isCivilShip($machineName)) {
            $cost *= 0.5;
        }

        return $cost * $amount;
    }

    /**
     * Whether the unit belongs to the civil ship group (50% military score
     * weight, no honor points). Defense structures are not civil.
     */
    private function isCivilShip(string $machineName): bool
    {
        foreach (ObjectService::getCivilShipObjects() as $ship) {
            if ($ship->machine_name === $machineName) {
                return true;
            }
        }

        return false;
    }
}
