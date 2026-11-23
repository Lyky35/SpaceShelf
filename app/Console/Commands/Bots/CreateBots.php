<?php

namespace OGame\Console\Commands\Bots;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use OGame\Bots\BotFactory;
use OGame\Bots\BotRegistry;
use OGame\Bots\PersonalityFactory;
use OGame\Models\User;

/**
 * Creates new autonomous bot players via the shared BotFactory (the exact
 * same pipeline the population scheduler's top-up pass uses). Each bot goes
 * through real account creation (CreateNewUser → initial planet, tech and
 * starting resources), then gets the "bot" role plus a personality for the
 * scheduler and brain.
 *
 * The bot's behavior class doubles as its in-game character class: Collector,
 * General or Discoverer (see OGame\Enums\CharacterClass). That grants the bots
 * the same class bonuses and class ships as real players and lets the brain
 * play styles that match what the account is entitled to.
 */
#[Description('Create autonomous bot players')]
#[Signature('spaceshelf:bot:create {--count=1 : Number of bots to create} {--profile=mixed : Behavior class (collector|general|discoverer) or "mixed"}')]
class CreateBots extends Command
{
    /**
     * Execute the console command.
     *
     * @param BotFactory $botFactory
     * @param BotRegistry $botRegistry
     * @return int
     */
    public function handle(
        BotFactory $botFactory,
        BotRegistry $botRegistry,
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

        if ($profile !== 'mixed' && !array_key_exists($profile, PersonalityFactory::CLASS_MAP)) {
            $this->warn(sprintf('Unknown behavior class "%s"; drawing random classes instead.', $profile));
        }

        $created = [];

        for ($i = 0; $i < $count; $i++) {
            $bot = $botFactory->create($profile);

            $created[] = [
                'username' => $bot->user->username,
                'class' => $bot->profile,
            ];
        }

        $botRegistry->forgetBotUserIdsCache();

        $this->table(['Username', 'Class'], array_map(
            fn (array $row): array => [$row['username'], $row['class']],
            $created
        ));

        $this->info(sprintf('Created %d bot(s). They will start playing on the next scheduler tick.', count($created)));

        return self::SUCCESS;
    }
}
