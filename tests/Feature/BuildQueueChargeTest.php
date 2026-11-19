<?php

namespace Tests\Feature;

use Exception;
use OGame\Models\BuildingQueue;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use Tests\IsolatedAccountTestCase;

/**
 * Verifies that the building queue charges resources when an item is queued, as
 * vanilla OGame does, and refunds the full price when an item is canceled.
 */
class BuildQueueChargeTest extends IsolatedAccountTestCase
{
    /**
     * Verify that placing a build request immediately deducts the construction
     * cost and stores that price on the queue item.
     * @throws Exception
     */
    public function testChargesResourcesWhenQueued(): void
    {
        $this->planetAddResources(new Resources(100000, 100000, 100000, 0));
        $this->planetService->reloadPlanet();

        $initial_metal = $this->planetService->metal()->get();
        $initial_crystal = $this->planetService->crystal()->get();

        $price = ObjectService::getObjectRawPrice('metal_mine', 1);

        $this->addResourceBuildRequest('metal_mine');

        // Resources must be gone right away, before construction even finishes.
        $this->planetService->reloadPlanet();
        $this->assertEquals($initial_metal - $price->metal->get(), $this->planetService->metal()->get());
        $this->assertEquals($initial_crystal - $price->crystal->get(), $this->planetService->crystal()->get());

        // The charged price must be stored on the queue item so it can be refunded on cancel.
        $queue_item = BuildingQueue::where('planet_id', $this->currentPlanetId)
            ->where('canceled', 0)
            ->firstOrFail();
        $this->assertEquals($price->metal->get(), (int)$queue_item->metal);
        $this->assertEquals($price->crystal->get(), (int)$queue_item->crystal);
        $this->assertEquals($price->deuterium->get(), (int)$queue_item->deuterium);
    }

    /**
     * Verify that a build request that the planet cannot pay for is rejected
     * outright and does not deduct anything.
     * @throws Exception
     */
    public function testRejectsBuildRequestWhenNotAffordable(): void
    {
        $this->planetDeductResources(new Resources(500, 500, 0, 0));

        $object = ObjectService::getObjectByMachineName('metal_mine');
        $response = $this->post('/resources/add-buildrequest', [
            '_token' => csrf_token(),
            'technologyId' => $object->id,
        ]);

        $response->assertStatus(200);
        $this->assertFalse((bool)$response->json('success'));

        $this->planetService->reloadPlanet();
        $this->assertEquals(0, $this->planetService->metal()->get());
        $this->assertEquals(0, $this->planetService->crystal()->get());

        $this->assertEquals(0, BuildingQueue::where('planet_id', $this->currentPlanetId)->where('canceled', 0)->count());
    }

    /**
     * Verify that canceling queued (not yet started) items refunds their full
     * price. Canceling the first metal mine cascades to the second one.
     * @throws Exception
     */
    public function testCancelRefundsQueuedItems(): void
    {
        $this->planetAddResources(new Resources(100000, 100000, 100000, 0));
        $this->planetService->reloadPlanet();

        $initial_metal = $this->planetService->metal()->get();
        $initial_crystal = $this->planetService->crystal()->get();

        $this->addResourceBuildRequest('metal_mine');
        $this->addResourceBuildRequest('metal_mine');

        $queue_items = BuildingQueue::where('planet_id', $this->currentPlanetId)
            ->where('canceled', 0)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $queue_items);
        $first_item = $queue_items[0];
        $second_item = $queue_items[1];
        $this->assertNotNull($first_item);
        $this->assertNotNull($second_item);

        $this->cancelResourceBuildRequest($first_item->object_id, $first_item->id);

        $this->planetService->reloadPlanet();
        $this->assertEquals($initial_metal, $this->planetService->metal()->get());
        $this->assertEquals($initial_crystal, $this->planetService->crystal()->get());

        // Both cascade-canceled items must be marked canceled and refund nothing more.
        $first_item->refresh();
        $second_item->refresh();
        $this->assertEquals(1, (int)$first_item->canceled);
        $this->assertEquals(1, (int)$second_item->canceled);
    }

    /**
     * Verify that an item that is already under construction still refunds its
     * full price when canceled.
     * @throws Exception
     */
    public function testCancelRefundsInProgressItem(): void
    {
        $this->planetAddResources(new Resources(100000, 100000, 100000, 0));
        $this->planetService->reloadPlanet();

        $initial_metal = $this->planetService->metal()->get();
        $initial_crystal = $this->planetService->crystal()->get();

        $this->addResourceBuildRequest('metal_mine');
        $this->travel(1)->seconds();

        $queue_item = BuildingQueue::where('planet_id', $this->currentPlanetId)
            ->where('canceled', 0)
            ->firstOrFail();
        $this->assertEquals(1, (int)$queue_item->building);

        $this->cancelResourceBuildRequest($queue_item->object_id, $queue_item->id);

        $this->planetService->reloadPlanet();
        $this->assertEquals($initial_metal, $this->planetService->metal()->get());
        $this->assertEquals($initial_crystal, $this->planetService->crystal()->get());
    }

    /**
     * Verify that the planet balance caps the queue: the queue stops when the
     * next item can no longer be paid for.
     * @throws Exception
     */
    public function testQueueStopsWhenPlanetCannotPay(): void
    {
        // Leave the starter planet with exactly the resources for one metal mine
        // upgrade: the first request succeeds and spends them, the second one
        // must be rejected because nothing is left to pay for it.
        $price = ObjectService::getObjectRawPrice('metal_mine', 1);
        $this->planetDeductResources(new Resources(
            max(0, 500 - $price->metal->get()),
            max(0, 500 - $price->crystal->get()),
            0,
            0
        ));

        $this->addResourceBuildRequest('metal_mine');
        $this->addResourceBuildRequest('metal_mine', true);

        $active_items = BuildingQueue::where('planet_id', $this->currentPlanetId)
            ->where('canceled', 0)
            ->count();
        $this->assertEquals(1, $active_items);

        $this->planetService->reloadPlanet();
        $this->assertEquals(0, $this->planetService->metal()->get());
        $this->assertEquals(0, $this->planetService->crystal()->get());
    }

    /**
     * Verify that a tear down queued behind an upgrade is charged immediately,
     * even though the tear down itself has not started yet. A solar plant is
     * used so the planet has no metal or crystal production to skew the checks.
     * @throws Exception
     */
    public function testDowngradeChargedWhenQueued(): void
    {
        $this->planetSetObjectLevel('solar_plant', 1);
        $this->planetAddResources(new Resources(100000, 100000, 100000, 0));
        $this->planetService->reloadPlanet();

        $initial_metal = $this->planetService->metal()->get();
        $initial_crystal = $this->planetService->crystal()->get();

        // Occupy the queue with a solar plant upgrade; it starts building immediately.
        $this->addResourceBuildRequest('solar_plant');

        $object = ObjectService::getObjectByMachineName('solar_plant');
        $response = $this->post('/resources/downgrade', [
            '_token' => csrf_token(),
            'technologyId' => $object->id,
        ]);
        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
        ]);

        // The tear down happens at level 2 (after the queued upgrade completes), so
        // its price must have been locked in and charged at queue time.
        $downgrade_price = ObjectService::getObjectDowngradePrice('solar_plant', $this->planetService, 2);
        $upgrade_price = ObjectService::getObjectRawPrice('solar_plant', 2);

        $this->planetService->reloadPlanet();
        $this->assertEquals(
            $initial_metal - $upgrade_price->metal->get() - $downgrade_price->metal->get(),
            $this->planetService->metal()->get()
        );
        $this->assertEquals(
            $initial_crystal - $upgrade_price->crystal->get() - $downgrade_price->crystal->get(),
            $this->planetService->crystal()->get()
        );

        // The downgrade must not be building yet, but must already store its price.
        $queue_items = BuildingQueue::where('planet_id', $this->currentPlanetId)
            ->where('canceled', 0)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $queue_items);
        $downgrade_item = $queue_items[1];
        $this->assertNotNull($downgrade_item);
        $this->assertTrue((bool)$downgrade_item->is_downgrade);
        $this->assertEquals(0, (int)$downgrade_item->building);
        $this->assertEquals($downgrade_price->metal->get(), (int)$downgrade_item->metal);
        $this->assertEquals($downgrade_price->crystal->get(), (int)$downgrade_item->crystal);
    }

    /**
     * Verify that canceling a queued tear down refunds its locked-in price and
     * leaves the still queued upgrade untouched.
     * @throws Exception
     */
    public function testCancelRefundsQueuedDowngrade(): void
    {
        $this->planetSetObjectLevel('solar_plant', 1);
        $this->planetAddResources(new Resources(100000, 100000, 100000, 0));
        $this->planetService->reloadPlanet();

        $this->addResourceBuildRequest('solar_plant');

        $object = ObjectService::getObjectByMachineName('solar_plant');
        $response = $this->post('/resources/downgrade', [
            '_token' => csrf_token(),
            'technologyId' => $object->id,
        ]);
        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
        ]);

        $queue_items = BuildingQueue::where('planet_id', $this->currentPlanetId)
            ->where('canceled', 0)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $queue_items);
        $downgrade_item = $queue_items[1];
        $this->assertNotNull($downgrade_item);

        $this->planetService->reloadPlanet();
        $metal_after_charge = $this->planetService->metal()->get();
        $crystal_after_charge = $this->planetService->crystal()->get();

        $this->cancelResourceBuildRequest($downgrade_item->object_id, $downgrade_item->id);

        $this->planetService->reloadPlanet();
        $this->assertEquals($metal_after_charge + (int)$downgrade_item->metal, $this->planetService->metal()->get());
        $this->assertEquals($crystal_after_charge + (int)$downgrade_item->crystal, $this->planetService->crystal()->get());

        // The upgrade must still be queued and charged.
        $active_items = BuildingQueue::where('planet_id', $this->currentPlanetId)
            ->where('canceled', 0)
            ->orderBy('id')
            ->get();
        $this->assertCount(1, $active_items);
        $upgrade_item = $active_items[0];
        $this->assertNotNull($upgrade_item);
        $this->assertFalse((bool)$upgrade_item->is_downgrade);
        $downgrade_item->refresh();
        $this->assertEquals(1, (int)$downgrade_item->canceled);
    }
}
