<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_home_renders_without_an_account(): void
    {
        $this->withoutVite();
        $this->get('/')->assertOk()->assertSee('کاردرست');
    }
}
