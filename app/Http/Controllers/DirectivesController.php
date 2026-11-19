<?php

namespace OGame\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use OGame\Services\DirectiveService;
use OGame\Services\PlayerService;

/**
 * "Directives" quest overlay (see docs/DIRECTIVES-PLAN.md).
 *
 * The overlay itself is fetched through the vanilla overlay framework (the
 * `a.overlay` link in the left menu) and rendered server-side as a partial;
 * the chapter tabs, mission accordions and claim buttons are driven by
 * resources/js/ingame/directives.js on top of that partial.
 */
class DirectivesController extends OGameController
{
    /**
     * Renders the Directives overlay content for one chapter. Opening it records
     * the "directives_opened" activity, which is what completes the "Open the
     * mission overview" meta mission in vanilla.
     */
    public function overlay(Request $request, PlayerService $player, DirectiveService $directiveService): View
    {
        $directiveService->recordActivity($player->getId(), DirectiveService::ACTIVITY_DIRECTIVES_OPENED);

        // Forced, unthrottled evaluation so a mission completed by this very page
        // view is claimable in this render (vanilla reacts immediately).
        $directiveService->evaluate($player);

        return view('ingame.directives.overlay', $directiveService->getOverlayData(
            $player,
            (string)$request->query('chapter', '')
        ));
    }

    /**
     * Claims a single mission reward (or the chapter bonus when mission is empty).
     */
    public function claim(Request $request, PlayerService $player, DirectiveService $directiveService): JsonResponse
    {
        $result = $directiveService->claim(
            $player,
            (string)$request->post('directive', ''),
            (string)$request->post('mission', '')
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Claims every completed reward of one chapter ("Claim All").
     */
    public function claimAll(Request $request, PlayerService $player, DirectiveService $directiveService): JsonResponse
    {
        $granted = $directiveService->claimAll(
            $player,
            (string)$request->post('directive', '')
        );

        return response()->json([
            'success' => $granted > 0,
            'granted' => $granted,
            'message' => $granted > 0 ? null : __('t_directives.ui.not_completed'),
            'unclaimed' => $directiveService->claimableCount($player),
        ], $granted > 0 ? 200 : 400);
    }
}
