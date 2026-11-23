<?php

namespace OGame\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use OGame\Bots\PersonalityFactory;
use OGame\Http\Controllers\OGameController;
use OGame\Models\Bot;
use OGame\Services\CharacterClassService;
use Throwable;

/**
 * Admin "Bots" page (docs/BOT-AI-PLAN.md phase 6): inspect and steer the
 * autonomous players without artisan — pause and resume them, switch their
 * behavior profile (which also syncs the in-game character class), and opt
 * individual bots out of the public highscore leaderboard.
 */
class BotsController extends OGameController
{
    public function __construct(private CharacterClassService $characterClassService)
    {
    }

    /**
     * Show the bot roster with a little live state per bot.
     */
    public function index(): View
    {
        return view('ingame.admin.bots', [
            'bots' => Bot::with('user.highscore')->orderBy('id')->paginate(50),
            'profiles' => array_keys((array) config('bots.personalities')),
            'enabledCount' => Bot::where('enabled', true)->count(),
            'hiatusCount' => Bot::whereNotNull('inactive_since')->count(),
        ]);
    }

    /**
     * Pause or resume one bot; the heartbeat skips disabled bots.
     */
    public function toggleEnabled(Bot $bot): RedirectResponse
    {
        $bot->update(['enabled' => !$bot->enabled]);

        return redirect()->route('admin.bots.index')
            ->with('success', $bot->enabled ? 'Bot resumed' : 'Bot paused');
    }

    /**
     * Toggle a bot's visibility on the public highscore leaderboard.
     */
    public function toggleHighscore(Bot $bot): RedirectResponse
    {
        $bot->update(['hide_from_highscore' => !$bot->hide_from_highscore]);

        return redirect()->route('admin.bots.index')
            ->with('success', $bot->hide_from_highscore ? 'Bot hidden from highscore' : 'Bot visible in highscore');
    }

    /**
     * Switch a bot's behavior profile: rebuild the jittered personality and
     * move the in-game character class along with it, so class bonuses and
     * class-ship entitlements keep matching what the brain plays for.
     */
    public function updateProfile(Request $request, Bot $bot): RedirectResponse
    {
        $validated = $request->validate([
            'profile' => ['required', 'string', Rule::in(array_keys((array) config('bots.personalities')))],
        ]);

        $personality = PersonalityFactory::make($validated['profile']);

        $bot->update([
            'profile' => $personality->profile,
            'personality' => $personality->toArray(),
        ]);

        $targetClass = PersonalityFactory::CLASS_MAP[$personality->profile];
        $user = $bot->user;

        if ($user->character_class !== $targetClass->value) {
            try {
                $this->characterClassService->selectClass($user, $targetClass);
            } catch (Throwable $problem) {
                // The class switch can legitimately fail (Dark Matter cost,
                // fleets in transit); the new profile still governs the brain
                // from the next visit, the admin can resolve the class later.
                return redirect()->route('admin.bots.index')
                    ->with('error', 'Profile switched, but the character class was not changed: ' . $problem->getMessage());
            }
        }

        return redirect()->route('admin.bots.index')->with('success', 'Bot profile updated');
    }
}
