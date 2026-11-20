<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\DirectiveActivityMarker;
use OGame\Models\DirectiveProgress;
use OGame\Models\User;
use OGame\Services\AllianceService;
use OGame\Services\BuddyService;
use OGame\Services\DirectiveService;
use OGame\Services\PlayerService;
use Tests\IsolatedAccountTestCase;

/**
 * Tests the Directives (quest system): overlay opening as the trigger for the
 * "Welcome Cadet!" meta mission, the condition engine boundaries, reward claiming
 * onto the active planet (resources) and the player account (Dark Matter), the
 * buddy/rename activity hooks and the "Claim All" flow including the exactly-once
 * chapter bonus.
 */
class DirectivesTest extends IsolatedAccountTestCase
{
    /**
     * New players start at computer technology level 0. The shared harness grants
     * level 5 by default (fleet-slot realism for fleet tests); with that default
     * the Directive III "Computer Technology" mission would auto-complete on the
     * first page view, which would break the fresh-account badge expectations.
     */
    protected int $defaultComputerTechnologyLevel = 0;

    /**
     * Fetch the Directives overlay for the given chapter.
     */
    private function overlay(string $chapter = ''): TestResponse
    {
        $uri = '/ajax/directives/overlay';
        if ($chapter !== '') {
            $uri .= '?chapter=' . $chapter;
        }

        return $this->get($uri);
    }

    /**
     * Claim a single mission (or chapter bonus when $mission is empty).
     */
    private function claim(string $chapterId, string $missionId = ''): TestResponse
    {
        return $this->post('/ajax/directives/claim', [
            'directive' => $chapterId,
            'mission' => $missionId,
        ]);
    }

    /**
     * "Claim All" for one chapter.
     */
    private function claimAll(string $chapterId): TestResponse
    {
        return $this->post('/ajax/directives/claim-all', ['directive' => $chapterId]);
    }

    /**
     * Resolve the directive service for direct (non-HTTP) calls.
     */
    private function directiveService(): DirectiveService
    {
        return resolve(DirectiveService::class);
    }

    /**
     * Current player service. Built through the factory so the helper works in
     * tests that have not made an HTTP request yet (which is when the middleware
     * binds the request singleton).
     */
    private function player(): PlayerService
    {
        return resolve(PlayerServiceFactory::class)->make($this->currentUserId);
    }

    /**
     * Current Dark Matter balance straight from the database.
     *
     * @phpstan-impure Reads freshly from the database on every call.
     */
    private function darkMatter(): int
    {
        return (int) User::where('id', $this->currentUserId)->value('dark_matter');
    }

    /**
     * Opening the overlay records the visit, completes the "Welcome Cadet!" meta
     * mission within the same request and its reward can be claimed exactly once
     * for 500 Dark Matter.
     */
    public function testOpeningTheOverlayCompletesWelcomeCadetAndGrantsDarkMatter(): void
    {
        $response = $this->overlay();
        $response->assertStatus(200);
        $response->assertSee('Welcome Cadet!');
        $response->assertSee('Open the mission overview');
        $response->assertSee('500');

        $this->assertDatabaseHas('directive_activity_markers', [
            'user_id' => $this->currentUserId,
            'activity' => 'directives_opened',
        ]);

        // The meta mission completes the moment the overlay opens, so it is already
        // claimable in this very response.
        $response->assertSee('data-state="completed"', false);

        $dmBefore = $this->darkMatter();

        $claim = $this->claim('directive_i', 'i_welcome_cadet');
        $claim->assertStatus(200);
        $claim->assertJson(['success' => true]);
        $this->assertSame($dmBefore + 500, $this->darkMatter());

        // A second claim of the same reward must be rejected and grant nothing.
        $second = $this->claim('directive_i', 'i_welcome_cadet');
        $second->assertStatus(400);
        $second->assertJson(['success' => false]);
        $this->assertSame($dmBefore + 500, $this->darkMatter());

        $this->assertDatabaseHas('directive_progress', [
            'user_id' => $this->currentUserId,
            'directive_id' => 'directive_i',
            'mission_id' => 'i_welcome_cadet',
            'status' => 'claimed',
        ]);
    }

    /**
     * Claims of unknown or not-yet-completed missions are rejected.
     */
    public function testClaimRejectsUnknownAndUncompletedMissions(): void
    {
        $unknown = $this->claim('no_such_directive', 'no_such_mission');
        $unknown->assertStatus(400);
        $unknown->assertJson(['success' => false]);

        // No conditions met yet, so even a valid mission is not claimable.
        $notCompleted = $this->claim('directive_ii', 'ii_shipyard');
        $notCompleted->assertStatus(400);
        $notCompleted->assertJson(['success' => false]);

        $this->assertDatabaseMissing('directive_progress', [
            'user_id' => $this->currentUserId,
            'directive_id' => 'no_such_directive',
        ]);
    }

    /**
     * A mission completed once stays completed and claimable even when live game
     * state regresses afterwards (e.g. the building is demolished, the units that
     * satisfied the condition sailed away with a fleet). Vanilla quest completion
     * is sticky; the progress display must not fall back to "0 / 1" with an
     * enabled Collect button, as that looks like a claimable but unmet mission.
     */
    public function testCompletedMissionStaysClaimableAfterConditionRegresses(): void
    {
        $this->planetSetObjectLevel('shipyard', 1);
        $this->overlay('directive_ii')->assertSee('data-state="completed"', false);

        $this->planetSetObjectLevel('shipyard', 0);

        $overlay = $this->overlay('directive_ii');
        $overlay->assertSee('data-state="completed"', false);
        // Exactly one satisfied progress row (the shipyard mission), rendered
        // with the target reached instead of the regressed 0 / 1.
        $this->assertSame(1, substr_count((string) $overlay->getContent(), '1 / 1'));

        $metalBefore = (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('metal');
        $claim = $this->claim('directive_ii', 'ii_shipyard');
        $claim->assertStatus(200);
        $claim->assertJson(['success' => true]);
        $this->assertSame($metalBefore + 1000, (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('metal'));
    }

    /**
     * Condition boundaries: one level/unit/marker below the target does not
     * complete, hitting the target does.
     */
    public function testConditionBoundaries(): void
    {
        $service = $this->directiveService();
        $player = $this->player();

        $this->assertSame([0, 1], $service->conditionProgress([
            'type' => 'building_level', 'object' => 'shipyard', 'target' => 1,
        ], $player));
        $this->planetSetObjectLevel('shipyard', 1);
        $this->assertSame([1, 1], $service->conditionProgress([
            'type' => 'building_level', 'object' => 'shipyard', 'target' => 1,
        ], $player));

        $this->assertSame([0, 1], $service->conditionProgress([
            'type' => 'research_level', 'object' => 'energy_technology', 'target' => 1,
        ], $player));
        $this->playerSetResearchLevel('energy_technology', 1);
        $this->assertSame([1, 1], $service->conditionProgress([
            'type' => 'research_level', 'object' => 'energy_technology', 'target' => 1,
        ], $player));

        $this->assertSame([0, 1], $service->conditionProgress([
            'type' => 'unit_count', 'objects' => ['small_cargo'], 'target' => 1, 'scope' => 'any',
        ], $player));
        $this->planetAddUnit('small_cargo', 1);
        $this->assertSame([1, 1], $service->conditionProgress([
            'type' => 'unit_count', 'objects' => ['small_cargo'], 'target' => 1, 'scope' => 'any',
        ], $player));

        $this->assertSame([0, 1], $service->conditionProgress(['type' => 'alliance'], $player));
        resolve(AllianceService::class)->createAlliance($this->currentUserId, 'DIR', 'Directive Test Alliance');
        // AllianceService updates a freshly loaded User model; refresh the player's
        // copy so the condition reads live state (in production every evaluation
        // pass runs against a freshly loaded player via the middleware).
        $player->getUser()->refresh();
        $this->assertSame([1, 1], $service->conditionProgress(['type' => 'alliance'], $player));

        $this->assertSame([0, 1], $service->conditionProgress([
            'type' => 'activity', 'key' => DirectiveService::ACTIVITY_BUDDY_REQUEST_SENT,
        ], $player));
        $service->recordActivity($this->currentUserId, DirectiveService::ACTIVITY_BUDDY_REQUEST_SENT);
        $this->assertSame([1, 1], $service->conditionProgress([
            'type' => 'activity', 'key' => DirectiveService::ACTIVITY_BUDDY_REQUEST_SENT,
        ], $player));
    }

    /**
     * A completed building mission's reward lands on the planet that is active at
     * claim time (1,000 metal + 500 crystal for the Shipyard mission).
     */
    public function testBuildingMissionRewardLandsOnTheActivePlanet(): void
    {
        $this->planetSetObjectLevel('shipyard', 1);

        $overlay = $this->overlay('directive_ii');
        $overlay->assertStatus(200);
        $overlay->assertSee('Shipyard');
        $overlay->assertSee('data-state="completed"', false);

        $metalBefore = (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('metal');
        $crystalBefore = (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('crystal');

        $claim = $this->claim('directive_ii', 'ii_shipyard');
        $claim->assertStatus(200);
        $claim->assertJson(['success' => true]);

        $metalAfter = (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('metal');
        $crystalAfter = (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('crystal');

        $this->assertSame(1000, $metalAfter - $metalBefore);
        $this->assertSame(500, $crystalAfter - $crystalBefore);
    }

    /**
     * The Directive I resource-collection missions complete once their
     * buildings/units exist and pay out on claim. Solar satellites are tracked
     * as a unit count and only complete at their target amount.
     */
    public function testDirectiveIResourceCollectionMissions(): void
    {
        $this->planetSetObjectLevel('metal_mine', 1);

        $overlay = $this->overlay('directive_i');
        $overlay->assertStatus(200);
        $overlay->assertSee('Metal Mine');
        $overlay->assertSee('data-state="completed"', false);

        $metalBefore = (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('metal');

        $claim = $this->claim('directive_i', 'i_metal_mine');
        $claim->assertStatus(200);
        $claim->assertJson(['success' => true]);
        $this->assertSame(400, (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('metal') - $metalBefore);

        $this->assertDatabaseHas('directive_progress', [
            'user_id' => $this->currentUserId,
            'directive_id' => 'directive_i',
            'mission_id' => 'i_metal_mine',
            'status' => 'claimed',
        ]);

        // Solar satellites need 3 to complete: two are not enough.
        $this->planetAddUnit('solar_satellite', 2);
        $this->overlay('directive_i');
        $notEnough = $this->claim('directive_i', 'i_solar_satellites');
        $notEnough->assertStatus(400);
        $notEnough->assertJson(['success' => false]);

        $this->planetAddUnit('solar_satellite', 1);
        $this->overlay('directive_i');

        $metalBefore = (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('metal');
        $claim = $this->claim('directive_i', 'i_solar_satellites');
        $claim->assertStatus(200);
        $claim->assertJson(['success' => true]);
        $this->assertSame(800, (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('metal') - $metalBefore);
    }

    /**
     * Directive III is the science chapter: research_level missions track each
     * technology individually and only their own research completes them; the
     * chapter bonus unlocks exactly once after all fourteen missions have been
     * claimed.
     */
    public function testDirectiveIIIScienceChapter(): void
    {
        // A tech that Directive II already covers (and Directive III therefore
        // skips) must not complete or unlock anything in this chapter.
        $this->playerSetResearchLevel('energy_technology', 1);
        $this->overlay('directive_iii');
        $this->claim('directive_iii', 'iii_laser_technology')->assertStatus(400);
        $this->claim('directive_iii', '')->assertStatus(400);

        $this->playerSetResearchLevel('computer_technology', 1);

        $overlay = $this->overlay('directive_iii');
        $overlay->assertStatus(200);
        $overlay->assertSee('Computer Technology');
        $overlay->assertSee('data-state="completed"', false);

        $metalBefore = (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('metal');
        $crystalBefore = (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('crystal');

        $claim = $this->claim('directive_iii', 'iii_computer_technology');
        $claim->assertStatus(200);
        $claim->assertJson(['success' => true]);
        $this->assertSame(600, (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('metal') - $metalBefore);
        $this->assertSame(600, (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('crystal') - $crystalBefore);

        // Research every science tech and let "Claim All" sweep the remaining
        // missions, then the chapter bonus exactly once.
        /** @var array<string, array<string, mixed>> $missions */
        $missions = config('directives.chapters.directive_iii.missions');
        foreach ($missions as $mission) {
            $this->playerSetResearchLevel((string) $mission['condition']['object'], 1);
        }

        $metalBefore = (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('metal');
        $crystalBefore = (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('crystal');
        $deuteriumBefore = (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('deuterium');
        $dmBefore = $this->darkMatter();

        $first = $this->claimAll('directive_iii');
        $first->assertStatus(200);
        $first->assertJson(['success' => true, 'granted' => 13]);

        $second = $this->claimAll('directive_iii');
        $second->assertStatus(200);
        $second->assertJson(['success' => true, 'granted' => 1]);

        $this->assertDatabaseHas('directive_progress', [
            'user_id' => $this->currentUserId,
            'directive_id' => 'directive_iii',
            'mission_id' => '',
            'status' => 'claimed',
        ]);

        // Remaining 13 missions: 11,200 metal / 9,500 crystal / 2,400 deuterium /
        // 2,100 Dark Matter, plus the best-effort chapter bonus of 3,000 metal /
        // 1,500 crystal / 750 deuterium / 3,000 Dark Matter.
        $this->assertSame(11200 + 3000, (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('metal') - $metalBefore);
        $this->assertSame(9500 + 1500, (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('crystal') - $crystalBefore);
        $this->assertSame(2400 + 750, (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('deuterium') - $deuteriumBefore);
        $this->assertSame($dmBefore + 5100, $this->darkMatter());
    }

    /**
     * Sending a buddy request marks the activity, which completes the mission and
     * allows claiming its Dark Matter reward.
     */
    public function testBuddyRequestHookCompletesMission(): void
    {
        $otherUser = User::factory()->create();
        resolve(BuddyService::class)->sendRequest($this->currentUserId, $otherUser->id, 'hello');

        $this->assertDatabaseHas('directive_activity_markers', [
            'user_id' => $this->currentUserId,
            'activity' => 'buddy_request_sent',
        ]);

        $overlay = $this->overlay('directive_ii');
        $overlay->assertSee('Send a buddy request');
        $overlay->assertSee('data-state="completed"', false);

        $dmBefore = $this->darkMatter();
        $claim = $this->claim('directive_ii', 'ii_buddy_request');
        $claim->assertStatus(200);
        $claim->assertJson(['success' => true]);
        $this->assertSame($dmBefore + 200, $this->darkMatter());
    }

    /**
     * Renaming the current planet marks the activity, which completes the rename
     * mission and allows claiming its reward.
     */
    public function testPlanetRenameHookCompletesMission(): void
    {
        $rename = $this->post('/ajax/planet-abandon/rename', ['newPlanetName' => 'Renamed']);
        $rename->assertStatus(200);
        $rename->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('directive_activity_markers', [
            'user_id' => $this->currentUserId,
            'activity' => 'planet_renamed',
        ]);

        // Opening the overlay forces a condition evaluation so the mission is
        // completed before claiming.
        $this->overlay('directive_ii');

        $dmBefore = $this->darkMatter();
        $claim = $this->claim('directive_ii', 'ii_rename_planet');
        $claim->assertStatus(200);
        $claim->assertJson(['success' => true]);
        $this->assertSame($dmBefore + 100, $this->darkMatter());
    }

    /**
     * "Claim All" grants every completed mission of the chapter, and the chapter
     * bonus (1,500 metal / 750 crystal / 250 deuterium / 2,000 Dark Matter) becomes
     * claimable exactly once after all missions have been claimed.
     */
    public function testClaimAllGrantsMissionsThenTheChapterBonusExactlyOnce(): void
    {
        $this->planetAddUnit('colony_ship', 1);
        $this->planetSetObjectLevel('research_lab', 1);
        $this->playerSetResearchLevel('energy_technology', 1);
        $this->playerSetResearchLevel('combustion_drive', 1);
        $this->planetSetObjectLevel('robot_factory', 1);
        $this->planetSetObjectLevel('shipyard', 1);
        $this->planetAddUnit('small_cargo', 1);
        resolve(AllianceService::class)->createAlliance($this->currentUserId, 'DIR', 'Directive Test Alliance');
        $this->post('/ajax/planet-abandon/rename', ['newPlanetName' => 'Renamed'])->assertStatus(200);
        resolve(BuddyService::class)->sendRequest($this->currentUserId, User::factory()->create()->id, 'hi');

        $metalBefore = (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('metal');
        $crystalBefore = (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('crystal');
        $deuteriumBefore = (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('deuterium');
        $dmBefore = $this->darkMatter();

        // First pass: all ten missions are completed by the claim-all evaluation
        // and claimed in one go.
        $first = $this->claimAll('directive_ii');
        $first->assertStatus(200);
        $first->assertJson(['success' => true, 'granted' => 10]);

        // Second pass: only the chapter bonus is left.
        $second = $this->claimAll('directive_ii');
        $second->assertStatus(200);
        $second->assertJson(['success' => true, 'granted' => 1]);

        // Third pass: nothing is left to claim.
        $third = $this->claimAll('directive_ii');
        $third->assertStatus(400);
        $third->assertJson(['success' => false]);

        $metalAfter = (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('metal');
        $crystalAfter = (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('crystal');

        // Mission rewards: 1000+500+750+500+1000+1000+1500 metal, 500+250+500+500 crystal
        // plus the verified chapter bonus of 1,500 metal / 750 crystal / 250 deuterium.
        $this->assertSame(6250 + 1500, $metalAfter - $metalBefore);
        $this->assertSame(1750 + 750, $crystalAfter - $crystalBefore);
        $this->assertSame($deuteriumBefore + 250, (int) DB::table('planets')->where('id', $this->currentPlanetId)->value('deuterium'));

        // Mission Dark Matter (100 + 100 + 200) plus the 2,000 chapter bonus,
        // relative to the balance the test started with (new users get an initial
        // Dark Matter bonus from the UserObserver).
        $this->assertSame($dmBefore + 2400, $this->darkMatter());

        $this->assertSame(
            11,
            DirectiveProgress::where('user_id', $this->currentUserId)
                ->where('directive_id', 'directive_ii')
                ->where('status', 'claimed')
                ->count()
        );
    }

    /**
     * The left-menu panel shows the first pending mission as current mission and
     * the collect badge once something is claimable.
     */
    public function testPanelShowsCurrentMissionAndCollectBadge(): void
    {
        $overview = $this->get('/overview');
        $overview->assertStatus(200);
        $overview->assertSee('Welcome Cadet!');
        $overview->assertDontSee('ipiHintCollect', false);

        $this->overlay();

        $overview = $this->get('/overview');
        $overview->assertSee('ipiHintCollect', false);
        // With the welcome mission done (but unclaimed), the next mission in
        // Directive I is shown.
        $overview->assertSee('Metal Mine');

        $this->claim('directive_i', 'i_welcome_cadet')->assertStatus(200);

        $overview = $this->get('/overview');
        $overview->assertDontSee('ipiHintCollect', false);
    }

    /**
     * The "Open the mission overview" completion does not leak to other users.
     */
    public function testProgressIsPerUser(): void
    {
        $this->overlay();
        $this->claim('directive_i', 'i_welcome_cadet');

        $otherUser = User::factory()->create();
        $this->assertSame(
            0,
            DirectiveActivityMarker::where('user_id', $otherUser->id)->count()
        );
        $this->assertSame(
            0,
            DirectiveProgress::where('user_id', $otherUser->id)->count()
        );
    }
}
