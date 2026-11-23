<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;

/**
 * Tests the construction wrench icon of the left-hand planet menu: it must
 * only appear while a planet actually has construction running, even when a
 * finished build queue item has not been processed yet because the planet is
 * not the player's current one (page-load queue updates only touch the
 * current planet).
 */
class PlanetMenuConstructionIconTest extends IsolatedAccountTestCase
{
    /**
     * Count the construction icons rendered in the planet list of a response.
     */
    private function constructionIconCount(string $content): int
    {
        return substr_count($content, 'icon_wrench');
    }

    /**
     * A finished-but-unprocessed build queue item on an inactive planet must
     * not keep the wrench icon of that planet lit; opening the planet then
     * materializes the build and the icon stays gone.
     */
    public function testConstructionIconClearsAfterBuildFinishedOnInactivePlanet(): void
    {
        $settingsService = resolve(SettingsService::class);
        $settingsService->set('economy_speed', 8);

        $this->assertNotNull($this->secondPlanetService);
        $secondPlanetId = $this->secondPlanetService->getPlanetId();

        // Queue a metal mine on the second planet, then move the active
        // planet context back to the first planet.
        $this->switchToSecondPlanet();
        $this->addResourceBuildRequest('metal_mine');
        $this->switchToFirstPlanet();

        // While the build is running, the menu shows exactly one wrench (the
        // active planet itself has no queue).
        $overview = $this->get('/overview');
        $overview->assertStatus(200);
        $this->assertSame(1, $this->constructionIconCount((string) $overview->getContent()));

        // Let the build finish without ever touching the second planet again.
        $this->travel(2)->minutes();

        // The item is finished but still unprocessed (lazy queue processing
        // only touched the current planet), and the menu must no longer show
        // a wrench for the second planet.
        $overview = $this->get('/overview');
        $overview->assertStatus(200);
        $this->assertSame(0, $this->constructionIconCount((string) $overview->getContent()));
        $this->assertSame(0, (int) DB::table('building_queues')->where('planet_id', $secondPlanetId)->where('processed', 1)->count());
        $this->assertSame(0, (int) DB::table('planets')->where('id', $secondPlanetId)->value('metal_mine'));

        // Opening the second planet processes its queue: the build lands and
        // the icon stays gone.
        $overview = $this->get('/overview?cp=' . $secondPlanetId);
        $overview->assertStatus(200);
        $this->assertSame(0, $this->constructionIconCount((string) $overview->getContent()));
        $this->assertSame(1, (int) DB::table('building_queues')->where('planet_id', $secondPlanetId)->where('processed', 1)->count());
        $this->assertSame(1, (int) DB::table('planets')->where('id', $secondPlanetId)->value('metal_mine'));
    }

    /**
     * With several items queued, the wrench must stay lit while any part of
     * the queue is still pending, including the moment when the head item has
     * virtually finished but the follow-up item has not been started yet.
     */
    public function testConstructionIconStaysLitWhileQueueHasPendingItems(): void
    {
        $settingsService = resolve(SettingsService::class);
        $settingsService->set('economy_speed', 8);

        $this->assertNotNull($this->secondPlanetService);
        $secondPlanetId = $this->secondPlanetService->getPlanetId();

        $this->switchToSecondPlanet();
        $this->addResourceBuildRequest('metal_mine');
        $this->addResourceBuildRequest('metal_mine');
        $this->switchToFirstPlanet();

        // Travel past the first item's virtual completion. The second item
        // is still queued (lazy processing has not started its timer yet),
        // so the planet is still building and the wrench stays lit.
        $this->travel(1)->minutes();

        $overview = $this->get('/overview');
        $overview->assertStatus(200);
        $this->assertSame(1, $this->constructionIconCount((string) $overview->getContent()));

        // Opening the second planet materializes the whole queue: both items
        // finished by now, so the metal mine lands at level 2 and the wrench
        // disappears.
        $overview = $this->get('/overview?cp=' . $secondPlanetId);
        $overview->assertStatus(200);
        $this->assertSame(0, $this->constructionIconCount((string) $overview->getContent()));
        $this->assertSame(2, (int) DB::table('planets')->where('id', $secondPlanetId)->value('metal_mine'));
    }
}
