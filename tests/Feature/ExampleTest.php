<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The original app bounced signed-out visitors to /login (src/proxy.ts);
     * "/" is behind the auth middleware, so an anonymous GET redirects.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
}
