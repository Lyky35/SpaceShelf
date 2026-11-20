<?php

namespace Tests\Feature\Bots;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use OGame\Enums\DirectiveProgressStatus;
use OGame\Enums\StarterAidReward;
use OGame\Jobs\BotHeartbeatJob;
use OGame\Models\Bot;
use OGame\Models\StarterAidClaim;
use OGame\Models\User;
use Tests\IsolatedAccountTestCase;

/**
 * Bots claim rewards like active players do on login: unlocked Starter Aid
 * dispatches and completed directives are collected at the start of every
 * heartbeat (UtilityBrain::claimRewards), so the granted resources and Dark
 * Matter are usable during the same visit.
 */
class BotRewardClaimTest extends IsolatedAccountTestCase
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
     * Run exactly one heartbeat for the bot.
     */
    private function heartbeat(Bot $bot): void
    {
        Bus::dispatchSync(new BotHeartbeatJob($bot->id));
    }

    /**
     * Count the Starter Aid claims recorded for the bot's account.
     */
    private function starterAidClaimCount(Bot $bot): int
    {
        return StarterAidClaim::where('user_id', $bot->user_id)->count();
    }

    /**
     * A bot account old enough to have Starter Aid dispatches unlocks them
     * claims them on its next heartbeat, and a second heartbeat does not
     * claim anything a second time.
     */
    public function testUnlockedStarterAidIsClaimedOnce(): void
    {
        $bot = $this->createBot('collector');

        // Backdate the account registration so the first dispatch days (day 2
        // and day 3) are unlocked; the day 4 dispatch is still locked.
        $user = User::findOrFail($bot->user_id);
        $user->created_at = now()->subDays(3);
        $user->save();

        $this->assertSame(0, $this->starterAidClaimCount($bot));

        $this->heartbeat($bot);

        $claimsAfterFirst = $this->starterAidClaimCount($bot);
        $this->assertGreaterThan(0, $claimsAfterFirst);
        $this->assertTrue(
            StarterAidClaim::where('user_id', $bot->user_id)
                ->where('reward', StarterAidReward::Supplies->value)
                ->exists(),
            'The first (day 2) Starter Aid dispatch was not claimed.'
        );

        $this->heartbeat($bot);
        $this->assertSame($claimsAfterFirst, $this->starterAidClaimCount($bot));
    }

    /**
     * A freshly created bot has no unlocked Starter Aid yet and must not
     * record any claim.
     */
    public function testStarterAidNotClaimedBeforeUnlock(): void
    {
        $bot = $this->createBot('collector');

        $this->heartbeat($bot);

        $this->assertSame(0, $this->starterAidClaimCount($bot));
    }

    /**
     * A completed directive mission is claimed by the heartbeat and its
     * reward (500 Dark Matter for the Directive I welcome mission) is
     * credited to the account.
     */
    public function testCompletedDirectiveIsClaimedAndRewarded(): void
    {
        $bot = $this->createBot('collector');

        DB::table('directive_progress')->insert([
            'user_id' => $bot->user_id,
            'directive_id' => 'directive_i',
            'mission_id' => 'i_welcome_cadet',
            'status' => DirectiveProgressStatus::Completed->value,
            'completed_at' => now(),
        ]);

        // Fresh accounts start with the signup Dark Matter bonus; the officer
        // hire floor keeps the brain from spending any of it this heartbeat.
        $darkMatterBefore = (int) User::findOrFail($bot->user_id)->dark_matter;

        $this->heartbeat($bot);

        $row = DB::table('directive_progress')
            ->where('user_id', $bot->user_id)
            ->where('directive_id', 'directive_i')
            ->where('mission_id', 'i_welcome_cadet')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(DirectiveProgressStatus::Claimed->value, $row->status);
        $this->assertSame($darkMatterBefore + 500, (int) User::findOrFail($bot->user_id)->dark_matter);

        // Second heartbeat must not grant the reward twice.
        $this->heartbeat($bot);
        $this->assertSame($darkMatterBefore + 500, (int) User::findOrFail($bot->user_id)->dark_matter);
    }
}
