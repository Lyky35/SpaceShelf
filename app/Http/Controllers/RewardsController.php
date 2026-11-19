<?php

namespace OGame\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use OGame\Enums\StarterAidReward;
use OGame\Services\PlayerService;
use OGame\Services\StarterAidService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RewardsController extends OGameController
{
    /**
     * Shows the rewards index page
     *
     * @param Request $request
     * @param PlayerService $player
     * @param StarterAidService $starterAidService
     * @return View
     */
    public function index(Request $request, PlayerService $player, StarterAidService $starterAidService): View
    {
        $rewards = [];
        foreach ($starterAidService->getGroupedRewards($player) as $status => $rewardList) {
            foreach ($rewardList as $reward) {
                $rewards[$status][] = [
                    'reward' => $reward,
                    'message' => $starterAidService->getRewardMessage($reward, $player->getUsername(false)),
                ];
            }
        }

        return view('ingame.rewards.index', ['rewards' => $rewards]);
    }

    /**
     * Claims a Starter Aid reward.
     *
     * @param Request $request
     * @param PlayerService $player
     * @param StarterAidService $starterAidService
     * @return RedirectResponse
     */
    public function claim(Request $request, PlayerService $player, StarterAidService $starterAidService): RedirectResponse
    {
        $reward = StarterAidReward::tryFrom((string)$request->route('reward'));
        if ($reward === null) {
            throw new NotFoundHttpException();
        }

        $claimed = $starterAidService->claim($player, $reward);
        if (!$claimed) {
            return redirect()->route('rewards.index')->with('rewardError', __('t_rewards.claim_failed'));
        }

        return redirect()->route('rewards.index')->with('rewardClaimed', __('t_rewards.claimed_success'));
    }
}
