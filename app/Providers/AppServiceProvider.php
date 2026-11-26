<?php

namespace App\Providers;

use App\Ashby\Ai\AnthropicClient;
use App\Ashby\Ai\LlmClient;
use App\Ashby\Ai\StubClient;
use App\Ashby\Engine\AshbyEngine;
use App\Ashby\Engine\ModelData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ModelData::class, fn (): ModelData => new ModelData);
        $this->app->singleton(AshbyEngine::class, fn ($app): AshbyEngine => new AshbyEngine($app->make(ModelData::class)));
        // The real AI service when a key is set; otherwise the stand-in, which AI features use only off production.
        $this->app->singleton(LlmClient::class, fn (): LlmClient => (string) config('ashby.ai.anthropic_key') !== ''
            ? new AnthropicClient((string) config('ashby.ai.anthropic_key'), (string) config('ashby.ai.model'))
            : new StubClient);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Behind the host's proxy the app may not see HTTPS itself; links must still use it.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
