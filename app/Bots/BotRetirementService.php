<?php

namespace OGame\Bots;

use Illuminate\Support\Facades\Log;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Bot;
use OGame\Models\EspionageReport;
use OGame\Models\Message;
use OGame\Models\User;
use Throwable;

/**
 * Permanently deletes a bot account (population lifecycle: lapsed bots that
 * went permanently inactive eventually get purged like any abandoned one).
 *
 * PlayerService::delete() covers the account bulk: planets, queues, every
 * fleet leg touching its worlds (including enemy fleets inbound on them —
 * those flights die with the target), messages, highscore and tech rows;
 * the remaining user-level tables (bans, notes, alliances, unions, claims,
 * directives, ...) cascade at the database level. Two gaps are closed here:
 * espionage reports ABOUT the bot pin its user with a RESTRICT foreign key,
 * and messages referencing those reports pin the reports — so both must go
 * before the user row can leave.
 */
class BotRetirementService
{
    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private BotRegistry $botRegistry,
    ) {
    }

    /**
     * Delete one bot's whole account; the bots table row cascades off the
     * user foreign key.
     *
     * @param Bot $bot
     * @return bool Whether the account was fully deleted.
     */
    public function retire(Bot $bot): bool
    {
        $userId = (int) $bot->user_id;

        if (!User::whereKey($userId)->exists()) {
            // The account is already gone under the bot row; just sweep the
            // orphaned meta row.
            $bot->delete();
            $this->botRegistry->forgetBotUserIdsCache();

            return false;
        }

        $player = $this->playerServiceFactory->make($userId, true);

        try {
            $reportIds = EspionageReport::where('planet_user_id', $userId)->pluck('id');

            if ($reportIds->isNotEmpty()) {
                Message::whereIn('espionage_report_id', $reportIds)->delete();
                EspionageReport::whereIn('id', $reportIds)->delete();
            }

            $player->delete();

            $this->botRegistry->forgetBotUserIdsCache();

            Log::info(sprintf('SpaceShelf bot %d (user %d) retired: account deleted.', $bot->id, $userId));

            return true;
        } catch (Throwable $e) {
            Log::error(sprintf('SpaceShelf bot %d retirement failed: %s', $bot->id, $e->getMessage()));

            return false;
        }
    }
}
