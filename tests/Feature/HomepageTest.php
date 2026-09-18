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
            ->assertSee('Turn long links into')
            ->assertSee('id="shortener-form"', false)
            ->assertSee('data-endpoint="/api/v1/urls"', false)
            ->assertSee('name="long_url"', false)
            ->assertSee('id="long-url-error"', false)
            ->assertSee('Shorten URL')
            ->assertSee('id="short-url-result"', false);
    }
}
