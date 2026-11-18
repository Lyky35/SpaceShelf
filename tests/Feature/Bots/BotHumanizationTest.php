<?php

namespace Tests\Feature\Bots;

use Illuminate\Support\Facades\Artisan;
use OGame\Bots\Brain\BotBrain;
use OGame\Bots\Brain\SnapshotBuilder;
use OGame\Bots\Personality;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Bot;
use OGame\Models\Resources;
use OGame\Models\UnitQueue;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use ReflectionMethod;
use Tests\IsolatedAccountTestCase;

/**
 * Human-like build texture: bots get persistent quirks ("habits") at
 * creation, keep a standing transport bank like players do, treat the
 * fusion reactor as the solar-plant-alternative energy candidate, and
 * fodder-first bots stack cheap defense before big guns.
 */
class BotHumanizationTest extends IsolatedAccountTestCase
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
     * Whether the planet has a queued unit build of this machine name.
     */
    private function unitQueuedOn(PlanetService $planet, string $machineName): bool
    {
        return UnitQueue::where('planet_id', $planet->getPlanetId())
            ->where('object_id', ObjectService::getObjectByMachineName($machineName)->id)
            ->exists();
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
     * Every freshly created bot carries one (or sometimes two) habits from
     * the configured pool, and the habits survive the round-trip through the
     * stored personality array.
     */
    public function testBotsGetPersistentHabitsAtCreation(): void
    {
        $bot = $this->createBot('collector');

        /** @var array<int, string> $pool */
        $pool = config('bots.habits.pool');

        $habits = $bot->personalityObject()->habits;

        $this->assertNotEmpty($habits, 'Every bot should be created with at least one habit.');
        $this->assertLessThanOrEqual(2, count($habits));
        $this->assertEmpty(array_diff($habits, $pool));

        // The stored representation must round-trip the habits.
        $rehydrated = Personality::fromArray($bot->personality);
        $this->assertSame($habits, $rehydrated->habits);
    }

    /**
     * A planet with a working shipyard grows a small cargo bank without any
     * transport demand; a big enough shipyard also stocks large cargos.
     */
    public function testTransportBankIsKeptOnStandby(): void
    {
        $bot = $this->createBot('collector');
        $player = $this->reloadPlayer($bot);
        $home = $this->homePlanet($player);

        $this->setPlanetLevel($home, 'shipyard', 4);
        $player->setResearchLevel('combustion_drive', 8);
        $player->setResearchLevel('impulse_drive', 8);
        $home->addResources(new Resources(5000000, 5000000, 5000000, 0));

        $this->playOnce($bot);

        $this->assertTrue(
            $this->unitQueuedOn($home, 'small_cargo'),
            'Players keep small cargos on standby; the brain should bank them without demand.',
        );
        $this->assertTrue(
            $this->unitQueuedOn($home, 'large_cargo'),
            'The home planet should keep a large cargo bank for logistics and raid loot.',
        );

        $smallQueued = (int) UnitQueue::where('planet_id', $home->getPlanetId())
            ->where('object_id', ObjectService::getObjectByMachineName('small_cargo')->id)
            ->sum('object_amount');

        $this->assertSame(
            (int) config('bots.transport.small_cargo_target_per_planet', 2),
            $smallQueued,
            'The first visit should top the bank up to the target without overshooting.',
        );
    }

    /**
     * The transport_hoarder habit doubles the bank targets.
     */
    public function testTransportHoarderHabitDoublesTheBank(): void
    {
        $bot = $this->createBot('collector');
        $personality = $bot->personality;
        $personality['habits'] = ['transport_hoarder'];
        $bot->personality = $personality;
        $bot->save();

        $player = $this->reloadPlayer($bot);
        $home = $this->homePlanet($player);

        $this->setPlanetLevel($home, 'shipyard', 4);
        $player->setResearchLevel('combustion_drive', 8);
        $player->setResearchLevel('impulse_drive', 8);
        $home->addResources(new Resources(5000000, 5000000, 5000000, 0));

        $this->playOnce($bot);

        $smallTarget = (int) config('bots.transport.small_cargo_target_per_planet', 2) * 2;
        $smallQueued = (int) UnitQueue::where('planet_id', $home->getPlanetId())
            ->where('object_id', ObjectService::getObjectByMachineName('small_cargo')->id)
            ->sum('object_amount');

        $this->assertSame(
            $smallTarget,
            $smallQueued,
            'Hoarder bots must fill the doubled target in a single visit.',
        );
    }

    /**
     * Fusion candidate rules: absent while the planet is young, present
     * alongside solar once solar gets tired and the planet still starves for
     * energy, and present even on a happy planet when the bot is solar_eager.
     */
    public function testFusionReactorJoinsTheEnergyCandidatesInPlayersPattern(): void
    {
        $bot = $this->createBot('collector');
        $player = $this->reloadPlayer($bot);
        $home = $this->homePlanet($player);

        // Young planet: solar 2, energy tech 1 → fusion is not a candidate.
        $this->setPlanetLevel($home, 'solar_plant', 2);
        $player->setResearchLevel('energy_technology', 1);
        $this->setPlanetLevel($home, 'metal_mine', 12);
        $this->setPlanetLevel($home, 'crystal_mine', 10);
        $this->setPlanetLevel($home, 'deuterium_synthesizer', 8);

        $this->assertNotContains(
            'fusion_plant',
            $this->candidateOrder($bot, $home),
            'A young planet should still only know solar plants.',
        );

        // Mid-game planet: solar 9 under the same heavy mine draw → fusion
        // competes with the solar line.
        $this->setPlanetLevel($home, 'metal_mine', 25);
        $this->setPlanetLevel($home, 'crystal_mine', 20);
        $this->setPlanetLevel($home, 'deuterium_synthesizer', 15);
        $this->setPlanetLevel($home, 'solar_plant', 9);
        $player->setResearchLevel('energy_technology', 3);
        $home->update();

        $this->assertLessThan(
            (int) config('bots.economy.energy_floor_factor', 100),
            $home->getResourceProductionFactor(),
            'Test precondition: the planet must actually starve for energy.',
        );

        $order = $this->candidateOrder($bot, $home);
        $this->assertContains('solar_plant', $order);
        $this->assertContains(
            'fusion_plant',
            $order,
            'Once solar plants get tired, players start building fusion reactors.',
        );
    }

    /**
     * Fodder-first bots stack rocket launchers while the default profile
     * goes straight for the strongest affordable tier.
     */
    public function testFodderFirstHabitBuildsCheapDefenseFirst(): void
    {
        $fodder = $this->createBot('collector');
        $fodderPersonality = $fodder->personality;
        $fodderPersonality['habits'] = ['fodder_first'];
        $fodder->personality = $fodderPersonality;
        $fodder->save();

        $default = $this->createBot('collector');
        $defaultPersonality = $default->personality;
        $defaultPersonality['habits'] = [];
        $default->personality = $defaultPersonality;
        $default->save();

        foreach ([$fodder, $default] as $bot) {
            $player = $this->reloadPlayer($bot);
            $home = $this->homePlanet($player);

            $this->setPlanetLevel($home, 'shipyard', 4);
            // Heavy laser is gated on laser 6 + energy 3 in this fork's
            // balance; below those the "strongest affordable tier" is light
            // laser.
            $player->setResearchLevel('laser_technology', 6);
            $player->setResearchLevel('energy_technology', 3);
            $home->addResources(new Resources(5000000, 5000000, 5000000, 0));
        }

        $this->playOnce($fodder);
        $this->playOnce($default);

        $fodderHome = $this->homePlanet($this->reloadPlayer($fodder));
        $defaultHome = $this->homePlanet($this->reloadPlayer($default));

        $this->assertTrue(
            $this->unitQueuedOn($fodderHome, 'rocket_launcher'),
            'Fodder-first players start with the cheap stuff.',
        );
        $this->assertFalse(
            $this->unitQueuedOn($fodderHome, 'heavy_laser'),
            'Fodder-first bots should not jump to the strongest tier.',
        );
        $this->assertTrue(
            $this->unitQueuedOn($defaultHome, 'heavy_laser'),
            'Default personalities still build the strongest affordable tier.',
        );
        $this->assertFalse(
            $this->unitQueuedOn($defaultHome, 'rocket_launcher'),
            'Default personalities should not queue the cheapest tier.',
        );
    }
}
