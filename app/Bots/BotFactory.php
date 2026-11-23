<?php

namespace OGame\Bots;

use Illuminate\Support\Str;
use OGame\Actions\Fortify\CreateNewUser;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Bot;
use OGame\Services\CharacterClassService;

/**
 * Creates one fully initialized bot account: registration pipeline, "bot"
 * role, behavior class (= in-game character class), personality and human
 * looking planet names. Shared by the manual bot:create command and the
 * population scheduler's top-up pass so both spawn identical accounts.
 */
class BotFactory
{
    public function __construct(
        private CreateNewUser $createNewUser,
        private BotRegistry $botRegistry,
        private PlanetNameGenerator $planetNameGenerator,
        private PlayerServiceFactory $playerServiceFactory,
        private CharacterClassService $characterClassService,
    ) {
    }

    /**
     * Create one bot with the requested behavior class, drawing a random
     * class when the request is unknown (the "mixed" option).
     *
     * @param string $requestedProfile
     * @return Bot
     */
    public function create(string $requestedProfile): Bot
    {
        $email = sprintf('bot-%s@bots.spaceshelf.local', Str::lower(Str::random(10)));
        $password = Str::random(32);

        // Same pipeline as the web registration form.
        $user = $this->createNewUser->create([
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ]);

        $user->assignRole('bot');

        // Behavior class and character class are one and the same: the
        // free first selection grants the bot its class bonuses (and the
        // Crawler/Reaper/Pathfinder hull later on).
        $profile = $this->resolveProfile($requestedProfile);
        $characterClass = PersonalityFactory::CLASS_MAP[$profile];
        $this->characterClassService->selectClass($user, $characterClass);

        $personality = PersonalityFactory::make($profile);

        $bot = Bot::create([
            'user_id' => $user->id,
            'profile' => $personality->profile,
            'personality' => $personality->toArray(),
            'enabled' => true,
            'next_action_at' => now()->addMinutes($this->botRegistry->firstActionDelayMinutes()),
        ]);

        // The engine names fresh-account planets "Homeworld"; give the bot
        // account human-looking planet names right away.
        $this->planetNameGenerator->nameAll($this->playerServiceFactory->make($user->id, true));

        return $bot;
    }

    /**
     * Resolve the behavior class for one bot: the requested one when it is a
     * known class, otherwise a random draw across all classes.
     */
    private function resolveProfile(string $requested): string
    {
        if (array_key_exists($requested, PersonalityFactory::CLASS_MAP)) {
            return $requested;
        }

        $profiles = array_keys(PersonalityFactory::CLASS_MAP);

        return $profiles[array_rand($profiles)];
    }
}
