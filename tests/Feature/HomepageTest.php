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
            ->assertSee('name="long_url"', false)
            ->assertSee('Shorten URL');
    }
}
