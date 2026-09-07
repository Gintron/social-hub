<?php

declare(strict_types=1);

namespace App\Providers;

use Anthropic\Client;
use App\Ai\AnthropicCaptionWriter;
use App\Ai\CaptionWriter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One place decides which writer the agent gets, so tests bind the fake instead of the API.
        $this->app->singleton(Client::class, fn (): Client => new Client(apiKey: (string) config('hub.ai.api_key')));
        $this->app->bind(CaptionWriter::class, AnthropicCaptionWriter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
