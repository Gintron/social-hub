<?php

declare(strict_types=1);

namespace Tests\Feature\Legal;

use Tests\TestCase;

final class AppPageTest extends TestCase
{
    /**
     * The Google consent screen links to this page as the app's home page, so it must answer without a login
     * and say what the tool does, with the two YouTube scopes it asks for and the privacy policy it links to.
     */
    public function test_the_public_app_page_describes_the_tool_and_its_youtube_scopes_without_a_login(): void
    {
        $this->get('/aplikacija')
            ->assertOk()
            ->assertSee('Social Hub')
            ->assertSee('youtube.upload')
            ->assertSee('youtube.readonly')
            ->assertSee('/privatnost');
    }
}
