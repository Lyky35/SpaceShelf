<?php

namespace OGame\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use OGame\Bots\Brain\BotBrain;
use OGame\Bots\Brain\UtilityBrain;
use OGame\Exceptions\Handler;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\User;
use OGame\Observers\UserObserver;
use OGame\Services\SettingsService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    final public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Legacy JS bundles are plain concatenated scripts that rely on jQuery globals
        // and must not be treated as ES modules.
        Vite::useScriptTagAttributes(['type' => false]);

        // Guard against in-game chat spam (upstream #1647): a sane number of
        // message sends per minute per authenticated user.
        RateLimiter::for('chatsend', function (Request $request) {
            return Limit::perMinute(30)->by((int) $request->user()?->id);
        });

        // Register composer file for the main ingame layout.
        view()->composer('ingame.layouts.main', 'OGame\Http\ViewComposers\IngameMainComposer');

        // Register model observers
        User::observe(UserObserver::class);
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    final public function register(): void
    {
        $this->app->singleton(function ($app): SettingsService {
            return new SettingsService();
        });

        $this->app->singleton(function ($app): PlayerServiceFactory {
            return new PlayerServiceFactory();
        });

        $this->app->singleton(function ($app): PlanetServiceFactory {
            return new PlanetServiceFactory(
                $app->make(SettingsService::class),
                $app->make(PlayerServiceFactory::class)
            );
        });

        $this->app->singleton(ExceptionHandler::class, Handler::class);

        // Swap the bot brain implementation here to experiment with new
        // decision engines (see docs/BOT-AI-PLAN.md).
        $this->app->bind(BotBrain::class, UtilityBrain::class);
    }
}
