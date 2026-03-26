<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_root_page_shows_the_threadql_install_screen(): void
    {
        $version = config('app.threadql_version');

        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertSee('ThreadQL is installed and ready to use.')
            ->assertSee('Version '.$version)
            ->assertSee('/panel')
            ->assertSee('administer ThreadQL from the panel', false);
    }
}
