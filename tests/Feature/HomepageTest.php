<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomepageTest extends TestCase
{
    public function test_the_homepage_displays_the_url_shortener_form(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Shortly')
            ->assertSee('Skip to main content')
            ->assertSee('Turn long links into')
            ->assertSee('id="main-content"', false)
            ->assertSee('id="shortener-form"', false)
            ->assertSee('data-endpoint="/api/v1/urls"', false)
            ->assertSee('name="long_url"', false)
            ->assertSee('id="long-url-error"', false)
            ->assertSee('id="shortener-status"', false)
            ->assertSee('role="status"', false)
            ->assertSee('Shorten URL')
            ->assertSee('id="short-url-result"', false)
            ->assertSee('id="recent-links"', false)
            ->assertSee('Clear history')
            ->assertSee('aria-live="polite"', false);
    }
}
