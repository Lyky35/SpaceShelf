<?php

namespace OGame\Bots\Brain;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use OGame\Bots\BotRegistry;
use OGame\Bots\Personality;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\BattleEngine\Models\AttackerFleet;
use OGame\GameMissions\BattleEngine\Models\DefenderFleet;
use OGame\GameMissions\BattleEngine\PhpBattleEngine;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\Enums\PlanetType;
use OGame\Models\EspionageReport;
use OGame\Models\FleetMission;
use OGame\Models\Highscore;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use OGame\Services\SettingsService;
use Throwable;

/**
 * Phase 3 war policy (docs/BOT-AI-PLAN.md §5): fleet saving on incoming
 * attacks, an espionage scouting loop and personality-scaled loot raids.
 *
 * All war "memory" — attack cooldowns, avoid lists and intel freshness — is
 * derived from the game's own tables (fleet missions, espionage reports and
 * their messages), so the policy carries no bot-specific persistence.
 */
final class WarPolicy
{
    private const MISSION_TYPE_ATTACK = 1;
    private const MISSION_TYPE_ACS_ATTACK = 2;
    private const MISSION_TYPE_TRANSPORT = 3;
    private const MISSION_TYPE_ESPIONAGE = 6;
    private const MISSION_TYPE_MISSILE = 10;
    private const MISSION_TYPE_EXPEDITION = 15;

    /**
     * Strike-capable military ships, strongest machine first. The raid and
     * evacuation fleets are assembled from the harbor counts of these.
     *
     * @var array<int, string>
     */
    private const STRIKE_SHIPS = [
        'battlecruiser',
        'battle_ship',
        'destroyer',
        'cruiser',
        'bomber',
        'reaper',
        'heavy_fighter',
        'light_fighter',
    ];

    public function __construct(
        private PlanetServiceFactory $planetServiceFactory,
        private PlayerServiceFactory $playerServiceFactory,
    ) {
    }

    /**
     * Run one pass of the war policy: save endangered fleets, scout with
     * probes, and raid when the roll of the dice and the battle simulation
     * agree. Returns the number of fleet missions dispatched.
     */
    public function conduct(BrainSnapshot $snapshot, Personality $personality): int
    {
        if (!config('bots.war.enabled', true)) {
            return 0;
        }

        $actions = $this->saveFleets($snapshot);
        $actions += $this->spy($snapshot, $personality);
        $actions += $this->raid($snapshot, $personality);

        return $actions;
    }

    /**
     * Fleet save: when an incoming hostile fleet would beat our fleet plus
     * defense on a planet, fly the military ships to a safe haven instead of
     * feeding them to defense. ACS attack fleets count as threats too (they
     * arrive as separate mission rows and are simulated as one combined
     * battle), and the save itself only gets attempted `attempt_chance` of
     * the time — a human does not always notice or bother.
     */
    private function saveFleets(BrainSnapshot $snapshot): int
    {
        $minThreat = (int) config('bots.war.save_fleet.min_threat_size', 10);
        $minFleet = (int) config('bots.war.save_fleet.min_fleet_size', 5);
        $minWin = (float) config('bots.war.save_fleet.min_win_chance', 0.3);
        $maxMissions = (int) config('bots.war.save_fleet.actions_per_visit', 1);
        $attemptChance = (float) config('bots.war.save_fleet.attempt_chance', 0.4);
        $samples = (int) config('bots.war.attack.sim_samples', 3);
        $now = (int) Date::now()->timestamp;
        $player = $snapshot->player;
        $fleetMissionService = resolve(FleetMissionService::class, ['player' => $player]);

        /** @var array<int, array<int, FleetMission>> $threats */
        $threats = [];
        foreach ($snapshot->planets as $planet) {
            $incoming = FleetMission::where('planet_id_to', $planet->getPlanetId())
                ->whereIn('mission_type', [self::MISSION_TYPE_ATTACK, self::MISSION_TYPE_ACS_ATTACK])
                ->where('processed', 0)
                ->where('canceled', 0)
                ->where('time_arrival', '>', $now)
                ->where('user_id', '!=', $player->getId())
                ->get();

            foreach ($incoming as $mission) {
                $threats[$planet->getPlanetId()][] = $mission;
            }
        }

        $missions = 0;
        foreach ($snapshot->planets as $planet) {
            if ($missions >= $maxMissions) {
                break;
            }

            $incoming = $threats[$planet->getPlanetId()] ?? [];
            if ($incoming === []) {
                continue;
            }

            // Not every endangered fleet gets saved — endangered planets
            // still contaminate the safe-haven map below either way.
            if (random_int(1, 100) > (int) round($attemptChance * 100)) {
                continue;
            }

            $fleet = $this->strikeFleet($planet, 1.0);
            if ($fleet->getAmount() < $minFleet) {
                continue;
            }

            // Simulate all threats to this planet at once; they arrive at
            // roughly the same time and would fight us together.
            $attackers = [];
            foreach ($incoming as $mission) {
                if ($fleetMissionService->getFleetUnitCount($mission) < $minThreat) {
                    continue;
                }

                $attackers[] = AttackerFleet::fromFleetMission(
                    $mission,
                    $fleetMissionService,
                    $this->playerServiceFactory,
                    true,
                );
            }

            if ($attackers === []) {
                continue;
            }

            // battleWinChance is the attackers' perspective: hold ground if
            // we can win the remaining share of samples, flee otherwise.
            if ($this->battleWinChance($planet, $attackers, $samples) < 1.0 - $minWin) {
                continue;
            }

            if ($this->dispatchFleetAway($snapshot, $planet, $fleet, $threats)) {
                $missions++;
            }
        }

        return $missions;
    }

    /**
     * Fly a planet's military fleet to another owned planet without incoming
     * hostiles; when we have nowhere to shelter, use a deep space expedition
     * instead of sitting still and dying.
     *
     * @param array<int, array<int, FleetMission>> $threats Planet id to incoming missions.
     */
    private function dispatchFleetAway(
        BrainSnapshot $snapshot,
        PlanetService $from,
        UnitCollection $fleet,
        array $threats,
    ): bool {
        foreach ($snapshot->planets as $candidate) {
            if (
                $candidate->getPlanetId() === $from->getPlanetId() ||
                isset($threats[$candidate->getPlanetId()])
            ) {
                continue;
            }

            return $this->dispatchMission(
                $from,
                self::MISSION_TYPE_TRANSPORT,
                $candidate->getPlanetCoordinates(),
                PlanetType::Planet,
                $fleet,
            );
        }

        $home = $from->getPlanetCoordinates();
        $player = $from->getPlayer();

        // Deep space shelter requires astrophysics, same as any expedition.
        if ($player === null || $player->getResearchLevel('astrophysics') < 1) {
            return false;
        }

        return $this->dispatchMission(
            $from,
            self::MISSION_TYPE_EXPEDITION,
            new Coordinate($home->galaxy, $home->system, 16),
            PlanetType::DeepSpace,
            $fleet,
        );
    }

    /**
     * Espionage loop: keep probing neighbors we could raid whose intel is
     * stale or missing. Launches from the planet with the largest probe
     * stock.
     */
    private function spy(BrainSnapshot $snapshot, Personality $personality): int
    {
        if ($personality->aggression < (float) config('bots.war.min_aggression_spy', 0.2)) {
            return 0;
        }

        if (
            $snapshot->player->getEffectiveEspionageLevel() <
            (int) config('bots.war.spy.min_espionage_level', 1)
        ) {
            return 0;
        }

        $probesPerMission = (int) config('bots.war.spy.probes_per_mission', 2);
        $maxMissions = (int) config('bots.war.spy.actions_per_visit', 1);
        $ttlSeconds = (int) config('bots.war.spy.intel_ttl_hours', 12) * 3600;
        $now = (int) Date::now()->timestamp;

        $launch = null;
        $probeCount = 0;
        foreach ($snapshot->planets as $planet) {
            $probes = $planet->getShipUnits()->getAmountByMachineName('espionage_probe');
            if ($probes >= $probesPerMission && $probes > $probeCount) {
                $probeCount = $probes;
                $launch = $planet;
            }
        }

        if ($launch === null) {
            return 0;
        }

        $probeObject = ObjectService::getUnitObjectByMachineName('espionage_probe');
        $missions = 0;

        foreach ($this->scanTargets($snapshot) as $target) {
            if ($missions >= $maxMissions) {
                break;
            }

            if ($this->hasActiveMission($snapshot->player, $target->coordinate, self::MISSION_TYPE_ESPIONAGE)) {
                continue;
            }

            $intel = $this->latestIntel($snapshot->player, $target->coordinate);
            $intelDate = $intel?->created_at;
            $intelAge = $intelDate === null ? null : (int) $intelDate->getTimestamp();
            if ($intel !== null && $intelAge !== null && $now - $intelAge < $ttlSeconds) {
                continue;
            }

            $units = new UnitCollection();
            $units->addUnit($probeObject, $probesPerMission);

            if (
                $this->dispatchMission($launch, self::MISSION_TYPE_ESPIONAGE, $target->coordinate, PlanetType::Planet, $units)
            ) {
                $missions++;
            }
        }

        return $missions;
    }

    /**
     * Raid decision: pick a scouted target whose loot is worth the trip, size
     * the strike fleet with the battle engine, and launch the smallest fleet
     * that can win with a good probability.
     */
    private function raid(BrainSnapshot $snapshot, Personality $personality): int
    {
        if ($personality->aggression < (float) config('bots.war.min_aggression_attack', 0.4)) {
            return 0;
        }

        $probability = min(
            100.0,
            $personality->aggression * 100 * (float) config('bots.war.raid_probability_scale', 0.4),
        );
        if (random_int(1, 100) > $probability) {
            return 0;
        }

        $minValue = (float) config('bots.war.loot.min_value', 20000);
        $minWin = (float) config('bots.war.attack.success_chance', 0.7);
        $samples = (int) config('bots.war.attack.sim_samples', 3);
        $minStrike = (int) config('bots.war.attack.min_strike_size', 10);
        $maxMissions = (int) config('bots.war.attack.actions_per_visit', 1);
        $maxSimTargets = (int) config('bots.war.attack.max_sim_targets', 3);
        $cooldownSeconds = (int) config('bots.war.attack.cooldown_hours', 12) * 3600;
        $fractions = (array) config('bots.war.attack.fleet_fractions', [0.8, 0.4, 0.2]);
        $player = $snapshot->player;
        $fleetMissionService = resolve(FleetMissionService::class, ['player' => $player]);
        $now = (int) Date::now()->timestamp;

        // Strike from the planet with the biggest military stock.
        $from = null;
        $bestFleet = 0;
        foreach ($snapshot->planets as $planet) {
            $amount = $this->strikeFleet($planet, 1.0)->getAmount();
            if ($amount >= $minStrike && $amount > $bestFleet) {
                $from = $planet;
                $bestFleet = $amount;
            }
        }

        if ($from === null) {
            return 0;
        }

        $missions = 0;
        $simulatedTargets = 0;

        /** @var array<int, Coordinate> $softTargets */
        $softTargets = [];

        foreach ($this->scanTargets($snapshot) as $target) {
            if ($missions >= $maxMissions || $simulatedTargets >= $maxSimTargets) {
                break;
            }

            if ($this->recentlyAttacked($player, $target->coordinate, $cooldownSeconds)) {
                continue;
            }

            if ($this->avoided($player, $target->coordinate, $now)) {
                continue;
            }

            $loot = $this->lootValue($player, $target->coordinate, $now);
            if ($loot === null || $loot < $minValue) {
                continue;
            }

            $targetPlanet = $this->planetServiceFactory->makeForCoordinate(
                $target->coordinate,
                false,
                PlanetType::Planet,
            );
            if ($targetPlanet === null || $targetPlanet->getPlayer() === null) {
                continue;
            }

            $simulatedTargets++;

            $launched = false;
            $undefendable = false;
            // fleet_fractions is ordered smallest first: the last entry is
            // the biggest fleet we would send, and only its simulation
            // decides whether a target is simply unbeatable for us.
            $largestFractionKey = array_key_last($fractions);

            foreach ($fractions as $fractionKey => $fraction) {
                $units = $this->strikeFleet($from, (float) $fraction);
                if ($units->getAmount() < $minStrike) {
                    // Fraction too small to count as a strike fleet at all;
                    // a bigger fraction may still qualify.
                    continue;
                }

                if (
                    $this->battleWinChance($targetPlanet, [$this->attackerFleet($player, $units)], $samples) < $minWin
                ) {
                    // Try a bigger fleet; only when even the biggest one
                    // loses is the target undefendable — remember it as a
                    // soft target for the missile volley.
                    if ($fractionKey === $largestFractionKey) {
                        $undefendable = true;
                    }

                    continue;
                }

                // Cargo ships ride along to load the loot the battle engine
                // hands to surviving transports (they barely fight, so the
                // win chance we just verified holds with them aboard).
                $this->attachCargo($from, $player, $units, $loot);

                // The assembled fleet must be able to pay its own fuel from
                // the strike planet's deuterium stock. Every bigger fraction
                // burns more than this one that just won, so there is no
                // point trying further — leave the target for a later visit.
                $consumption = $fleetMissionService->calculateConsumption(
                    $from,
                    $units,
                    $target->coordinate,
                    0,
                    10.0,
                );
                if ($from->getResources()->deuterium->get() < $consumption) {
                    break;
                }

                if (
                    $this->dispatchMission($from, self::MISSION_TYPE_ATTACK, $target->coordinate, PlanetType::Planet, $units)
                ) {
                    $missions++;
                    $launched = true;
                }

                break;
            }

            if (
                !$launched &&
                $undefendable &&
                count($softTargets) < (int) config('bots.war.missiles.max_soft_targets', 2)
            ) {
                $softTargets[] = $target->coordinate;
            }
        }

        // Fleet could not crack anything worth raiding this visit — the
        // missile bank is what a human reaches for next.
        if ($missions === 0 && $softTargets !== []) {
            $missions += $this->missileVolley($snapshot, $personality, $softTargets, $cooldownSeconds);
        }

        return $missions;
    }

    /**
     * Missile volley: a scouted target was worth raiding but even our
     * biggest fleet fraction would lose the battle sim — a defended loot
     * pin. A human does not shrug and log off; they drop an interplanetary
     * missile salvo on the guns keeping them out and try the raid again
     * once the craters are in (loot survives an IGM hit). Fires one volley
     * at the first qualifying soft target, from the planet with the
     * biggest interplanetary missile bank.
     */
    private function missileVolley(
        BrainSnapshot $snapshot,
        Personality $personality,
        array $softTargets,
        int $cooldownSeconds,
    ): int {
        if ($personality->aggression < (float) config('bots.war.missiles.min_aggression', 0.5)) {
            return 0;
        }

        $probability = (float) config('bots.war.missiles.probability', 0.35) * 100;

        if (random_int(1, 100) > (int) round($probability)) {
            return 0;
        }

        $volleyMin = (int) config('bots.war.missiles.volley_min', 4);
        $volleySize = (int) config('bots.war.missiles.volley_size', 10);
        $player = $snapshot->player;

        /** @var array<int, Coordinate> $softTargets */
        $coordinate = $softTargets[0];

        if ($this->recentlyAttacked($player, $coordinate, $cooldownSeconds, [self::MISSION_TYPE_MISSILE])) {
            return 0;
        }

        // Fire from the planet with the biggest IGM bank that meets the
        // volley minimum; the bank only exists where a silo does.
        $source = null;
        $best = 0;

        foreach ($snapshot->planets as $planet) {
            $stock = (int) $planet->getObjectAmount('interplanetary_missile');

            if ($stock >= $volleyMin && $stock > $best) {
                $source = $planet;
                $best = $stock;
            }
        }

        if ($source === null) {
            return 0;
        }

        $volley = min($volleySize, $best);

        // IGMs do not cross galaxies, and their reach is the impulse
        // drive's — the same pre-checks the game's launch screen does.
        $sourceCoords = $source->getPlanetCoordinates();

        if (
            $sourceCoords->galaxy !== $coordinate->galaxy ||
            abs($sourceCoords->system - $coordinate->system) > $player->getMissileRange()
        ) {
            return 0;
        }

        $targetPlanet = $this->planetServiceFactory->makeForCoordinate($coordinate, true, PlanetType::Planet);

        if ($targetPlanet === null) {
            return 0;
        }

        $targetPlayer = $targetPlanet->getPlayer();
        if ($targetPlayer === null || $targetPlayer->equals($player)) {
            return 0;
        }

        // Never launch missiles at administrators; they do not participate in the game.
        if ($targetPlayer->getUser()->hasRole('admin')) {
            return 0;
        }

        // Interplanetary missiles do not use the fleet mission service —
        // the game launches them as their own one-way mission row with a
        // dedicated missile count and flight-time formula, and the missile
        // processor reads exactly that. This mirrors that launch path.
        $distance = abs($sourceCoords->system - $coordinate->system);
        $now = (int) Date::now()->timestamp;

        $mission = new FleetMission();
        $mission->user_id = $player->getId();
        $mission->planet_id_from = $source->getPlanetId();
        $mission->galaxy_from = $sourceCoords->galaxy;
        $mission->system_from = $sourceCoords->system;
        $mission->position_from = $sourceCoords->position;
        $mission->type_from = $source->getPlanetType()->value;
        $mission->planet_id_to = $targetPlanet->getPlanetId();
        $mission->galaxy_to = $coordinate->galaxy;
        $mission->system_to = $coordinate->system;
        $mission->position_to = $coordinate->position;
        $mission->type_to = $targetPlanet->getPlanetType()->value;
        $mission->mission_type = self::MISSION_TYPE_MISSILE;
        $mission->time_departure = $now;
        $mission->time_arrival = $now + (int) (30 + 60 * $distance);
        $mission->canceled = 0;
        $mission->processed = 0;
        $mission->interplanetary_missile = $volley;
        $mission->target_priority = 0;
        $mission->save();

        $source->removeUnit('interplanetary_missile', $volley);
        $source->save();

        return 1;
    }

    /**
     * Build a military-only fleet from a planet's harbor stock at the given
     * fraction (always at least one of each ship type present).
     */
    private function strikeFleet(PlanetService $planet, float $fraction): UnitCollection
    {
        $units = new UnitCollection();
        $shipUnits = $planet->getShipUnits();

        foreach (self::STRIKE_SHIPS as $machineName) {
            $amount = $shipUnits->getAmountByMachineName($machineName);
            if ($amount <= 0) {
                continue;
            }

            $units->addUnit(
                ObjectService::getUnitObjectByMachineName($machineName),
                max(1, (int) floor($amount * $fraction)),
            );
        }

        return $units;
    }

    /**
     * Attach up to the number of large transports needed to carry the
     * expected loot home.
     */
    private function attachCargo(PlanetService $from, PlayerService $player, UnitCollection $units, float $loot): void
    {
        $cargoCount = $from->getShipUnits()->getAmountByMachineName('large_cargo');
        if ($cargoCount <= 0) {
            return;
        }

        $cargo = ObjectService::getUnitObjectByMachineName('large_cargo');
        $capacity = (int) $cargo->properties->capacity->calculate($player)->totalValue;
        if ($capacity <= 0) {
            return;
        }

        $units->addUnit($cargo, (int) min($cargoCount, max(1, (int) ceil($loot / $capacity))));
    }

    /**
     * Battle win probability, sampled N times against the defender's current
     * fleet and defense (combat is RNG, so one sim cannot be trusted).
     *
     * @param array<int, AttackerFleet> $attackers
     */
    private function battleWinChance(PlanetService $defender, array $attackers, int $samples): float
    {
        $defenderPlayer = $defender->getPlayer();
        if ($defenderPlayer === null) {
            return 1.0;
        }

        // The simulation must never change the defender. Tactical retreat
        // deducts deuterium and strips fleeing defending ships, so suppress
        // it on the in-memory user only — nothing here may write the foreign
        // account to the database.
        $defenderUser = $defenderPlayer->getUser();
        $originalRatio = $defenderUser->tactical_retreat_ratio;
        $defenderUser->tactical_retreat_ratio = 0;

        $wins = 0;
        try {
            for ($i = 0; $i < $samples; $i++) {
                $engine = new PhpBattleEngine(
                    $attackers,
                    $defender,
                    [DefenderFleet::fromPlanet($defender)],
                    resolve(SettingsService::class),
                );

                $result = $engine->simulateBattle();
                if ($result->defenderUnitsResult->getAmount() === 0) {
                    $wins++;
                }
            }
        } catch (Throwable) {
            // A pre-simulation failure means we cannot judge this fight, so
            // report "winnable": no raid is launched and no fleet is saved
            // on data we do not understand.
            return 1.0;
        } finally {
            $defenderUser->tactical_retreat_ratio = $originalRatio;
        }

        return $wins / max(1, $samples);
    }

    private function attackerFleet(PlayerService $player, UnitCollection $units): AttackerFleet
    {
        $attacker = new AttackerFleet();
        $attacker->units = $units;
        $attacker->player = $player;
        $attacker->fleetMissionId = 0;
        $attacker->ownerId = $player->getId();
        $attacker->cargoResources = new Resources(0, 0, 0, 0);
        $attacker->isInitiator = true;
        $attacker->fleetMission = null;

        return $attacker;
    }

    /**
     * Candidate foreign planets within range of our colonies, already
     * filtered by the protection rules: vacationers, top-ranked humans and
     * players far outside our score band are never even scouted.
     *
     * @return array<int, WarTarget>
     */
    private function scanTargets(BrainSnapshot $snapshot): array
    {
        $maxSystems = (int) config('bots.war.spy.max_system_distance', 3);
        $protectTopRank = (int) config('bots.war.protect.human_top_rank', 10);
        $humanMinScore = (float) config('bots.war.protect.human_min_score', 100000);
        [$bandMin, $bandMax] = config('bots.war.loot.score_band', [0.2, 2.5]);
        $unrankedMaxScore = (float) config('bots.war.loot.unranked_max_score', 50000);
        $botUserIds = resolve(BotRegistry::class)->botUserIds();

        $player = $snapshot->player;
        $userId = $player->getId();
        $ourScore = (int) Highscore::where('player_id', $userId)->value('general');

        /** @var array<string, WarTarget> $targets */
        $targets = [];
        /** @var array<int, User|null> $users */
        $users = [];

        foreach ($snapshot->planets as $planet) {
            $home = $planet->getPlanetCoordinates();
            $systems = range(max(1, $home->system - $maxSystems), $home->system + $maxSystems);

            $rows = Planet::where('galaxy', $home->galaxy)
                ->whereIn('system', $systems)
                ->where('user_id', '!=', $userId)
                ->where('destroyed', 0)
                ->get();

            foreach ($rows as $row) {
                $key = $row->galaxy . ':' . $row->system . ':' . $row->planet;
                if (isset($targets[$key])) {
                    continue;
                }

                $targetUserId = (int) $row->user_id;
                if (!array_key_exists($targetUserId, $users)) {
                    $users[$targetUserId] = User::find($targetUserId);
                }
                $targetUser = $users[$targetUserId];

                // Never target administrators; they do not participate in the game.
                if ($targetUser === null || $targetUser->vacation_mode || $targetUser->hasRole('admin')) {
                    continue;
                }

                $isBot = in_array($targetUserId, $botUserIds, true);
                $score = (int) Highscore::where('player_id', $targetUserId)->value('general');

                // The leaderboard top end is off limits for humans; bots
                // keep fighting each other freely. The score floor keeps a
                // small world (where everyone ranks top-N) raidable.
                if (
                    !$isBot &&
                    $protectTopRank > 0 &&
                    $score >= $humanMinScore
                ) {
                    $rank = (int) Highscore::where('player_id', $targetUserId)->value('general_rank');
                    if ($rank > 0 && $rank <= $protectTopRank) {
                        continue;
                    }
                }

                if ($ourScore > 0) {
                    if ($score < (float) $bandMin * $ourScore || $score > (float) $bandMax * $ourScore) {
                        continue;
                    }
                } elseif ($score > $unrankedMaxScore) {
                    continue;
                }

                $targets[$key] = new WarTarget(
                    coordinate: new Coordinate((int) $row->galaxy, (int) $row->system, (int) $row->planet),
                    userId: $targetUserId,
                    isBot: $isBot,
                    generalScore: $score,
                );
            }
        }

        $targets = array_values($targets);
        shuffle($targets);

        return $targets;
    }

    /**
     * Whether we already launched a mission of these types at this
     * coordinate within the cooldown window (our own fleet missions are our
     * attack history).
     *
     * @param array<int, int> $missionTypes
     */
    private function recentlyAttacked(
        PlayerService $player,
        Coordinate $coordinate,
        int $cooldownSeconds,
        array $missionTypes = [self::MISSION_TYPE_ATTACK],
    ): bool {
        return FleetMission::where('user_id', $player->getId())
            ->whereIn('mission_type', $missionTypes)
            ->where('galaxy_to', $coordinate->galaxy)
            ->where('system_to', $coordinate->system)
            ->where('position_to', $coordinate->position)
            ->where('canceled', 0)
            ->where('time_departure', '>=', (int) Date::now()->timestamp - $cooldownSeconds)
            ->exists();
    }

    /**
     * Whether a raid on this coordinate must be skipped because one of our
     * recent attacks there returned having lost (or never returned) more
     * than the configured fraction of the fleet — the target is defended
     * and would only cost us again.
     */
    private function avoided(PlayerService $player, Coordinate $coordinate, int $now): bool
    {
        $hours = (int) config('bots.war.attack.avoid_hours', 48);
        $lossRatio = (float) config('bots.war.attack.avoid_loss_ratio', 0.3);
        $fleetMissionService = resolve(FleetMissionService::class, ['player' => $player]);

        $attacks = FleetMission::where('user_id', $player->getId())
            ->where('mission_type', self::MISSION_TYPE_ATTACK)
            ->where('galaxy_to', $coordinate->galaxy)
            ->where('system_to', $coordinate->system)
            ->where('position_to', $coordinate->position)
            ->where('canceled', 0)
            ->where('time_arrival', '>=', $now - $hours * 3600)
            ->get();

        foreach ($attacks as $attack) {
            $dispatched = $fleetMissionService->getFleetUnitCount($attack);
            if ($dispatched <= 0) {
                continue;
            }

            // Fleet wiped entirely: no return mission exists at all.
            $returns = FleetMission::where('parent_id', $attack->id)
                ->where('canceled', 0)
                ->get();

            $survived = 0;
            foreach ($returns as $return) {
                $survived += $fleetMissionService->getFleetUnitCount($return);
            }

            if ($dispatched - $survived >= $lossRatio * $dispatched) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether we have an undelivered mission of this type to the coordinate
     * (a probe still in transit makes the target freshly scouted already).
     */
    private function hasActiveMission(PlayerService $player, Coordinate $coordinate, int $missionType): bool
    {
        return FleetMission::where('user_id', $player->getId())
            ->where('mission_type', $missionType)
            ->where('galaxy_to', $coordinate->galaxy)
            ->where('system_to', $coordinate->system)
            ->where('position_to', $coordinate->position)
            ->where('processed', 0)
            ->where('canceled', 0)
            ->exists();
    }

    /**
     * Latest espionage report on the coordinate that belongs to the player.
     * Ownership lives only on the message the mission delivered, so the
     * reports table is joined through the messages table.
     */
    private function latestIntel(PlayerService $player, Coordinate $coordinate): EspionageReport|null
    {
        return EspionageReport::query()
            ->where('planet_galaxy', $coordinate->galaxy)
            ->where('planet_system', $coordinate->system)
            ->where('planet_position', $coordinate->position)
            ->where('planet_type', PlanetType::Planet->value)
            ->whereExists(function (Builder $query) use ($player): void {
                $query->select(DB::raw(1))
                    ->from('messages')
                    ->where('messages.user_id', $player->getId())
                    ->whereColumn('messages.espionage_report_id', 'espionage_reports.id');
            })
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Raidable value on the coordinate from our freshest report: resources
     * plus debris. Null when intel is missing or stale.
     */
    private function lootValue(PlayerService $player, Coordinate $coordinate, int $now): float|null
    {
        $intel = $this->latestIntel($player, $coordinate);
        if ($intel === null) {
            return null;
        }

        $ttlSeconds = (int) config('bots.war.spy.intel_ttl_hours', 12) * 3600;
        $intelDate = $intel->created_at;
        $createdAt = $intelDate === null ? null : (int) $intelDate->getTimestamp();
        if ($createdAt === null || $now - $createdAt > $ttlSeconds) {
            return null;
        }

        $value = 0.0;
        $resources = $intel->resources ?? [];
        $debris = $intel->debris ?? [];
        foreach (['metal', 'crystal', 'deuterium'] as $resource) {
            $value += (float) ($resources[$resource] ?? 0);
            $value += (float) ($debris[$resource] ?? 0);
        }

        return $value;
    }

    /**
     * Dispatch one fleet mission with empty cargo at full speed. Returns
     * false (instead of throwing) when the engine rejects the order, so one
     * rejected war mission never interrupts the whole brain visit.
     */
    private function dispatchMission(
        PlanetService $from,
        int $missionType,
        Coordinate $destination,
        PlanetType $destinationType,
        UnitCollection $units,
    ): bool {
        $player = $from->getPlayer();
        if ($player === null) {
            return false;
        }

        $fleetMissionService = resolve(FleetMissionService::class, ['player' => $player]);

        try {
            $fleetMissionService->createNewFromPlanet(
                $from,
                $destination,
                $destinationType,
                $missionType,
                $units,
                new Resources(0, 0, 0, 0),
                10.0,
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
