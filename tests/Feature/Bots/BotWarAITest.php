<?php

namespace Tests\Feature\Bots;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use OGame\Bots\Brain\BotBrain;
use OGame\Bots\Brain\SnapshotBuilder;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Bot;
use OGame\Models\Enums\PlanetType;
use OGame\Models\EspionageReport;
use OGame\Models\FleetMission;
use OGame\Models\Highscore;
use OGame\Models\Message;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Models\UserTech;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use Tests\IsolatedAccountTestCase;

/**
 * Phase-3 war policy (docs/BOT-AI-PLAN.md §5): espionage scouting loop,
 * simulated loot raids with cooldowns and human leaderboard protection, and
 * fleet saving under incoming attack. Asserted on dispatched fleet-mission
 * rows only — combat is RNG, so outcomes are never hardcoded (AGENTS.md).
 */
class BotWarAITest extends IsolatedAccountTestCase
{
    /**
     * Create one bot with the given profile and return it.
     */
    private function createBot(string $profile): Bot
    {
        $this->assertSame(0, Artisan::call('spaceshelf:bot:create', ['--count' => 1, '--profile' => $profile]));

        return Bot::orderByDesc('id')->firstOrFail();
    }

    /**
     * Reload the bot's player service with fresh state.
     */
    private function reloadPlayer(Bot $bot): PlayerService
    {
        return resolve(PlayerServiceFactory::class)->make($bot->user_id, true);
    }

    /**
     * Run the brain exactly once against the bot's current state, mirroring
     * the heartbeat's "login" step first.
     */
    private function playOnce(Bot $bot): int
    {
        $player = $this->reloadPlayer($bot);

        $player->update();
        foreach ($player->planets->allPlanets() as $planet) {
            $planet->update();
        }

        return resolve(BotBrain::class)->play(
            resolve(SnapshotBuilder::class)->build($player),
            $bot->personalityObject(),
        );
    }

    /**
     * The bot's (only) home planet.
     */
    private function homePlanet(PlayerService $player): PlanetService
    {
        $home = null;
        foreach ($player->planets->allPlanets() as $planet) {
            if ($home === null || $planet->getPlanetId() < $home->getPlanetId()) {
                $home = $planet;
            }
        }

        if ($home === null) {
            $this->fail('Player has no planets to pick a home from.');
        }

        return $home;
    }

    /**
     * The bot's non-home colony (bots spawn with a second planet).
     */
    private function secondPlanet(PlayerService $player): PlanetService
    {
        $home = $this->homePlanet($player);

        foreach ($player->planets->allPlanets() as $planet) {
            if ($planet->getPlanetId() !== $home->getPlanetId()) {
                return $planet;
            }
        }

        $this->fail('Bot has no second planet to use as a colony.');
    }

    /**
     * Create a foreign player whose planet sits at a free position in the
     * bot's home system, so the war policy's range scan finds it.
     *
     * @return array{PlanetService, int} Target planet plus its owner's id.
     */
    private function createForeignTarget(PlayerService $bot): array
    {
        $home = $this->homePlanet($bot);
        $coords = $home->getPlanetCoordinates();

        $position = null;
        for ($candidate = 1; $candidate <= 15; $candidate++) {
            if ($candidate === $coords->position) {
                continue;
            }

            $occupied = Planet::where('galaxy', $coords->galaxy)
                ->where('system', $coords->system)
                ->where('planet', $candidate)
                ->exists();

            if (!$occupied) {
                $position = $candidate;
                break;
            }
        }

        $this->assertNotNull($position, 'No free position in the bot home system for a target planet.');

        $foreignUser = User::factory()->create();
        UserTech::factory()->create(['user_id' => $foreignUser->id]);

        $planet = Planet::factory()->create([
            'user_id' => $foreignUser->id,
            'galaxy' => $coords->galaxy,
            'system' => $coords->system,
            'planet' => $position,
            'time_last_update' => (int) Date::now()->timestamp,
        ]);

        $target = resolve(PlanetServiceFactory::class)->makeForPlayer(
            resolve(PlayerServiceFactory::class)->make($foreignUser->id),
            $planet->id,
        );

        return [$target, $foreignUser->id];
    }

    /**
     * Pin both players to the same general score so the war policy's score
     * band accepts each other as targets.
     */
    private function setScore(int $userId, int $general, int|null $rank = null): void
    {
        $row = Highscore::where('player_id', $userId)->first();
        if ($row === null) {
            Highscore::forceCreate([
                'player_id' => $userId,
                'general' => $general,
                'general_rank' => $rank,
            ]);

            return;
        }

        $row->general = $general;
        if ($rank !== null) {
            $row->general_rank = $rank;
        }
        $row->save();
    }

    /**
     * Fake a fresh espionage report the bot owns: reports are owned through
     * the delivered message, exactly like the real spy mission creates them.
     */
    private function createSpyIntel(PlayerService $spy, PlanetService $target, array $resources): void
    {
        $coords = $target->getPlanetCoordinates();
        $targetUserId = $target->getPlayer()?->getId();
        $this->assertNotNull($targetUserId);

        $report = EspionageReport::forceCreate([
            'planet_galaxy' => $coords->galaxy,
            'planet_system' => $coords->system,
            'planet_position' => $coords->position,
            'planet_type' => PlanetType::Planet->value,
            'planet_user_id' => $targetUserId,
            'resources' => $resources,
            'debris' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0],
            'buildings' => [],
            'research' => [],
            'ships' => [],
            'defense' => [],
            'player_info' => [],
        ]);

        Message::forceCreate([
            'user_id' => $spy->getId(),
            'key' => 'espionage_report',
            'espionage_report_id' => $report->id,
        ]);
    }

    /**
     * Force the raid probability roll so aggressive profiles always attempt
     * a raid on a visit (keeps the assertions deterministic).
     */
    private function forceRaidRoll(): void
    {
        config(['bots.war.raid_probability_scale' => 1.5]);
    }

    /**
     * Set a building level on a planet service directly.
     */
    private function setPlanetLevel(PlanetService $planet, string $machineName, int $level): void
    {
        $planet->setObjectLevel(ObjectService::getObjectByMachineName($machineName)->id, $level);
    }

    public function testSpiesNeighbouringForeignPlanet(): void
    {
        $bot = $this->createBot('general');
        $player = $this->reloadPlayer($bot);
        $player->setResearchLevel('espionage_technology', 3);

        [$target] = $this->createForeignTarget($player);
        $this->setScore((int) $bot->user_id, 50000);
        $this->setScore((int) $target->getPlayer()?->getId(), 50000);

        $home = $this->homePlanet($player);
        $home->addUnit('espionage_probe', 4);

        $this->playOnce($bot);

        $coords = $target->getPlanetCoordinates();
        $mission = FleetMission::where('user_id', $bot->user_id)
            ->where('mission_type', 6)
            ->where('galaxy_to', $coords->galaxy)
            ->where('system_to', $coords->system)
            ->where('position_to', $coords->position)
            ->first();

        $this->assertNotNull($mission, 'Expected a probe mission on the neighbouring foreign planet.');
        $this->assertSame(2, $mission->espionage_probe);
    }

    public function testSkipsScoutWithoutProbeStock(): void
    {
        $bot = $this->createBot('general');
        $player = $this->reloadPlayer($bot);
        $player->setResearchLevel('espionage_technology', 3);

        [$target] = $this->createForeignTarget($player);
        $this->setScore((int) $bot->user_id, 50000);
        $this->setScore((int) $target->getPlayer()?->getId(), 50000);

        $this->playOnce($bot);

        $this->assertSame(
            0,
            FleetMission::where('user_id', $bot->user_id)->where('mission_type', 6)->count(),
            'The brain must not dispatch spies without probes in stock.',
        );
    }

    public function testRaidsRichTargetWithFreshIntel(): void
    {
        $this->forceRaidRoll();

        $bot = $this->createBot('general');
        $player = $this->reloadPlayer($bot);

        [$target, $targetUserId] = $this->createForeignTarget($player);
        $target->addResources(new Resources(150000, 90000, 0, 0));

        $this->setScore((int) $bot->user_id, 50000);
        $this->setScore($targetUserId, 50000);
        $this->createSpyIntel($player, $target, [
            'metal' => 150000,
            'crystal' => 90000,
            'deuterium' => 0,
        ]);

        $home = $this->homePlanet($player);
        $home->addUnit('light_fighter', 200);
        $home->addResources(new Resources(0, 0, 100000, 0));

        $this->playOnce($bot);

        $coords = $target->getPlanetCoordinates();
        $mission = FleetMission::where('user_id', $bot->user_id)
            ->where('mission_type', 1)
            ->where('galaxy_to', $coords->galaxy)
            ->where('system_to', $coords->system)
            ->where('position_to', $coords->position)
            ->first();

        $this->assertNotNull($mission, 'Expected an attack on the rich, un defended, scouted target.');
        $this->assertGreaterThan(0, $mission->light_fighter);
    }

    /**
     * Fleet sizing: the fraction ladder is tried smallest first, so an
     * undefended loot target must draw only the smallest strike fraction —
     * never the bulk of the navy.
     */
    public function testRaidLaunchesSmallestSufficientFleet(): void
    {
        $this->forceRaidRoll();

        $bot = $this->createBot('general');
        $player = $this->reloadPlayer($bot);

        [$target, $targetUserId] = $this->createForeignTarget($player);
        $target->addResources(new Resources(150000, 90000, 0, 0));

        $this->setScore((int) $bot->user_id, 50000);
        $this->setScore($targetUserId, 50000);
        $this->createSpyIntel($player, $target, [
            'metal' => 150000,
            'crystal' => 90000,
            'deuterium' => 0,
        ]);

        $home = $this->homePlanet($player);
        $home->addUnit('light_fighter', 200);
        $home->addResources(new Resources(0, 0, 100000, 0));

        $stock = $home->getShipUnits()->getAmountByMachineName('light_fighter');
        $smallest = (float) config('bots.war.attack.fleet_fractions.0');

        $this->playOnce($bot);

        $coords = $target->getPlanetCoordinates();
        $mission = FleetMission::where('user_id', $bot->user_id)
            ->where('mission_type', 1)
            ->where('galaxy_to', $coords->galaxy)
            ->where('system_to', $coords->system)
            ->where('position_to', $coords->position)
            ->first();

        $this->assertNotNull($mission, 'Expected an attack on the rich, un defended, scouted target.');
        $this->assertSame(
            (int) floor($stock * $smallest),
            (int) $mission->light_fighter,
            'An undefended target must draw only the smallest strike fraction, not the whole navy.',
        );
    }

    /**
     * A fraction below the minimum strike fleet size is not worth sending at
     * all; the ladder steps up to the next fraction instead.
     */
    public function testRaidStepsUpWhenSmallestFractionIsTooSmallForAStrike(): void
    {
        $this->forceRaidRoll();

        $bot = $this->createBot('general');
        $player = $this->reloadPlayer($bot);

        [$target, $targetUserId] = $this->createForeignTarget($player);
        $target->addResources(new Resources(150000, 90000, 0, 0));

        $this->setScore((int) $bot->user_id, 50000);
        $this->setScore($targetUserId, 50000);
        $this->createSpyIntel($player, $target, [
            'metal' => 150000,
            'crystal' => 90000,
            'deuterium' => 0,
        ]);

        $home = $this->homePlanet($player);
        $home->addUnit('light_fighter', 40);
        $home->addResources(new Resources(0, 0, 100000, 0));

        $this->playOnce($bot);

        $coords = $target->getPlanetCoordinates();
        $mission = FleetMission::where('user_id', $bot->user_id)
            ->where('mission_type', 1)
            ->where('galaxy_to', $coords->galaxy)
            ->where('system_to', $coords->system)
            ->where('position_to', $coords->position)
            ->first();

        $this->assertNotNull($mission, 'Expected an attack with the 40% fraction fleet.');

        // 40 light fighters: the 20% fraction (8) sits below the 10-unit
        // strike minimum, so the 40% fraction (16) must be the one leaving.
        $this->assertSame(16, (int) $mission->light_fighter);
    }

    /**
     * The undefendable (soft target) marking is decided by the biggest
     * fraction losing: targets the navy cannot beat must not eat attack
     * missions — a banked missile volley is the answer instead.
     */
    public function testUndefendableTargetDrawsMissilesNotTheAttackFleet(): void
    {
        $this->forceRaidRoll();
        config()->set('bots.war.missiles.probability', 1.0);

        $bot = $this->createBot('general');
        $player = $this->reloadPlayer($bot);

        [$target, $targetUserId] = $this->createForeignTarget($player);
        $target->addResources(new Resources(150000, 90000, 0, 0));
        $target->addUnit('light_fighter', 2000);

        $this->setScore((int) $bot->user_id, 50000);
        $this->setScore($targetUserId, 50000);
        $this->createSpyIntel($player, $target, [
            'metal' => 150000,
            'crystal' => 90000,
            'deuterium' => 0,
        ]);

        $home = $this->homePlanet($player);
        $this->setPlanetLevel($home, 'missile_silo', 4);
        $player->setResearchLevel('impulse_drive', 5);
        $home->addUnit('light_fighter', 200);
        $home->addUnit('interplanetary_missile', 10);
        $home->addResources(new Resources(0, 0, 5000000, 0));

        $this->playOnce($bot);

        $coords = $target->getPlanetCoordinates();

        $this->assertSame(
            0,
            FleetMission::where('user_id', $bot->user_id)->where('mission_type', 1)->count(),
            'The navy must not be thrown away against unbeatable force.',
        );

        $volley = FleetMission::where('user_id', $bot->user_id)
            ->where('mission_type', 10)
            ->where('galaxy_to', $coords->galaxy)
            ->where('system_to', $coords->system)
            ->where('position_to', $coords->position)
            ->first();

        $this->assertNotNull($volley, 'A soft target that beats the biggest fraction must draw a missile volley.');
        $this->assertGreaterThanOrEqual((int) config('bots.war.missiles.volley_min', 4), (int) $volley->interplanetary_missile);
    }

    public function testRaidCooldownSkipsRecentlyAttackedTarget(): void
    {
        $this->forceRaidRoll();

        $bot = $this->createBot('general');
        $player = $this->reloadPlayer($bot);

        [$target, $targetUserId] = $this->createForeignTarget($player);
        $target->addResources(new Resources(150000, 90000, 0, 0));

        $this->setScore((int) $bot->user_id, 50000);
        $this->setScore($targetUserId, 50000);
        $this->createSpyIntel($player, $target, [
            'metal' => 150000,
            'crystal' => 90000,
            'deuterium' => 0,
        ]);

        $home = $this->homePlanet($player);
        $home->addUnit('light_fighter', 200);

        // An attack we launched two hours ago that came home intact: the
        // avoid list does not apply, so only the cooldown may block a re-raid.
        $homeCoords = $home->getPlanetCoordinates();
        $targetCoords = $target->getPlanetCoordinates();
        $now = (int) Date::now()->timestamp;

        $attack = FleetMission::forceCreate([
            'user_id' => $bot->user_id,
            'mission_type' => 1,
            'planet_id_from' => $home->getPlanetId(),
            'galaxy_from' => $homeCoords->galaxy,
            'system_from' => $homeCoords->system,
            'position_from' => $homeCoords->position,
            'planet_id_to' => $target->getPlanetId(),
            'galaxy_to' => $targetCoords->galaxy,
            'system_to' => $targetCoords->system,
            'position_to' => $targetCoords->position,
            'time_departure' => $now - 7200,
            'time_arrival' => $now - 3600,
            'processed' => 1,
            'canceled' => 0,
            'light_fighter' => 100,
        ]);

        FleetMission::forceCreate([
            'user_id' => $bot->user_id,
            'mission_type' => 1,
            'parent_id' => $attack->id,
            'planet_id_from' => $target->getPlanetId(),
            'galaxy_from' => $targetCoords->galaxy,
            'system_from' => $targetCoords->system,
            'position_from' => $targetCoords->position,
            'planet_id_to' => $home->getPlanetId(),
            'galaxy_to' => $homeCoords->galaxy,
            'system_to' => $homeCoords->system,
            'position_to' => $homeCoords->position,
            'time_departure' => $now - 3600,
            'time_arrival' => $now + 3600,
            'processed' => 0,
            'canceled' => 0,
            'light_fighter' => 100,
        ]);

        $this->playOnce($bot);

        $this->assertSame(
            1,
            FleetMission::where('user_id', $bot->user_id)
                ->where('mission_type', 1)
                ->where('galaxy_to', $targetCoords->galaxy)
                ->where('system_to', $targetCoords->system)
                ->where('position_to', $targetCoords->position)
                ->count(),
            'Only the pre-existing attack may exist; the cooldown blocks a repeat raid.',
        );
    }

    public function testProtectsTopRankedHuman(): void
    {
        $this->forceRaidRoll();

        $bot = $this->createBot('general');
        $player = $this->reloadPlayer($bot);

        [$target, $targetUserId] = $this->createForeignTarget($player);
        $target->addResources(new Resources(150000, 90000, 0, 0));

        // Same score band so the only thing that can stop the raid is rank
        // protection, and a juicy report so the target looks worth raiding.
        $this->setScore((int) $bot->user_id, 500000);
        $this->setScore($targetUserId, 500000, 3);
        $this->createSpyIntel($player, $target, [
            'metal' => 150000,
            'crystal' => 90000,
            'deuterium' => 0,
        ]);

        $home = $this->homePlanet($player);
        $home->addUnit('light_fighter', 200);
        $home->addUnit('espionage_probe', 4);

        $this->playOnce($bot);

        $this->assertSame(
            0,
            FleetMission::where('user_id', $bot->user_id)
                ->whereIn('mission_type', [1, 6])
                ->count(),
            'Humans inside the leaderboard top ranks must never be scouted or raided.',
        );
    }

    public function testCollectorNeverRaids(): void
    {
        $this->forceRaidRoll();

        $bot = $this->createBot('collector');
        $player = $this->reloadPlayer($bot);

        [$target, $targetUserId] = $this->createForeignTarget($player);
        $target->addResources(new Resources(150000, 90000, 0, 0));

        $this->setScore((int) $bot->user_id, 50000);
        $this->setScore($targetUserId, 50000);
        $this->createSpyIntel($player, $target, [
            'metal' => 150000,
            'crystal' => 90000,
            'deuterium' => 0,
        ]);

        $home = $this->homePlanet($player);
        $home->addUnit('light_fighter', 200);

        $this->playOnce($bot);

        $this->assertSame(
            0,
            FleetMission::where('user_id', $bot->user_id)->where('mission_type', 1)->count(),
            'Low-aggression profiles pass below the attack gate and must not raid.',
        );
    }

    public function testSavesFleetFromOverwhelmingIncomingAttack(): void
    {
        config([
            'bots.war.raid_probability_scale' => 0.0,
            'bots.war.save_fleet.attempt_chance' => 1.0,
        ]);

        $bot = $this->createBot('general');
        $player = $this->reloadPlayer($bot);
        $player->setResearchLevel('astrophysics', 1);

        $home = $this->homePlanet($player);
        $home->addUnit('light_fighter', 20);
        $home->addResources(new Resources(0, 0, 50000, 0));

        // Bots spawn with a second planet, which the policy would evacuate
        // to by transport. Remove it so this exercises the deep space
        // expedition fallback for single-planet bots.
        Planet::where('user_id', $bot->user_id)
            ->where('id', '!=', $home->getPlanetId())
            ->delete();

        $attackerUser = User::factory()->create();
        UserTech::factory()->create(['user_id' => $attackerUser->id]);

        $coords = $home->getPlanetCoordinates();
        $now = (int) Date::now()->timestamp;
        FleetMission::forceCreate([
            'user_id' => $attackerUser->id,
            'mission_type' => 1,
            'planet_id_to' => $home->getPlanetId(),
            'galaxy_to' => $coords->galaxy,
            'system_to' => $coords->system,
            'position_to' => $coords->position,
            'time_departure' => $now,
            'time_arrival' => $now + 7200,
            'processed' => 0,
            'canceled' => 0,
            'light_fighter' => 150,
        ]);

        $this->playOnce($bot);

        // Single-planet bot: the evacuation target is a deep space
        // expedition (position 16) rather than another own planet.
        $save = FleetMission::where('user_id', $bot->user_id)
            ->where('mission_type', 15)
            ->where('position_to', 16)
            ->first();

        $this->assertNotNull($save, 'Outnumbered fleet should flee to deep space.');
        $this->assertGreaterThanOrEqual(5, $save->light_fighter);
    }

    public function testSavesFleetFromIncomingAcsAttack(): void
    {
        config([
            'bots.war.raid_probability_scale' => 0.0,
            'bots.war.save_fleet.attempt_chance' => 1.0,
        ]);

        $bot = $this->createBot('general');
        $player = $this->reloadPlayer($bot);
        $player->setResearchLevel('astrophysics', 1);

        $home = $this->homePlanet($player);
        $home->addUnit('light_fighter', 20);
        $home->addResources(new Resources(0, 0, 50000, 0));

        // Single-planet scenario again: the ACS fleet must read as a threat
        // exactly like a plain attack, so the fleet flees to deep space.
        Planet::where('user_id', $bot->user_id)
            ->where('id', '!=', $home->getPlanetId())
            ->delete();

        $attackerUser = User::factory()->create();
        UserTech::factory()->create(['user_id' => $attackerUser->id]);

        $coords = $home->getPlanetCoordinates();
        $now = (int) Date::now()->timestamp;
        FleetMission::forceCreate([
            'user_id' => $attackerUser->id,
            'mission_type' => 2,
            'planet_id_to' => $home->getPlanetId(),
            'galaxy_to' => $coords->galaxy,
            'system_to' => $coords->system,
            'position_to' => $coords->position,
            'time_departure' => $now,
            'time_arrival' => $now + 7200,
            'processed' => 0,
            'canceled' => 0,
            'light_fighter' => 150,
        ]);

        $this->playOnce($bot);

        $save = FleetMission::where('user_id', $bot->user_id)
            ->where('mission_type', 15)
            ->where('position_to', 16)
            ->first();

        $this->assertNotNull($save, 'ACS attack inbound should trigger the fleet save too.');
        $this->assertGreaterThanOrEqual(5, $save->light_fighter);
    }

    public function testFleetSaveHavenSkipsAcsTargetedPlanet(): void
    {
        config([
            'bots.war.raid_probability_scale' => 0.0,
            'bots.war.save_fleet.attempt_chance' => 1.0,
        ]);

        $bot = $this->createBot('general');
        $player = $this->reloadPlayer($bot);
        $player->setResearchLevel('astrophysics', 1);

        $home = $this->homePlanet($player);
        $home->addUnit('light_fighter', 20);
        $home->addResources(new Resources(0, 0, 50000, 0));

        // The second colony is the only transport haven — but an ACS fleet
        // is inbound on it, so it must not be used as the shelter.
        $colony = $this->secondPlanet($player);
        $colony->addUnit('light_fighter', 10);
        $colony->addResources(new Resources(0, 0, 50000, 0));

        $attackerUser = User::factory()->create();
        UserTech::factory()->create(['user_id' => $attackerUser->id]);

        $now = (int) Date::now()->timestamp;

        // Plain attack on the home world (triggers the save) and an ACS
        // attack on the colony (contaminates the haven).
        $homeCoords = $home->getPlanetCoordinates();
        FleetMission::forceCreate([
            'user_id' => $attackerUser->id,
            'mission_type' => 1,
            'planet_id_to' => $home->getPlanetId(),
            'galaxy_to' => $homeCoords->galaxy,
            'system_to' => $homeCoords->system,
            'position_to' => $homeCoords->position,
            'time_departure' => $now,
            'time_arrival' => $now + 7200,
            'processed' => 0,
            'canceled' => 0,
            'light_fighter' => 150,
        ]);

        $colonyCoords = $colony->getPlanetCoordinates();
        FleetMission::forceCreate([
            'user_id' => $attackerUser->id,
            'mission_type' => 2,
            'planet_id_to' => $colony->getPlanetId(),
            'galaxy_to' => $colonyCoords->galaxy,
            'system_to' => $colonyCoords->system,
            'position_to' => $colonyCoords->position,
            'time_departure' => $now,
            'time_arrival' => $now + 7500,
            'processed' => 0,
            'canceled' => 0,
            'light_fighter' => 150,
        ]);

        $this->playOnce($bot);

        // Whatever fled, it must not have transported onto the ACS-targeted
        // colony; with both planets endangered the only shelter is deep space.
        $this->assertSame(
            0,
            FleetMission::where('user_id', $bot->user_id)
                ->where('mission_type', 3)
                ->where('galaxy_to', $colonyCoords->galaxy)
                ->where('system_to', $colonyCoords->system)
                ->where('position_to', $colonyCoords->position)
                ->count(),
            'An ACS-targeted planet must never serve as the fleet-save haven.',
        );

        $this->assertGreaterThan(
            0,
            FleetMission::where('user_id', $bot->user_id)
                ->where('mission_type', 15)
                ->where('position_to', 16)
                ->count(),
            'With every planet endangered the save should go to deep space.',
        );
    }

    public function testFleetSaveOnlyAttemptedOnTheRoll(): void
    {
        config([
            'bots.war.raid_probability_scale' => 0.0,
            'bots.war.save_fleet.attempt_chance' => 0.0,
        ]);

        $bot = $this->createBot('general');
        $player = $this->reloadPlayer($bot);
        $player->setResearchLevel('astrophysics', 1);

        $home = $this->homePlanet($player);
        $home->addUnit('light_fighter', 20);
        $home->addResources(new Resources(0, 0, 50000, 0));

        $attackerUser = User::factory()->create();
        UserTech::factory()->create(['user_id' => $attackerUser->id]);

        $coords = $home->getPlanetCoordinates();
        $now = (int) Date::now()->timestamp;
        FleetMission::forceCreate([
            'user_id' => $attackerUser->id,
            'mission_type' => 1,
            'planet_id_to' => $home->getPlanetId(),
            'galaxy_to' => $coords->galaxy,
            'system_to' => $coords->system,
            'position_to' => $coords->position,
            'time_departure' => $now,
            'time_arrival' => $now + 7200,
            'processed' => 0,
            'canceled' => 0,
            'light_fighter' => 150,
        ]);

        $this->playOnce($bot);

        $this->assertSame(
            0,
            FleetMission::where('user_id', $bot->user_id)
                ->whereIn('mission_type', [3, 15])
                ->count(),
            'A failed attempt roll must leave the fleet sitting in the harbor.',
        );
    }
}
