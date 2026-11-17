<?php

namespace Tests\Feature\Bots;

use Illuminate\Support\Facades\Artisan;
use OGame\Bots\PlanetNameGenerator;
use OGame\Models\Bot;
use OGame\Models\Planet;
use Tests\IsolatedAccountTestCase;

/**
 * Tests for the spaceshelf:bot:create command: bots must come out as real
 * game accounts (planets, tech) tagged with the "bot" role, plus scheduling
 * rows the heartbeat can pick up.
 */
class BotCreationTest extends IsolatedAccountTestCase
{
    /**
     * Assert that creating bots produces bot users with the bot role and a
     * home planet, and that the human account that made it possible stays a
     * non-bot.
     */
    public function testCreateBotsCreatesTaggedAccounts(): void
    {
        $this->assertSame(0, Artisan::call('spaceshelf:bot:create', ['--count' => 3]));

        $this->assertSame(3, Bot::count());

        $bots = Bot::all();

        foreach ($bots as $bot) {
            $user = $bot->user;

            $this->assertNotNull($user);
            $this->assertTrue($user->hasRole('bot'));
            $this->assertFalse($user->hasRole('admin'));

            // Full game account: the normal registration pipeline must have
            // placed a home planet for the bot.
            $planetNames = Planet::where('user_id', $user->id)->pluck('name')->all();
            $this->assertNotEmpty($planetNames);

            // Bot planets must not keep the engine's tell-tale defaults and
            // must satisfy the engine's own planet-name validation rules.
            foreach ($planetNames as $planetName) {
                $this->assertNotContains($planetName, ['Homeworld', 'Colony']);
                $this->assertMatchesRegularExpression('/^[a-zA-Z0-9-_ ]{2,20}$/', $planetName);
            }

            $this->assertArrayHasKey($bot->profile, config('bots.personalities'));
            $this->assertNotNull($bot->next_action_at);
            $this->assertTrue($bot->enabled);
        }

        $personality = $bots->firstOrFail()->personality;
        $this->assertArrayHasKey('aggression', $personality);
        $this->assertArrayHasKey('colonisation_bias', $personality);
        $this->assertArrayHasKey('military_share', $personality);
        $this->assertArrayHasKey('research_drive', $personality);

        // The authenticated (human) test user must not have picked up the bot role.
        $human = auth()->user();
        $this->assertNotNull($human);
        $this->assertFalse($human->hasRole('bot'));
    }

    /**
     * Assert that the bot profile option produces bots that all use the given
     * personality profile instead of a mixed draw.
     */
    public function testCreateBotsRespectsProfileOption(): void
    {
        $this->assertSame(0, Artisan::call('spaceshelf:bot:create', ['--count' => 2, '--profile' => 'warrior']));

        $profiles = Bot::pluck('profile')->all();

        $this->assertSame(['warrior', 'warrior'], $profiles);
    }

    /**
     * Assert that the planet name generator only ever produces names that pass
     * the engine's planet name validation and are never an engine default.
     */
    public function testPlanetNameGeneratorProducesValidNames(): void
    {
        $generator = resolve(PlanetNameGenerator::class);

        $names = [];
        for ($i = 0; $i < 200; $i++) {
            $name = $generator->generate();

            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9-_ ]{2,20}$/', $name);
            $this->assertNotContains($name, ['Homeworld', 'Colony']);

            $names[] = $name;
        }

        // With such a large pool of candidates the sample should be free of
        // duplicates (the generator avoids collisions with existing planets).
        $this->assertGreaterThan(150, count(array_unique($names)));
    }
}
