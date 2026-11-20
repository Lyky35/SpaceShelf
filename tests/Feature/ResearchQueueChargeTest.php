<?php

namespace Tests\Feature;

use Exception;
use OGame\Models\ResearchQueue;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;

/**
 * Verifies that the research queue charges resources when an item is queued, as
 * vanilla OGame does, and refunds the full price when an item is canceled.
 */
class ResearchQueueChargeTest extends IsolatedAccountTestCase
{
    /**
     * Verify that placing a research request immediately deducts the research
     * cost and stores that price on the queue item.
     * @throws Exception
     */
    public function testChargesResourcesWhenQueued(): void
    {
        $this->planetSetObjectLevel('research_lab', 1);
        $this->planetAddResources(new Resources(100000, 100000, 100000, 0));
        $this->planetService->reloadPlanet();

        $initial_crystal = $this->planetService->crystal()->get();
        $initial_deuterium = $this->planetService->deuterium()->get();

        $price = ObjectService::getObjectRawPrice('energy_technology', 1);

        $this->addResearchBuildRequest('energy_technology');

        // Resources must be gone right away, before the research even finishes.
        $this->planetService->reloadPlanet();
        $this->assertEquals($initial_crystal - $price->crystal->get(), $this->planetService->crystal()->get());
        $this->assertEquals($initial_deuterium - $price->deuterium->get(), $this->planetService->deuterium()->get());

        // The charged price must be stored on the queue item so it can be refunded on cancel.
        $queue_item = ResearchQueue::where('planet_id', $this->currentPlanetId)
            ->where('canceled', 0)
            ->firstOrFail();
        $this->assertEquals($price->metal->get(), (int)$queue_item->metal);
        $this->assertEquals($price->crystal->get(), (int)$queue_item->crystal);
        $this->assertEquals($price->deuterium->get(), (int)$queue_item->deuterium);
    }

    /**
     * Verify that a research request that the planet cannot pay for is rejected
     * outright and does not deduct anything.
     * @throws Exception
     */
    public function testRejectsResearchRequestWhenNotAffordable(): void
    {
        $this->planetSetObjectLevel('research_lab', 1);
        $this->planetService->reloadPlanet();

        $initial_crystal = $this->planetService->crystal()->get();
        $initial_deuterium = $this->planetService->deuterium()->get();

        // The starter balance must genuinely be unable to cover the research,
        // otherwise this test verifies nothing.
        $price = ObjectService::getObjectRawPrice('energy_technology', 1);
        if ($initial_deuterium >= $price->deuterium->get() && $initial_crystal >= $price->crystal->get()) {
            $this->fail('Starter planet balance covers the research price; unaffordable scenario is invalid.');
        }

        $object = ObjectService::getObjectByMachineName('energy_technology');
        $response = $this->post('/research/add-buildrequest', [
            '_token' => csrf_token(),
            'technologyId' => $object->id,
        ]);

        $response->assertStatus(200);
        $this->assertFalse((bool)$response->json('success'));

        $this->planetService->reloadPlanet();
        $this->assertEquals($initial_crystal, $this->planetService->crystal()->get());
        $this->assertEquals($initial_deuterium, $this->planetService->deuterium()->get());

        $this->assertEquals(0, ResearchQueue::where('planet_id', $this->currentPlanetId)->where('canceled', 0)->count());
    }

    /**
     * Verify that canceling a queued (not yet started) item refunds both its own
     * and the cascaded queued item's full price.
     * @throws Exception
     */
    public function testCancelRefundsQueuedItems(): void
    {
        $this->planetSetObjectLevel('research_lab', 1);
        $this->planetAddResources(new Resources(0, 100000, 100000, 0));
        $this->planetService->reloadPlanet();

        $initial_crystal = $this->planetService->crystal()->get();
        $initial_deuterium = $this->planetService->deuterium()->get();

        $this->addResearchBuildRequest('energy_technology');
        $this->addResearchBuildRequest('energy_technology');

        $queue_items = ResearchQueue::where('planet_id', $this->currentPlanetId)
            ->where('canceled', 0)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $queue_items);
        $first_item = $queue_items[0];
        $second_item = $queue_items[1];
        $this->assertNotNull($first_item);
        $this->assertNotNull($second_item);

        // The second level is queued behind the running research and must already
        // have been charged its target-level price.
        $this->assertEquals(0, (int)$second_item->building);
        $level_two_price = ObjectService::getObjectRawPrice('energy_technology', 2);
        $this->assertEquals($level_two_price->crystal->get(), (int)$second_item->crystal);
        $this->assertEquals($level_two_price->deuterium->get(), (int)$second_item->deuterium);

        $this->cancelResearchBuildRequest($first_item->object_id, $first_item->id);

        $this->planetService->reloadPlanet();
        $this->assertEquals($initial_crystal, $this->planetService->crystal()->get());
        $this->assertEquals($initial_deuterium, $this->planetService->deuterium()->get());

        // Both cascade-canceled items must be marked canceled and refund nothing more.
        $first_item->refresh();
        $second_item->refresh();
        $this->assertEquals(1, (int)$first_item->canceled);
        $this->assertEquals(1, (int)$second_item->canceled);
    }

    /**
     * Verify that an item that is already researching still refunds its full
     * price when canceled.
     * @throws Exception
     */
    public function testCancelRefundsInProgressItem(): void
    {
        $this->planetSetObjectLevel('research_lab', 1);
        $this->planetAddResources(new Resources(0, 100000, 100000, 0));
        $this->planetService->reloadPlanet();

        $initial_crystal = $this->planetService->crystal()->get();
        $initial_deuterium = $this->planetService->deuterium()->get();

        $this->addResearchBuildRequest('energy_technology');
        $this->travel(1)->seconds();

        $queue_item = ResearchQueue::where('planet_id', $this->currentPlanetId)
            ->where('canceled', 0)
            ->firstOrFail();
        $this->assertEquals(1, (int)$queue_item->building);

        $this->cancelResearchBuildRequest($queue_item->object_id, $queue_item->id);

        $this->planetService->reloadPlanet();
        $this->assertEquals($initial_crystal, $this->planetService->crystal()->get());
        $this->assertEquals($initial_deuterium, $this->planetService->deuterium()->get());
    }

    /**
     * Verify that the planet balance caps the queue: the queue stops when the
     * next research can no longer be paid for.
     * @throws Exception
     */
    public function testQueueStopsWhenPlanetCannotPay(): void
    {
        $this->planetSetObjectLevel('research_lab', 1);
        $this->planetAddResources(new Resources(0, 10000, 10000, 0));
        $this->planetService->reloadPlanet();

        // Leave the planet with exactly the resources for one level of energy
        // technology: the first request spends them, the second one (level 2,
        // double the price) must be rejected because nothing is left to pay for it.
        $price = ObjectService::getObjectRawPrice('energy_technology', 1);
        $this->planetDeductResources(new Resources(
            0,
            $this->planetService->crystal()->get() - $price->crystal->get(),
            $this->planetService->deuterium()->get() - $price->deuterium->get(),
            0
        ));
        $this->planetService->reloadPlanet();

        $this->addResearchBuildRequest('energy_technology');
        $this->addResearchBuildRequest('energy_technology');

        $active_items = ResearchQueue::where('planet_id', $this->currentPlanetId)
            ->where('canceled', 0)
            ->count();
        $this->assertEquals(1, $active_items);

        $this->planetService->reloadPlanet();
        $this->assertEquals(0, $this->planetService->crystal()->get());
        $this->assertEquals(0, $this->planetService->deuterium()->get());
    }

    /**
     * Verify that finishing a research charges the price exactly once: with
     * queue-time charging, completion and the start of the next queue item must
     * not deduct anything extra.
     * @throws Exception
     */
    public function testResearchCompletionChargesPriceOnce(): void
    {
        // Match the fast research settings used by the other research tests so the
        // item finishes within the traveled time.
        $settingsService = resolve(SettingsService::class);
        $settingsService->set('economy_speed', 8);
        $settingsService->set('research_speed', 2);

        $this->planetSetObjectLevel('research_lab', 1);
        $this->planetAddResources(new Resources(0, 100000, 100000, 0));
        $this->planetService->reloadPlanet();

        $initial_crystal = $this->planetService->crystal()->get();
        $initial_deuterium = $this->planetService->deuterium()->get();

        $price = ObjectService::getObjectRawPrice('energy_technology', 1);

        $this->addResearchBuildRequest('energy_technology');

        $this->travel(10)->minutes();

        // A page load processes the finished research.
        $response = $this->get('/research');
        $response->assertStatus(200);
        $this->assertObjectLevelOnPage($response, 'energy_technology', 1, 'Energy technology did not finish within the research time.');

        // The balance must show the single queue-time deduction and nothing more.
        $this->planetService->reloadPlanet();
        $this->assertEquals($initial_crystal - $price->crystal->get(), $this->planetService->crystal()->get());
        $this->assertEquals($initial_deuterium - $price->deuterium->get(), $this->planetService->deuterium()->get());
    }
}
