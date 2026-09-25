<?php

declare(strict_types=1);

namespace Tests\Unit\Drafting;

use App\Drafting\DigestSeries;
use App\Enums\ContentFormat;
use App\Enums\ContentKind;
use App\Enums\Platform;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class DigestSeriesTest extends TestCase
{
    public function test_what_the_panel_saves_is_tidied(): void
    {
        $series = DigestSeries::fromArray([
            'key' => 'k1',
            'enabled' => '1',
            'name' => 'Kaufland srijedom',
            'headline' => ' Top {count} akcija u Kauflandu ',
            'days' => ['3', '3', '5'],
            'time' => '9:30:00',
            'count' => '40',
            'kind' => 'deal',
            'tag' => ' Kaufland ',
            'formats' => ['meta' => 'video', 'tiktok' => 'image'],
            'seconds_per_slide' => '1.75',
        ]);

        $this->assertTrue($series->enabled);
        $this->assertSame('Top {count} akcija u Kauflandu', $series->headline);
        $this->assertSame([3, 5], $series->days);
        $this->assertSame('09:30', $series->time);
        $this->assertSame(9, $series->count, 'Instagram carousel nosi najviše 9 stavki uz naslovnicu');
        $this->assertSame(ContentKind::Deal, $series->kind);
        $this->assertSame('kaufland', $series->tag);
        $this->assertSame(ContentFormat::Video, $series->metaFormat);
        $this->assertSame(ContentFormat::Carousel, $series->tiktokFormat, 'jedna slika izgubila bi sve stavke osim naslovnice');
        $this->assertSame(1.75, $series->secondsPerSlide);
    }

    public function test_a_reel_goes_only_where_the_channel_takes_one(): void
    {
        $series = DigestSeries::fromArray(['formats' => ['meta' => 'video', 'tiktok' => 'carousel']]);

        $this->assertSame(ContentFormat::Video, $series->formatFor(Platform::InstagramBusiness));
        $this->assertSame(ContentFormat::Video, $series->formatFor(Platform::FacebookPage));
        $this->assertSame(ContentFormat::Carousel, $series->formatFor(Platform::FacebookGroup));
        $this->assertSame(ContentFormat::Video, $series->formatFor(Platform::TikTok), 'TikTok je uvijek video');
    }

    public function test_it_is_due_on_its_days_at_its_time(): void
    {
        $series = DigestSeries::fromArray(['days' => [3], 'time' => '18:30']);
        $wednesday = CarbonImmutable::parse('2026-09-16 10:00', 'Europe/Zagreb');

        $this->assertTrue($series->isDueOn($wednesday));
        $this->assertFalse($series->isDueOn($wednesday->addDay()));
        $this->assertSame('2026-09-16 18:30', $series->at($wednesday)->format('Y-m-d H:i'));
    }

    public function test_the_old_single_digest_becomes_the_first_series(): void
    {
        $legacy = DigestSeries::fromLegacy(['enabled' => true, 'days' => ['1', '4'], 'time' => '19:00', 'count' => 5, 'kind' => 'job']);

        $this->assertNotNull($legacy);
        $this->assertNotEmpty($legacy['key']);

        $series = DigestSeries::fromArray($legacy);
        $this->assertTrue($series->enabled);
        $this->assertSame([1, 4], $series->days);
        $this->assertSame(ContentFormat::Carousel, $series->formatFor(Platform::InstagramBusiness), 'dosadašnji pregled ostaje carousel');
        $this->assertNull($series->tag);

        $this->assertNull(DigestSeries::fromLegacy(['enabled' => false, 'days' => [], 'time' => null, 'count' => null, 'kind' => null]), 'nikad postavljen pregled ne postaje serija');
    }
}
