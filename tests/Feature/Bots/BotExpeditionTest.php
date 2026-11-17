<?php

namespace Tests\Feature\Bots;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use OGame\Bots\Brain\BotBrain;
use OGame\Bots\Brain\SnapshotBuilder;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Bot;
use OGame\Models\DebrisField;
use OGame\Models\Enums\PlanetType;
use OGame\Models\FleetMission;
use OGame\Models\Resources;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use Tests\IsolatedAccountTestCase;

/**
 * Phase-4 expedition policy (docs/BOT-AI-PLAN.md §6): astrophysics-gated
 * expedition dispatch on personality-scaled odds, slot and redispatch
 * cooldown gating, and Pathfinder salvage of position-16 debris. Asserted
 * on dispatched fleet-mission rows only — expedition outcomes are RNG and
 * never hardcoded here (AGENTS.md).
 */
class BotExpeditionTest extends IsolatedAccountTestCase
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
     * Equip the bot's home planet so an expedition can actually launch:
     * astrophysics level, ships to sail and deuterium to pay for the trip.
     */
    private function equipForExpedition(PlayerService $player, int $astrophysics): PlanetService
    {
        $player->setResearchLevel('astrophysics', $astrophysics);

        $home = $this->homePlanet($player);
        $home->addUnit('light_fighter', 40);
        $home->addResources(new Resources(0, 0, 100000, 0));

        return $home;
    }

    /**
     * Fake an in-progress (outbound) expedition round trip: mission type 15
     * with no destination planet, exactly like a fleet sailing to deep space
     * looks in the fleet tables while it is away.
     */
    private function createActiveExpedition(Bot $bot, PlanetService $from, int $minutesAgo): FleetMission
    {
        $coords = $from->getPlanetCoordinates();
        $now = (int) Date::now()->timestamp;

        return FleetMission::forceCreate([
            'user_id' => $bot->user_id,
            'mission_type' => 15,
            'planet_id_from' => $from->getPlanetId(),
            'galaxy_from' => $coords->galaxy,
            'system_from' => $coords->system,
            'position_from' => $coords->position,
            'galaxy_to' => $coords->galaxy,
            'system_to' => $coords->system,
            'position_to' => 16,
            'type_to' => PlanetType::DeepSpace->value,
            'time_departure' => $now - $minutesAgo * 60,
            'time_arrival' => $now + 3600,
            'time_holding' => 0,
            'processed' => 0,
            'canceled' => 0,
            'light_fighter' => 10,
        ]);
    }

    public function testDiscovererSendsExpeditionToPosition16(): void
    {
        $bot = $this->createBot('discoverer');
        $player = $this->reloadPlayer($bot);
        $home = $this->equipForExpedition($player, 4);

        $this->playOnce($bot);

        $coords = $home->getPlanetCoordinates();
        $mission = FleetMission::where('user_id', $bot->user_id)
            ->where('mission_type', 15)
            ->where('galaxy_to', $coords->galaxy)
            ->where('system_to', $coords->system)
            ->where('position_to', 16)
            ->first();

        $this->assertNotNull($mission, 'Expected the Discoverer to send an expedition into deep space.');
        $this->assertSame(
            PlanetType::DeepSpace->value,
            $mission->type_to,
            'Expeditions target the deep space coordinate type.',
        );
        $this->assertSame(
            4 * 3600,
            (int) $mission->time_holding,
            'The expedition must stay out exactly as long as the astrophysics level allows.',
        );
        $this->assertGreaterThan(0, (int) $mission->light_fighter);
    }

    public function testSkipsExpeditionWithoutAstrophysics(): void
    {
        $bot = $this->createBot('discoverer');
        $player = $this->reloadPlayer($bot);

        $home = $this->homePlanet($player);
        $home->addUnit('light_fighter', 40);
        $home->addResources(new Resources(0, 0, 100000, 0));

        $this->playOnce($bot);

        $this->assertSame(
            0,
            FleetMission::where('user_id', $bot->user_id)->where('mission_type', 15)->count(),
            'Expeditions need astrophysics 1; the brain must not attempt them without it.',
        );
    }

    public function testSkipsWhenAllExpeditionSlotsInUse(): void
    {
        config(['bots.expedition.probability.general' => 1.0]);

        $bot = $this->createBot('general');
        $player = $this->reloadPlayer($bot);
        $home = $this->equipForExpedition($player, 3);

        // Occupy every expedition slot with fleets that are still away.
        $slots = $player->getExpeditionSlotsMax();
        $this->assertGreaterThan(0, $slots);

        for ($i = 0; $i < $slots; $i++) {
            $this->createActiveExpedition($bot, $home, 60);
        }

        $this->playOnce($bot);

        $this->assertSame(
            $slots,
            FleetMission::where('user_id', $bot->user_id)->where('mission_type', 15)->count(),
            'No expedition beyond the player slot limit may be dispatched.',
        );
    }

    public function testRedispatchCooldownBlocksFreshReturn(): void
    {
        $bot = $this->createBot('discoverer');
        $player = $this->reloadPlayer($bot);
        $home = $this->equipForExpedition($player, 4);

        // A fleet came home two hours ago (inside the 4 hour cooldown).
        $coords = $home->getPlanetCoordinates();
        $now = (int) Date::now()->timestamp;
        $outbound = FleetMission::forceCreate([
            'user_id' => $bot->user_id,
            'mission_type' => 15,
            'planet_id_from' => $home->getPlanetId(),
            'galaxy_from' => $coords->galaxy,
            'system_from' => $coords->system,
            'position_from' => $coords->position,
            'galaxy_to' => $coords->galaxy,
            'system_to' => $coords->system,
            'position_to' => 16,
            'type_to' => PlanetType::DeepSpace->value,
            'time_departure' => $now - 21600,
            'time_arrival' => $now - 7200,
            'processed' => 1,
            'canceled' => 0,
            'light_fighter' => 10,
        ]);

        FleetMission::forceCreate([
            'user_id' => $bot->user_id,
            'mission_type' => 15,
            'parent_id' => $outbound->id,
            'planet_id_from' => $home->getPlanetId(),
            'galaxy_from' => $coords->galaxy,
            'system_from' => $coords->system,
            'position_from' => $coords->position,
            'planet_id_to' => $home->getPlanetId(),
            'galaxy_to' => $coords->galaxy,
            'system_to' => $coords->system,
            'position_to' => $coords->position,
            'type_to' => PlanetType::Planet->value,
            'time_departure' => $now - 7200,
            'time_arrival' => $now - 3600,
            'processed' => 1,
            'canceled' => 0,
            'light_fighter' => 10,
        ]);

        $this->playOnce($bot);

        $this->assertSame(
            2,
            FleetMission::where('user_id', $bot->user_id)->where('mission_type', 15)->count(),
            'The fleet only just got back; the redispatch cooldown blocks a new expedition.',
        );
    }

    public function testSalvagesPosition16DebrisWithPathfinders(): void
    {
        // Salvage is stock-gated, not odds-gated: a collector with
        // Pathfinders in harbour must still go collect the debris.
        config(['bots.expedition.probability.collector' => 0.0]);

        $bot = $this->createBot('collector');
        $player = $this->reloadPlayer($bot);

        $home = $this->homePlanet($player);
        $home->addUnit('pathfinder', 2);
        $home->addResources(new Resources(0, 0, 100000, 0));

        $coords = $home->getPlanetCoordinates();
        DebrisField::forceCreate([
            'galaxy' => $coords->galaxy,
            'system' => $coords->system,
            'planet' => 16,
            'metal' => 100000,
            'crystal' => 50000,
            'deuterium' => 0,
        ]);

        $this->playOnce($bot);

        $mission = FleetMission::where('user_id', $bot->user_id)
            ->where('mission_type', 8)
            ->where('galaxy_to', $coords->galaxy)
            ->where('system_to', $coords->system)
            ->where('position_to', 16)
            ->first();

        $this->assertNotNull($mission, 'Expected a Pathfinder salvage run on the position-16 debris field.');
        $this->assertSame(PlanetType::DebrisField->value, $mission->type_to);
        $this->assertGreaterThan(0, (int) $mission->pathfinder);
    }

    public function testSkipsTinyDebrisField(): void
    {
        config(['bots.expedition.probability.collector' => 0.0]);

        $bot = $this->createBot('collector');
        $player = $this->reloadPlayer($bot);

        $home = $this->homePlanet($player);
        $home->addUnit('pathfinder', 2);
        $home->addResources(new Resources(0, 0, 100000, 0));

        $coords = $home->getPlanetCoordinates();
        DebrisField::forceCreate([
            'galaxy' => $coords->galaxy,
            'system' => $coords->system,
            'planet' => 16,
            'metal' => 400,
            'crystal' => 100,
            'deuterium' => 0,
        ]);

        $this->playOnce($bot);

        $this->assertSame(
            0,
            FleetMission::where('user_id', $bot->user_id)
                ->where('mission_type', 8)
                ->where('position_to', 16)
                ->count(),
            'Tiny debris fields are not worth a salvage sortie.',
        );
    }

    public function testNoSalvageWithoutPathfinders(): void
    {
        config(['bots.expedition.probability.collector' => 0.0]);

        $bot = $this->createBot('collector');
        $player = $this->reloadPlayer($bot);

        $home = $this->homePlanet($player);
        $home->addUnit('light_fighter', 10);
        $home->addResources(new Resources(0, 0, 100000, 0));

        $coords = $home->getPlanetCoordinates();
        DebrisField::forceCreate([
            'galaxy' => $coords->galaxy,
            'system' => $coords->system,
            'planet' => 16,
            'metal' => 100000,
            'crystal' => 50000,
            'deuterium' => 0,
        ]);

        $this->playOnce($bot);

        $this->assertSame(
            0,
            FleetMission::where('user_id', $bot->user_id)
                ->where('mission_type', 8)
                ->where('position_to', 16)
                ->count(),
            'Regular recyclers cannot harvest deep space debris; no mission without Pathfinders.',
        );
    }
}
