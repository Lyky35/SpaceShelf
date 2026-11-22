<?php

namespace Tests\Feature\Bots;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use OGame\Bots\Personality;
use OGame\Factories\PlayerServiceFactory;
use OGame\Jobs\BotHeartbeatJob;
use OGame\Models\Bot;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

/**
 * The brain's research phase queues the first affordable + researchable
 * research in `bots.research.priority`, so the vanilla expansion chain
 * (impulse drive 3 -> espionage 4 -> astrophysics 1) must lead the list:
 * astrophysics alone opens colony slots and expedition gates. If the chain
 * is buried behind high-cap economy tech, bots stall single-planet empires.
 *
 * These tests pin both ends of the chain: a fresh bot rushes impulse drive,
 * and a researched astrophysics level immediately unlocks colony ship
 * queueing.
 */
class BotResearchUnlockTest extends IsolatedAccountTestCase
{
    /**
     * Assert that a freshly created bot with its impulse prerequisites
     * satisfies queues the impulse drive before stacking economy tech.
     */
    public function testFreshBotQueuesImpulseDriveFirst(): void
    {
        $this->assertSame(0, Artisan::call('spaceshelf:bot:create', ['--count' => 1, '--profile' => 'collector']));

        $bot = Bot::orderByDesc('id')->firstOrFail();

        /** @var PlayerServiceFactory $playerServiceFactory */
        $playerServiceFactory = resolve(PlayerServiceFactory::class);
        $player = $playerServiceFactory->make($bot->user_id, true);
        $home = $player->planets->first();
        $this->assertNotNull($home);

        // Impulse drive prerequisites: energy technology 1 + research lab 2.
        $home->setObjectLevel(ObjectService::getObjectByMachineName('research_lab')->id, 2);
        $player->setResearchLevel('energy_technology', 1);
        $home->addResources(new Resources(500_000, 500_000, 200_000, 0));

        Bus::dispatchSync(new BotHeartbeatJob($bot->id));

        $queued = DB::table('research_queues')
            ->where('planet_id', $home->getPlanetId())
            ->where('processed', 0)
            ->pluck('object_id')
            ->all();

        $impulseId = ObjectService::getResearchObjectByMachineName('impulse_drive')->id;
        $this->assertContains(
            $impulseId,
            $queued,
            'Brain did not queue the impulse drive although it was affordable and unresearched'
        );
    }

    /**
     * Assert that the bottom of the chain pays off: with astrophysics 1 the
     * bot has a free colony slot and queues a colony ship right away.
     */
    public function testAstrophysicsUnlocksColonyShipQueueing(): void
    {
        $this->assertSame(0, Artisan::call('spaceshelf:bot:create', ['--count' => 1, '--profile' => 'discoverer']));

        $bot = Bot::orderByDesc('id')->firstOrFail();

        /** @var PlayerServiceFactory $playerServiceFactory */
        $playerServiceFactory = resolve(PlayerServiceFactory::class);
        $player = $playerServiceFactory->make($bot->user_id, true);
        $home = $player->planets->first();
        $this->assertNotNull($home);

        $this->assertSame(1, $player->getMaxPlanetAmount(), 'Fresh bot should be colony-locked');

        // Skip the chain: the research levels plus the shipyard a colony ship
        // needs, plus the resources to build one. Astrophysics 4 because fresh
        // accounts start with two planets and the astrophysics object grants
        // round(level / 2) colony slots on top of the home world.
        $home->setObjectLevel(ObjectService::getObjectByMachineName('shipyard')->id, 4);
        $player->setResearchLevel('impulse_drive', 3);
        $player->setResearchLevel('espionage_technology', 4);
        $player->setResearchLevel('astrophysics', 4);
        // Room for the mine/lab backlog the structure phase queues before it
        // reaches the unit phase (queued builds deduct immediately).
        $home->addResources(new Resources(5_000_000, 5_000_000, 2_000_000, 0));

        $this->assertGreaterThan($player->planets->planetCount(), $player->getMaxPlanetAmount());

        Bus::dispatchSync(new BotHeartbeatJob($bot->id));

        $queued = DB::table('unit_queues')
            ->where('planet_id', $home->getPlanetId())
            ->where('processed', 0)
            ->pluck('object_id')
            ->all();

        $colonyShipId = ObjectService::getObjectByMachineName('colony_ship')->id;
        $this->assertContains(
            $colonyShipId,
            $queued,
            'Brain did not queue a colony ship although a colony slot was free and a shipyard was standing'
        );
    }

    /**
     * Assert that no personality cap can sink the expansion chain below its
     * object prerequisites: colony ships and astrophysics both require
     * impulse drive 3, astrophysics also requires espionage 4.
     */
    public function testResearchCapsKeepTheAstrophysicsChainReachable(): void
    {
        /** @var array<string, array<string, float>> $personalities */
        $personalities = config('bots.personalities');
        /** @var array<string, int> $caps */
        $caps = config('bots.research.caps');
        /** @var array<string, array{group: array<int, string>, trait: string}> $scaling */
        $scaling = config('bots.research.cap_scaling');

        /** @var array<string, int> $chainGates Machine name to required level. */
        $chainGates = ['impulse_drive' => 3, 'espionage_technology' => 4];

        foreach ($personalities as $profile => $traits) {
            $personality = new Personality(
                profile: (string) $profile,
                aggression: (float) $traits['aggression'],
                colonisationBias: (float) $traits['colonisation_bias'],
                militaryShare: (float) $traits['military_share'],
                defenseBias: (float) $traits['defense_bias'],
                researchDrive: (float) $traits['research_drive'],
            );

            foreach ($chainGates as $machineName => $requiredLevel) {
                $traitName = null;
                foreach ($scaling as $policy) {
                    if (in_array($machineName, $policy['group'], true)) {
                        $traitName = $policy['trait'];
                        break;
                    }
                }

                $this->assertNotNull(
                    $traitName,
                    sprintf('%s is not covered by bots.research.cap_scaling', $machineName)
                );

                $cap = $personality->scaledCap($caps[$machineName], (float) $personality->{$traitName});

                $this->assertGreaterThanOrEqual(
                    $requiredLevel,
                    $cap,
                    sprintf(
                        'profile "%s" caps %s at %d, below the expansion-chain prerequisite of %d',
                        $profile,
                        $machineName,
                        $cap,
                        $requiredLevel
                    )
                );
            }
        }
    }
}
