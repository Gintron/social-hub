<?php

declare(strict_types=1);

namespace Tests\Feature\Voiceover;

use App\Voiceover\VoiceoverSettings;
use Tests\TestCase;

/**
 * The hub speaks with one model, v4, and moves to a newer one by changing the list — never the code.
 */
final class VoiceoverSettingsTest extends TestCase
{
    public function test_v4_is_the_only_model_offered_and_the_default(): void
    {
        $this->assertSame(['eleven_v4'], array_keys((array) config('elevenlabs.models')));
        $this->assertSame('eleven_v4', config('elevenlabs.model'));
        $this->assertSame('eleven_v4', VoiceoverSettings::fromArray([])->model);
    }

    public function test_a_model_a_brand_saved_before_v4_became_the_only_one_is_read_as_the_default(): void
    {
        foreach (['eleven_multilingual_v2', 'eleven_v3', 'eleven_flash_v2_5', 'something-else'] as $old) {
            $this->assertSame('eleven_v4', VoiceoverSettings::fromArray(['model' => $old])->model, $old);
        }
    }

    public function test_a_model_added_to_the_list_is_kept_and_the_default_can_move(): void
    {
        config()->set('elevenlabs.models', ['eleven_v4' => 'v4', 'eleven_next' => 'next']);

        $this->assertSame('eleven_next', VoiceoverSettings::fromArray(['model' => 'eleven_next'])->model);

        config()->set('elevenlabs.model', 'eleven_next');

        $this->assertSame('eleven_next', VoiceoverSettings::fromArray([])->model);
        $this->assertSame('eleven_v4', VoiceoverSettings::fromArray(['model' => 'eleven_v4'])->model);
    }

    public function test_the_stability_of_a_brand_is_sent_as_it_is_whatever_the_model(): void
    {
        $this->assertSame(0.7, VoiceoverSettings::fromArray(['stability' => 0.7])->voiceSettings()['stability']);
        $this->assertSame(0.55, VoiceoverSettings::fromArray([])->voiceSettings()['stability']);
    }
}
