<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Pinterest\PinFeed;
use Illuminate\Http\Response;

final class PinterestFeedController extends Controller
{
    public function show(string $brand, string $board, PinFeed $feed): Response
    {
        $model = Brand::query()->where('slug', $brand)->first();

        // Only boards the config names: any other pair is not a feed.
        abort_if($model === null || ! is_string(config("pinterest.brands.{$brand}.boards.{$board}")), 404);

        return response($feed->xml($model, $board), 200, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
            // Pinterest fetches on its own schedule (within 24 hours); an hour is fresh enough for a feed that changes on Thursdays.
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
