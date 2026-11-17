<?php

namespace OGame\Console\Commands\Bots;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use OGame\Actions\Fortify\CreateNewUser;
use OGame\Bots\BotRegistry;
use OGame\Bots\PersonalityFactory;
use OGame\Bots\PlanetNameGenerator;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Bot;
use OGame\Models\User;

/**
 * Creates new autonomous bot players. Each bot goes through the exact same
 * account creation pipeline as a human registration (CreateNewUser → initial
 * planet, tech and starting resources), then gets the "bot" role plus a
 * personality for the scheduler and brain.
 */
#[Description('Create autonomous bot players')]
#[Signature('spaceshelf:bot:create {--count=1 : Number of bots to create} {--profile=mixed : Personality profile or "mixed"}')]
class CreateBots extends Command
{
    /**
     * Execute the console command.
     *
     * @param CreateNewUser $createNewUser
     * @param BotRegistry $botRegistry
     * @return int
     */
    public function handle(
        CreateNewUser $createNewUser,
        BotRegistry $botRegistry,
        PlanetNameGenerator $planetNameGenerator,
        PlayerServiceFactory $playerServiceFactory,
    ): int {
        $count = (int) $this->option('count');
        if ($count < 1) {
            $this->error('Option --count must be at least 1.');
            return self::FAILURE;
        }

        // The User model auto-assigns the admin role to the first non-Legor
        // user. Creating bots before a human exists would hand admin to an NPC.
        if (!User::where('username', '!=', 'Legor')->exists()) {
            $this->error('No human account found. Register at least one account before creating bots.');
            return self::FAILURE;
        }

        /** @var string $profile */
        $profile = (string) $this->option('profile');
        $created = [];

        for ($i = 0; $i < $count; $i++) {
            $email = sprintf('bot-%s@bots.spaceshelf.local', Str::lower(Str::random(10)));
            $password = Str::random(32);

            // Same pipeline as the web registration form.
            $user = $createNewUser->create([
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password,
            ]);

            $user->assignRole('bot');

            $personality = PersonalityFactory::make($profile === 'mixed' ? null : $profile);

            Bot::create([
                'user_id' => $user->id,
                'profile' => $personality->profile,
                'personality' => $personality->toArray(),
                'enabled' => true,
                'next_action_at' => now()->addMinutes($botRegistry->firstActionDelayMinutes()),
            ]);

            // The engine names fresh-account planets "Homeworld"; give the bot
            // account human-looking planet names right away.
            $planetNameGenerator->nameAll($playerServiceFactory->make($user->id, true));

            $created[] = [
                'username' => $user->username,
                'profile' => $personality->profile,
            ];
        }

        $botRegistry->forgetBotUserIdsCache();

        $this->table(['Username', 'Profile'], array_map(
            fn (array $row): array => [$row['username'], $row['profile']],
            $created
        ));

        $this->info(sprintf('Created %d bot(s). They will start playing on the next scheduler tick.', count($created)));

        return self::SUCCESS;
    }
}
