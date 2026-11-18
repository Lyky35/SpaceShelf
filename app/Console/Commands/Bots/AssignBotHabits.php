<?php

namespace OGame\Console\Commands\Bots;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use OGame\Bots\PersonalityFactory;
use OGame\Models\Bot;

/**
 * Backfills per-bot habit quirks (see PersonalityFactory and the "habits"
 * block in config/bots.php) onto bots that were created before the habit
 * system existed. Idempotent: bots that already have habits are left
 * untouched, so it is safe to schedule or re-run after config changes.
 */
#[Description('Assign habit quirks to bots that do not have any yet')]
#[Signature('spaceshelf:bot:assign-habits')]
class AssignBotHabits extends Command
{
    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $assigned = 0;
        $skipped = 0;
        $rows = [];

        foreach (Bot::with('user')->orderBy('id')->get() as $bot) {
            /** @var array<string, mixed> $personality */
            $personality = (array) $bot->personality;

            if (($personality['habits'] ?? []) !== []) {
                $skipped++;
                continue;
            }

            $personality['habits'] = PersonalityFactory::pickHabits();
            $bot->personality = $personality;
            $bot->save();

            $rows[] = [
                $bot->user->username,
                $bot->profile,
                implode(', ', $personality['habits']),
            ];
            $assigned++;
        }

        if ($rows !== []) {
            $this->table(['Username', 'Class', 'Habits'], $rows);
        }

        $this->info(sprintf('Assigned habits to %d bot(s); %d already had habits.', $assigned, $skipped));

        return self::SUCCESS;
    }
}
