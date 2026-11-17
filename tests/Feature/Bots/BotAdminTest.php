<?php

namespace Tests\Feature\Bots;

use Illuminate\Support\Facades\Artisan;
use OGame\Enums\CharacterClass;
use OGame\Enums\HighscoreTypeEnum;
use OGame\Models\Bot;
use OGame\Services\HighscoreService;
use OGame\Services\SettingsService;
use Tests\IsolatedAccountTestCase;

/**
 * Phase 6 admin surface (docs/BOT-AI-PLAN.md §8): the Bots page must govern
 * the bots without artisan — pause/resume, profile switches with character
 * class sync, and the per-bot highscore opt-out.
 */
class BotAdminTest extends IsolatedAccountTestCase
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
     * Ids of the players currently on the general highscore leaderboard.
     *
     * @return array<int>
     */
    private function leaderboardIds(): array
    {
        $service = resolve(HighscoreService::class);
        $service->setHighscoreType(HighscoreTypeEnum::general->value);

        return array_column($service->getHighscorePlayers(100, 1), 'id');
    }

    /**
     * Grant the acting user the admin role; the Bots page is admin only.
     */
    private function makeAdmin(): void
    {
        $authUser = auth()->user();
        $this->assertNotNull($authUser);
        $this->artisan('spaceshelf:admin:assign-role', ['username' => $authUser->username]);
    }

    public function testNonAdminCannotSeeBotsPage(): void
    {
        $this->createBot('discoverer');

        $authUser = auth()->user();
        $this->assertNotNull($authUser);
        $this->artisan('spaceshelf:admin:remove-role', ['username' => $authUser->username]);

        $this->get('/admin/bots')->assertRedirect('/overview');
    }

    public function testAdminSeesBotsPageWithBot(): void
    {
        $bot = $this->createBot('discoverer');
        $this->makeAdmin();

        $response = $this->get('/admin/bots');
        $response->assertStatus(200);
        $response->assertSee($bot->user->username);
    }

    public function testPauseToggleResumesAndPausesBot(): void
    {
        $bot = $this->createBot('collector');
        $this->makeAdmin();
        $this->assertTrue($bot->enabled);

        $this->post(route('admin.bots.enabled', $bot))
            ->assertRedirect(route('admin.bots.index'));
        $this->assertFalse($bot->refresh()->enabled);

        $this->post(route('admin.bots.enabled', $bot))
            ->assertRedirect(route('admin.bots.index'));
        $this->assertTrue($bot->refresh()->enabled);
    }

    public function testProfileSwitchRebuildsPersonalityAndSyncsClass(): void
    {
        $bot = $this->createBot('collector');
        $this->assertSame(CharacterClass::COLLECTOR->value, $bot->user->character_class);
        $this->makeAdmin();

        // Class re-selection is a paid player decision; the admin page rides
        // on the developer shortcut's free-class-changes setting for that.
        resolve(SettingsService::class)->set('dev_free_class_changes', 1);

        $this->post(route('admin.bots.profile', $bot), ['profile' => 'general'])
            ->assertRedirect(route('admin.bots.index'));

        $bot->refresh();
        $this->assertSame('general', $bot->profile);
        $this->assertSame(CharacterClass::GENERAL->value, $bot->refresh()->user->character_class);
        $this->assertSame('general', $bot->personalityObject()->profile);
    }

    public function testProfileSwitchRejectsUnknownProfile(): void
    {
        $bot = $this->createBot('general');
        $this->makeAdmin();

        $this->post(route('admin.bots.profile', $bot), ['profile' => 'space_wizard'])
            ->assertSessionHasErrors('profile');

        $this->assertSame('general', $bot->refresh()->profile);
    }

    public function testHighscoreOptOutHidesBotFromLeaderboard(): void
    {
        $bot = $this->createBot('general');
        $this->makeAdmin();
        $this->assertSame(0, Artisan::call('spaceshelf:scheduler:generate-highscore-ranks'));

        $this->assertContains($bot->user_id, $this->leaderboardIds());

        $this->post(route('admin.bots.highscore', $bot))
            ->assertRedirect(route('admin.bots.index'));
        $this->assertTrue($bot->refresh()->hide_from_highscore);

        $this->assertNotContains($bot->user_id, $this->leaderboardIds());
    }
}
