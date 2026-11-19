<?php

namespace OGame\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use OGame\Enums\DarkMatterTransactionType;
use OGame\Enums\DirectiveProgressStatus;
use OGame\Exceptions\ClaimRejection;
use OGame\GameObjects\Models\Units\UnitCollection;
use OGame\Models\DirectiveActivityMarker;
use OGame\Models\DirectiveProgress;
use OGame\Models\Resources;
use RuntimeException;

/**
 * Backend of the "Directives" quest system (see docs/DIRECTIVES-PLAN.md and
 * config/directives.php).
 *
 * Mission progress is never stored as counters: conditions are re-evaluated lazily
 * against live game state (throttled in the GlobalGame middleware, forced when the
 * overlay is opened), and only completions/claims are persisted. One-off actions that
 * cannot be derived from game state (opening the overlay, renaming a planet, sending
 * a buddy request) are recorded as activity markers.
 */
class DirectiveService
{
    /**
     * The mission_id of a chapter completion bonus progress row.
     */
    public const CHAPTER_BONUS_MISSION_ID = '';

    /**
     * Activity key recorded when the Directives overlay is opened. This is what
     * completes vanilla's meta mission "Open the mission overview".
     */
    public const ACTIVITY_DIRECTIVES_OPENED = 'directives_opened';

    /**
     * Activity key recorded when the player re-nicknames through the change
     * nickname flow.
     */
    public const ACTIVITY_PLANET_RENAMED = 'planet_renamed';

    /**
     * Activity key recorded when the player sends a buddy request.
     */
    public const ACTIVITY_BUDDY_REQUEST_SENT = 'buddy_request_sent';

    /**
     * Seconds between two lazy evaluation passes for the same player.
     */
    private const EVALUATION_THROTTLE_SECONDS = 60;

    public function __construct(private DarkMatterService $darkMatterService)
    {
    }

    /**
     * All configured chapters, keyed by chapter id, in catalog order.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getChapters(): array
    {
        /** @var array<string, array<string, mixed>> $chapters */
        $chapters = config('directives.chapters', []);

        return $chapters;
    }

    /**
     * Evaluates pending mission conditions for the player, throttled to once per
     * minute per player. Called on every in-game request by the GlobalGame middleware.
     */
    public function ensureEvaluated(PlayerService $player): void
    {
        $lockKey = 'directive_eval_' . $player->getId();
        if (!Cache::add($lockKey, 1, self::EVALUATION_THROTTLE_SECONDS)) {
            return;
        }

        $this->evaluate($player);
    }

    /**
     * Evaluates all missions without a progress row and marks them completed when
     * their conditions are met. Chapter bonus rows are completed once every mission
     * of the chapter has been claimed.
     */
    public function evaluate(PlayerService $player): void
    {
        $userId = $player->getId();
        $rows = $this->progressRows($player);

        foreach ($this->getChapters() as $chapterId => $chapter) {
            $allClaimed = true;

            /** @var string $missionId */
            /** @var array<string, mixed> $mission */
            foreach ($chapter['missions'] ?? [] as $missionId => $mission) {
                $row = $rows->get($chapterId . ':' . $missionId);
                if ($row === null) {
                    $allClaimed = false;
                    $this->completeMissionIfConditionMet($player, $chapterId, $missionId, $mission);
                    continue;
                }

                if ($row->status !== DirectiveProgressStatus::Claimed) {
                    $allClaimed = false;
                }
            }

            // The chapter bonus becomes claimable once every mission of the chapter
            // is collected (vanilla "Claim All" behaviour).
            $chapterRewards = $chapter['chapter_rewards'] ?? [];
            if (
                $allClaimed
                && $chapterRewards !== []
                && $rows->get($chapterId . ':' . self::CHAPTER_BONUS_MISSION_ID) === null
            ) {
                $this->insertCompletedRow($userId, $chapterId, self::CHAPTER_BONUS_MISSION_ID);
            }
        }
    }

    /**
     * Marks a one-off player activity (consumed by activity-type conditions).
     */
    public function recordActivity(int $userId, string $activity): void
    {
        DirectiveActivityMarker::insertOrIgnore([
            'user_id' => $userId,
            'activity' => $activity,
            'created_at' => Date::now()->toDateTimeString(),
            'updated_at' => Date::now()->toDateTimeString(),
        ]);
    }

    /**
     * Number of claimable (completed, unclaimed) rewards across all chapters.
     * Used for the collect badge on the left-hand menu panel.
     */
    public function claimableCount(PlayerService $player): int
    {
        return DirectiveProgress::where('user_id', $player->getId())
            ->where('status', DirectiveProgressStatus::Completed->value)
            ->count();
    }

    /**
     * Title of the first mission that is not yet completed, used as the "current
     * mission" hint in the collapsed Directives panel. Null when everything is
     * completed/claimed or nothing is configured.
     */
    public function currentMission(PlayerService $player): string|null
    {
        $rows = $this->progressRows($player);

        foreach ($this->getChapters() as $chapterId => $chapter) {
            /** @var string $missionId */
            /** @var array<string, mixed> $mission */
            foreach ($chapter['missions'] ?? [] as $missionId => $mission) {
                if ($rows->has($chapterId . ':' . $missionId)) {
                    continue;
                }

                return Lang::get($mission['title']);
            }
        }

        return null;
    }

    /**
     * Claims a single mission (or the chapter bonus when $missionId is the
     * CHAPTER_BONUS_MISSION_ID) and grants its reward. The status transition is a
     * conditional update inside a transaction, so concurrent claims cannot grant
     * the same reward twice.
     *
     * @return array{success: bool, message: string|null, unclaimed: int}
     */
    public function claim(PlayerService $player, string $chapterId, string $missionId): array
    {
        $reward = $this->findReward($chapterId, $missionId);
        if ($reward === null) {
            return $this->claimResult($player, false, Lang::get('t_directives.ui.not_found'));
        }

        try {
            DB::transaction(function () use ($player, $chapterId, $missionId, $reward): void {
                $this->transitionToClaimed($player, $chapterId, $missionId);
                $this->grantReward($player, $reward, $chapterId, $missionId);
            });
        } catch (ClaimRejection $e) {
            return $this->claimResult($player, false, $e->getMessage());
        }

        return $this->claimResult($player, true, null);
    }

    /**
     * Claims every completed row of the given chapter (missions and, once they are
     * all claimed, the chapter bonus on a follow-up evaluation). Returns the number
     * of rewards granted.
     */
    public function claimAll(PlayerService $player, string $chapterId): int
    {
        if (!isset($this->getChapters()[$chapterId])) {
            return 0;
        }

        // Sync completions first, so missions completed through game state since the
        // last evaluation pass are collected by the same "Claim All" press.
        $this->evaluate($player);

        $granted = 0;

        DB::transaction(function () use ($player, $chapterId, &$granted): void {
            $rows = DirectiveProgress::where('user_id', $player->getId())
                ->where('directive_id', $chapterId)
                ->where('status', DirectiveProgressStatus::Completed->value)
                ->get();

            /** @var DirectiveProgress $row */
            foreach ($rows as $row) {
                $reward = $this->findReward($chapterId, $row->mission_id);
                if ($reward === null) {
                    continue;
                }

                $this->transitionToClaimed($player, $chapterId, $row->mission_id);
                $this->grantReward($player, $reward, $chapterId, $row->mission_id);
                $granted++;
            }
        });

        // Completing the last mission may have unlocked the chapter bonus; make it
        // claimable right away so "Claim All" can be pressed again immediately.
        $this->evaluate($player);

        return $granted;
    }

    /**
     * Assembles everything the overlay view needs for one chapter.
     *
     * @return array{
     *     chapters: array<int, array<string, mixed>>,
     *     chapter: array<string, mixed>,
     *     missions: array<int, array<string, mixed>>,
     *     unclaimed: int
     * }
     */
    public function getOverlayData(PlayerService $player, string $chapterId): array
    {
        $chapters = $this->getChapters();
        $activeChapterId = isset($chapters[$chapterId]) ? $chapterId : (string)array_key_first($chapters);

        $rows = $this->progressRows($player);

        $chapterTabs = [];
        $missionRows = [];
        $activeChapter = [
            'id' => $activeChapterId,
            'title' => '',
            'rewards' => [],
            'bonus_state' => 'in_progress',
        ];

        foreach ($chapters as $id => $chapter) {
            $allCollected = true;

            /** @var string $missionId */
            /** @var array<string, mixed> $mission */
            foreach ($chapter['missions'] ?? [] as $missionId => $mission) {
                $state = $this->missionState($rows, $id, $missionId);
                if ($state !== 'collected') {
                    $allCollected = false;
                }

                if ($id === $activeChapterId) {
                    $missionRows[] = $this->missionViewData($player, $id, $missionId, $mission, $state);
                }
            }

            $bonusState = $this->missionState($rows, $id, self::CHAPTER_BONUS_MISSION_ID);
            if ($bonusState !== 'collected') {
                $allCollected = false;
            }

            $chapterTabs[] = [
                'id' => $id,
                'tab' => $chapter['tab'],
                'active' => $id === $activeChapterId,
                'all_done' => $allCollected,
            ];

            if ($id === $activeChapterId) {
                $activeChapter = [
                    'id' => $id,
                    'title' => Lang::get($chapter['title']),
                    'rewards' => $this->rewardItems($chapter['chapter_rewards'] ?? []),
                    'bonus_state' => $bonusState,
                ];
            }
        }

        return [
            'chapters' => $chapterTabs,
            'chapter' => $activeChapter,
            'missions' => $missionRows,
            'unclaimed' => $this->claimableCount($player),
        ];
    }

    /**
     * Live progress [current, target] for any condition type. Current is capped at
     * the target. Public for condition tests.
     *
     * @param array<string, mixed> $condition
     * @return array{int, int}
     */
    public function conditionProgress(array $condition, PlayerService $player): array
    {
        $target = (int)($condition['target'] ?? 1);
        $current = 0;

        switch ($condition['type']) {
            case 'activity':
                $current = DirectiveActivityMarker::where('user_id', $player->getId())
                    ->where('activity', $condition['key'])
                    ->exists() ? 1 : 0;
                break;

            case 'alliance':
                $current = $player->getUser()->alliance_id !== null ? 1 : 0;
                break;

            case 'building_level':
                $levels = [];
                foreach ($this->scopedPlanets($player, (string)($condition['scope'] ?? 'any')) as $planet) {
                    $levels[] = $planet->getObjectLevel($condition['object']);
                }
                $current = $levels !== [] ? max($levels) : 0;
                break;

            case 'research_level':
                $current = $player->getResearchLevel((string)$condition['object']);
                break;

            case 'unit_count':
                /** @var array<int, string> $objects */
                $objects = $condition['objects'];
                foreach ($this->scopedPlanets($player, (string)($condition['scope'] ?? 'any')) as $planet) {
                    $shipUnits = $planet->getShipUnits();
                    foreach ($objects as $machineName) {
                        $current += $shipUnits->getAmountByMachineName($machineName);
                    }
                }
                break;

            default:
                throw new RuntimeException(sprintf('Unknown directive condition type: %s', $condition['type']));
        }

        return [min($current, $target), $target];
    }

    /**
     * Renders a mission accordion row.
     *
     * @param array<string, mixed> $mission
     * @return array<string, mixed>
     */
    private function missionViewData(
        PlayerService $player,
        string $chapterId,
        string $missionId,
        array $mission,
        string $state
    ): array {
        [$current, $target] = $this->conditionProgress($mission['condition'], $player);
        if ($state === 'collected') {
            $current = $target;
        }

        return [
            'directive_id' => $chapterId,
            'mission_id' => $missionId,
            'title' => Lang::get($mission['title']),
            'state' => $state,
            'progress_current' => $current,
            'progress_target' => $target,
            'track' => match ($state) {
                'collected' => Lang::get('t_directives.ui.collected'),
                'completed' => Lang::get('t_directives.ui.collect'),
                default => '',
            },
            'description' => isset($mission['description']) ? Lang::get($mission['description']) : null,
            'actions' => array_map(
                static fn (string $key): string => Lang::get($key),
                $mission['actions'] ?? []
            ),
            'rewards' => $this->rewardItems($mission['rewards'] ?? []),
        ];
    }

    /**
     * State string for the UI: "collected" (claimed), "completed" (claimable) or
     * "in_progress".
     */
    private function missionState(Collection $rows, string $chapterId, string $missionId): string
    {
        $row = $rows->get($chapterId . ':' . $missionId);
        if ($row === null) {
            return 'in_progress';
        }

        return $row->status === DirectiveProgressStatus::Claimed ? 'collected' : 'completed';
    }

    /**
     * All progress rows of the player keyed by "chapterId:missionId".
     *
     * @return Collection<string, DirectiveProgress>
     */
    private function progressRows(PlayerService $player): Collection
    {
        return DirectiveProgress::where('user_id', $player->getId())
            ->get(['directive_id', 'mission_id', 'status'])
            ->keyBy(fn (DirectiveProgress $row): string => $row->directive_id . ':' . $row->mission_id);
    }

    /**
     * Converts a reward definition into renderable items with the vanilla reward
     * sprite classes (resourceReward + resource-N per vanilla resource type id:
     * crystal=1, deuterium=2, dark matter=4; metal is the unmodified sprite).
     *
     * @param array<string, mixed> $reward
     * @return array<int, array{class: string, amount: string}>
     */
    private function rewardItems(array $reward): array
    {
        $icons = [
            'metal' => 'resourceReward',
            'crystal' => 'resourceReward resource-1',
            'deuterium' => 'resourceReward resource-2',
            'dark_matter' => 'resourceReward resource-4',
        ];

        $items = [];
        foreach ($icons as $key => $class) {
            $amount = (int)($reward[$key] ?? 0);
            if ($amount <= 0) {
                continue;
            }

            $items[] = [
                'class' => $class,
                'amount' => number_format($amount),
            ];
        }

        return $items;
    }

    /**
     * Looks up the reward definition of a mission (or the chapter bonus row).
     *
     * @return array<string, mixed>|null
     */
    private function findReward(string $chapterId, string $missionId): array|null
    {
        $chapter = $this->getChapters()[$chapterId] ?? null;
        if ($chapter === null) {
            return null;
        }

        if ($missionId === self::CHAPTER_BONUS_MISSION_ID) {
            $rewards = $chapter['chapter_rewards'] ?? [];

            return $rewards !== [] ? $rewards : null;
        }

        $mission = $chapter['missions'][$missionId] ?? null;
        if ($mission === null) {
            return null;
        }

        $rewards = $mission['rewards'] ?? [];

        return $rewards !== [] ? $rewards : null;
    }

    /**
     * Atomically transitions a completed row to claimed, rejecting anything else.
     * Runs inside the caller's transaction so a failed reward grant rolls the
     * status back with it.
     */
    private function transitionToClaimed(PlayerService $player, string $chapterId, string $missionId): void
    {
        $updated = DirectiveProgress::where('user_id', $player->getId())
            ->where('directive_id', $chapterId)
            ->where('mission_id', $missionId)
            ->where('status', DirectiveProgressStatus::Completed->value)
            ->update([
                'status' => DirectiveProgressStatus::Claimed->value,
                'claimed_at' => Date::now()->toDateTimeString(),
                'updated_at' => Date::now()->toDateTimeString(),
            ]);

        if ($updated === 1) {
            return;
        }

        $exists = DirectiveProgress::where('user_id', $player->getId())
            ->where('directive_id', $chapterId)
            ->where('mission_id', $missionId)
            ->exists();

        throw new ClaimRejection(
            $exists
                ? Lang::get('t_directives.ui.already_collected')
                : Lang::get('t_directives.ui.not_completed')
        );
    }

    /**
     * Grants a reward payload to the planet that is active at claim time (vanilla
     * semantics); Dark Matter goes to the player account itself.
     *
     * @param array<string, mixed> $reward
     */
    private function grantReward(PlayerService $player, array $reward, string $chapterId, string $missionId): void
    {
        $planet = $player->planets->current();

        $resources = new Resources(
            (int)($reward['metal'] ?? 0),
            (int)($reward['crystal'] ?? 0),
            (int)($reward['deuterium'] ?? 0)
        );
        if ($resources->any()) {
            $planet->addResources($resources);
        }

        /** @var array<string, int>|null $units */
        $units = $reward['units'] ?? null;
        if ($units !== null && $units !== []) {
            $collection = new UnitCollection();
            foreach ($units as $machineName => $amount) {
                $collection->addUnit(ObjectService::getShipObjectByMachineName((string)$machineName), $amount);
            }
            $planet->addUnits($collection);
        }

        $darkMatter = (int)($reward['dark_matter'] ?? 0);
        if ($darkMatter > 0) {
            $this->darkMatterService->credit(
                $player->getUser(),
                $darkMatter,
                DarkMatterTransactionType::DIRECTIVES->value,
                sprintf('Directives reward: %s/%s', $chapterId, $missionId ?: 'chapter')
            );
        }
    }

    /**
     * Inserts a completed (unclaimed) progress row, ignoring unique-constraint
     * races with a concurrent evaluation pass.
     */
    private function insertCompletedRow(int $userId, string $chapterId, string $missionId): void
    {
        DirectiveProgress::insertOrIgnore([
            'user_id' => $userId,
            'directive_id' => $chapterId,
            'mission_id' => $missionId,
            'status' => DirectiveProgressStatus::Completed->value,
            'completed_at' => Date::now()->toDateTimeString(),
            'claimed_at' => null,
            'created_at' => Date::now()->toDateTimeString(),
            'updated_at' => Date::now()->toDateTimeString(),
        ]);
    }

    /**
     * Evaluates a mission condition; completes the mission when met.
     *
     * @param array<string, mixed> $mission
     */
    private function completeMissionIfConditionMet(PlayerService $player, string $chapterId, string $missionId, array $mission): void
    {
        [$current, $target] = $this->conditionProgress($mission['condition'], $player);
        if ($current >= $target) {
            $this->insertCompletedRow($player->getId(), $chapterId, $missionId);
        }
    }

    /**
     * Planets a condition looks at: 'active' is the current planet only, 'any' is
     * every planet owned by the player.
     *
     * @return array<int, PlanetService>
     */
    private function scopedPlanets(PlayerService $player, string $scope): array
    {
        if ($scope === 'active') {
            return [$player->planets->current()];
        }

        return $player->planets->allPlanets();
    }

    /**
     * @return array{success: bool, message: string|null, unclaimed: int}
     */
    private function claimResult(PlayerService $player, bool $success, string|null $message): array
    {
        return [
            'success' => $success,
            'message' => $message,
            'unclaimed' => $this->claimableCount($player),
        ];
    }
}
