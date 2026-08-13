<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_root_sends_guests_to_login(): void
    {
        // Da autenticati la radice smista per permesso (mappa, portale o
        // guida): il caso è coperto in WebAuthTest
        $this->get('/')->assertRedirect('/login');
    }
}
