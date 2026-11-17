<?php

namespace OGame\Http\Controllers;

use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use OGame\Enums\Officer;
use OGame\Services\OfficerService;

class PremiumController extends OGameController
{
    /**
     * PremiumController constructor.
     *
     * @param OfficerService $officerService
     */
    public function __construct(
        private OfficerService $officerService
    ) {
    }

    /**
     * Shows the premium/officers index page
     *
     * @return View
     */
    public function index(): View
    {
        $this->setBodyId('premium');

        $user = Auth::user();
        if ($user === null) {
            abort(403);
        }

        // Get current user's dark matter balance
        $darkMatter = $user->dark_matter ?? 0;

        // Prepare officer recruitment data (cost + remaining active days per officer)
        $officerRows = [];
        $activeOfficerCount = 0;
        foreach (Officer::cases() as $officer) {
            $remainingDays = $this->officerService->getRemainingDays($user, $officer);
            if ($remainingDays > 0) {
                $activeOfficerCount++;
            }

            $officerRows[] = [
                'officer' => $officer,
                'cost' => $officer->getCostPerWeek(),
                'remainingDays' => $remainingDays,
            ];
        }

        return view('ingame.premium.index', [
            'darkMatter' => $darkMatter,
            'officerRows' => $officerRows,
            'activeOfficerCount' => $activeOfficerCount,
        ]);
    }

    /**
     * Hire (or extend) an officer for a given amount of weeks, paying with Dark Matter.
     *
     * @param Request $request
     * @return RedirectResponse
     */
    public function hire(Request $request): RedirectResponse
    {
        $request->validate([
            'officer' => 'required|string',
            'weeks' => 'nullable|integer|min:1|max:' . OfficerService::MAX_WEEKS_PER_PURCHASE,
        ]);

        $user = Auth::user();
        if ($user === null) {
            abort(403);
        }

        // Make sure we work with the latest persisted state of the user
        $user->refresh();

        $officer = Officer::tryFrom((string)$request->input('officer'));
        if ($officer === null) {
            return redirect()->back()->with('statusError', __('t_ingame.premium.hire_failed'));
        }

        $weeks = (int)$request->input('weeks', 1);

        // Check if the user can afford the officer before attempting the hire
        if (!$this->officerService->canAfford($user, $officer, $weeks)) {
            return redirect()->back()->with('statusError', __('t_ingame.premium.hire_insufficient_dm'));
        }

        try {
            $this->officerService->hire($user, $officer, $weeks);
        } catch (Exception $e) {
            logger()->error("Failed to hire officer {$officer->value} for user {$user->id}: {$e->getMessage()}");

            return redirect()->back()->with('statusError', __('t_ingame.premium.hire_failed'));
        }

        $user->refresh();

        return redirect()->route('premium.index')->with('status', __('t_ingame.premium.hired_success', [
            'name' => $officer->getName(),
            'weeks' => $weeks,
            'cost' => number_format($this->officerService->getHireCost($officer, $weeks), 0, ',', '.'),
        ]));
    }
}
