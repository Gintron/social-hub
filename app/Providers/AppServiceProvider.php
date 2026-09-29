<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\CaptionWriter;
use App\Ai\OpenAiCaptionWriter;
use App\Publishing\TikTok\Business\TikTokBusinessOAuth;
use App\Publishing\TikTok\TikTokOAuth;
use App\Publishing\TikTok\TikTokTokens;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One place decides which writer the agent gets, so tests bind the fake instead of the API.
        $this->app->bind(CaptionWriter::class, OpenAiCaptionWriter::class);

        $this->app->bind(TikTokTokens::class, fn (): TikTokTokens => config('tiktok.driver') === 'business'
            ? $this->app->make(TikTokBusinessOAuth::class)
            : $this->app->make(TikTokOAuth::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
