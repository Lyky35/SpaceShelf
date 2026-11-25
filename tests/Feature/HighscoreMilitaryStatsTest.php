<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use OGame\Models\Highscore;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;

/**
 * Tests the military highscore sub-categories (vanilla types 4 lost, 5 built,
 * 6 destroyed, 7 honor): every type must render via the highscore AJAX
 * endpoint, and the "military built" counter must follow the shipyard and
 * defense construction queues with the official score weights (civil ships
 * count at 50%).
 */
class HighscoreMilitaryStatsTest extends IsolatedAccountTestCase
{
    private function gameSetup(): void
    {
        $settingsService = resolve(SettingsService::class);
        $settingsService->set('economy_speed', 8);

        $this->planetSetObjectLevel('robot_factory', 2);
        $this->planetSetObjectLevel('shipyard', 2);
        $this->playerSetResearchLevel('energy_technology', 1);
        $this->playerSetResearchLevel('combustion_drive', 2);
    }

    /**
     * All vanilla highscore type IDs (0-7) plus an unknown one must return a
     * renderable list instead of erroring (previously the military
     * sub-navigation sent types 4-7 which the backend never implemented).
     */
    public function testHighscoreAjaxServesAllVanillaTypes(): void
    {
        Highscore::updateOrCreate(['player_id' => $this->currentUserId], [
            'general' => 100,
            'general_rank' => 1,
            'military_lost_rank' => 1,
            'military_built_rank' => 1,
            'military_destroyed_rank' => 1,
            'honor_rank' => 1,
        ]);

        foreach ([0, 1, 2, 3, 4, 5, 6, 7] as $type) {
            $response = $this->post('/ajax/highscore', ['category' => '1', 'type' => (string) $type]);
            $response->assertStatus(200);
        }

        // Unknown types fall back to the general list instead of a 500.
        $response = $this->post('/ajax/highscore', ['category' => '1', 'type' => '99']);
        $response->assertStatus(200);
    }

    /**
     * Building ships and defense through the queues credits the cumulative
     * "military built" counter on the user, with civil ships at 50%.
     */
    public function testMilitaryBuiltCounterTracksConstructionQueues(): void
    {
        $this->gameSetup();

        $this->planetAddResources(new Resources(500000, 500000, 500000, 0));

        $this->addShipyardBuildRequest('light_fighter', 10);
        $this->addShipyardBuildRequest('small_cargo', 5);
        $this->addDefenseBuildRequest('rocket_launcher', 10);

        // Let the whole queue finish and trigger processing via a page load.
        $this->travel(2)->hours();
        $this->get('/shipyard')->assertStatus(200);

        $lightFighters = (int) DB::table('planets')->where('id', $this->planetService->getPlanetId())->value('light_fighter');
        $this->assertSame(10, $lightFighters, 'Light fighters should have been awarded');

        $expected = (int) floor(ObjectService::getObjectRawPrice('light_fighter')->sum() * 10 / 1000)
            + (int) floor(ObjectService::getObjectRawPrice('small_cargo')->sum() * 0.5 * 5 / 1000)
            + (int) floor(ObjectService::getObjectRawPrice('rocket_launcher')->sum() * 10 / 1000);

        $built = (int) DB::table('users')->where('id', $this->currentUserId)->value('military_built');
        $this->assertSame($expected, $built);
    }
}
