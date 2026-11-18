<?php

namespace Tests\Feature\Bots;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use OGame\Bots\Brain\BotBrain;
use OGame\Bots\Brain\SnapshotBuilder;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Bot;
use OGame\Models\FleetMission;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\Resources;
use OGame\Models\UnitQueue;
use OGame\Models\User;
use OGame\Models\UserTech;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use ReflectionMethod;
use ReflectionProperty;
use Tests\IsolatedAccountTestCase;

/**
 * Interplanetary missile texture: aggressive personalities raise a silo,
 * bank IGMs in it, and the war policy spends a volley on the raid target
 * its battle sim says the fleet cannot crack.
 */
class BotMissilePolicyTest extends IsolatedAccountTestCase
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
     * The bot's home planet: always the lowest planet id.
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
     * Give a planet a level of the given object.
     */
    private function setPlanetLevel(PlanetService $planet, string $machineName, int $level): void
    {
        $planet->setObjectLevel(ObjectService::getObjectByMachineName($machineName)->id, $level);
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
     * Score the structure candidates for one planet through the private
     * scoring pass, for assertions that do not depend on affordability or
     * the per-visit action cap.
     *
     * @return array<int, string>
     */
    private function candidateOrder(Bot $bot, PlanetService $planet): array
    {
        $brain = resolve(BotBrain::class);

        $method = new ReflectionMethod($brain, 'structureCandidateOrder');
        $method->setAccessible(true);

        /** @var array<int, string> $order */
        $order = $method->invoke($brain, $planet, $bot->personalityObject());

        return $order;
    }

    /**
     * The WarPolicy instance the resolved brain routes its war decisions
     * through, for testing the missile volley without staging a full raid.
     */
    private function warPolicy(): object
    {
        $brain = resolve(BotBrain::class);
        $property = new ReflectionProperty($brain, 'warPolicy');
        $property->setAccessible(true);

        return $property->getValue($brain);
    }

    /**
     * Whether the planet has a queued unit build of this machine name.
     */
    private function unitQueuedOn(PlanetService $planet, string $machineName): bool
    {
        return UnitQueue::where('planet_id', $planet->getPlanetId())
            ->where('object_id', ObjectService::getObjectByMachineName($machineName)->id)
            ->exists();
    }

    /**
     * Create a foreign planet in the bot's home system at a free position
     * and return its coordinate.
     */
    private function foreignCoordinate(PlayerService $player): Coordinate
    {
        $coords = $this->homePlanet($player)->getPlanetCoordinates();

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

        $this->assertNotNull($position, 'No free position in the bot home system for a foreign planet.');

        $foreignUser = User::factory()->create();
        UserTech::factory()->create(['user_id' => $foreignUser->id]);

        Planet::factory()->create([
            'user_id' => $foreignUser->id,
            'galaxy' => $coords->galaxy,
            'system' => $coords->system,
            'planet' => $position,
            'time_last_update' => (int) Date::now()->timestamp,
        ]);

        return new Coordinate($coords->galaxy, $coords->system, $position);
    }

    /**
     * Aggressive personalities keep a missile silo as a building candidate
     * even with zero defense agenda; defensive personalities with empty
     * defense lines do not (their silo comes later, with the ABM stock).
     */
    public function testMissileSiloIsScoredForAggressivePersonalities(): void
    {
        $general = $this->createBot('general');
        $generalHome = $this->homePlanet($this->reloadPlayer($general));

        $this->assertContains(
            'missile_silo',
            $this->candidateOrder($general, $generalHome),
            'Generals raise a silo so they can bank interplanetary missiles.',
        );

        $collector = $this->createBot('collector');
        $collectorHome = $this->homePlanet($this->reloadPlayer($collector));

        $this->assertNotContains(
            'missile_silo',
            $this->candidateOrder($collector, $collectorHome),
            'Collectors reach for the silo only once their defense line is standing.',
        );
    }

    /**
     * A general with a silo banks interplanetary missiles in batches.
     */
    public function testGeneralBanksInterplanetaryMissiles(): void
    {
        $bot = $this->createBot('general');
        $player = $this->reloadPlayer($bot);
        $home = $this->homePlanet($player);

        // The engine lets players build IGMs from silo level 4.
        $this->setPlanetLevel($home, 'missile_silo', 4);
        $this->setPlanetLevel($home, 'shipyard', 4);
        $player->setResearchLevel('combustion_drive', 5);
        $player->setResearchLevel('impulse_drive', 5);
        $home->addResources(new Resources(5000000, 5000000, 5000000, 5000000));

        $this->playOnce($bot);

        $this->assertTrue(
            $this->unitQueuedOn($home, 'interplanetary_missile'),
            'Aggressive players keep an IGM bank in the silo.',
        );

        $queued = (int) UnitQueue::where('planet_id', $home->getPlanetId())
            ->where('object_id', ObjectService::getObjectByMachineName('interplanetary_missile')->id)
            ->sum('object_amount');

        $this->assertSame(
            (int) config('bots.military.igm_batch_size', 5),
            $queued,
            'The bank grows in configured batches, not all at once.',
        );
    }

    /**
     * A general never holding a silo must not try to bank missiles: the
     * stock build stays gated on the building pass actually raising it.
     */
    public function testMissileBankWaitsForTheSilo(): void
    {
        $bot = $this->createBot('general');
        $player = $this->reloadPlayer($bot);
        $home = $this->homePlanet($player);

        $this->setPlanetLevel($home, 'shipyard', 4);
        $player->setResearchLevel('combustion_drive', 5);
        $player->setResearchLevel('impulse_drive', 5);
        $home->addResources(new Resources(5000000, 5000000, 5000000, 5000000));

        $this->playOnce($bot);

        $this->assertFalse(
            $this->unitQueuedOn($home, 'interplanetary_missile'),
            'Without a silo there is nowhere to store IGMs.',
        );
    }

    /**
     * The missile volley: fired against a soft target (worth raiding, fleet
     * cannot win) from the planet holding the biggest IGM bank.
     */
    public function testMissileVolleyLaunchesAgainstSoftTarget(): void
    {
        // Deterministic volley: remove the probability roll.
        config()->set('bots.war.missiles.probability', 1.0);

        $bot = $this->createBot('general');
        $player = $this->reloadPlayer($bot);
        $home = $this->homePlanet($player);
        $target = $this->foreignCoordinate($player);

        $this->setPlanetLevel($home, 'missile_silo', 4);
        $player->setResearchLevel('impulse_drive', 5);
        $home->addUnit('interplanetary_missile', 10);
        $home->addResources(new Resources(0, 0, 0, 5000000));

        $snapshot = resolve(SnapshotBuilder::class)->build($this->reloadPlayer($bot));

        $method = new ReflectionMethod($this->warPolicy(), 'missileVolley');
        $method->setAccessible(true);

        $fired = (int) $method->invoke(
            $this->warPolicy(),
            $snapshot,
            $bot->personalityObject(),
            [$target],
            3600,
        );

        $this->assertSame(1, $fired, 'The volley should be dispatched against the soft target.');

        $mission = FleetMission::where('user_id', $bot->user_id)
            ->where('mission_type', 10)
            ->first();

        $this->assertNotNull($mission, 'Interplanetary missiles should leave as a mission type 10 flight.');
        $this->assertSame($target->galaxy, $mission->galaxy_to);
        $this->assertSame($target->system, $mission->system_to);
        $this->assertSame($target->position, $mission->position_to);
        $this->assertGreaterThanOrEqual(
            (int) config('bots.war.missiles.volley_min', 4),
            (int) $mission->interplanetary_missile,
            'The volley must carry at least the configured minimum of missiles.',
        );

        // Below the volley minimum the bank is not worth spending: fire at
        // a fresh target (the first one is on cooldown) with an unreachable
        // minimum configured.
        config()->set('bots.war.missiles.volley_min', 50);
        $secondTarget = $this->foreignCoordinate($this->reloadPlayer($bot));

        $fired = (int) $method->invoke(
            $this->warPolicy(),
            resolve(SnapshotBuilder::class)->build($this->reloadPlayer($bot)),
            $bot->personalityObject(),
            [$secondTarget],
            3600,
        );

        $this->assertSame(0, $fired, 'A bank below the volley minimum should not be spent.');
        $this->assertSame(1, FleetMission::where('user_id', $bot->user_id)->where('mission_type', 10)->count());
    }
}
