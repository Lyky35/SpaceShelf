<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guard the Reverb WebSocket origin restrictions.
 */
class WebsocketConfigTest extends TestCase
{
    /**
     * Test that Reverb never accepts connections from a wildcard origin and
     * defaults to the game host instead (upstream #1649).
     */
    public function testReverbDoesNotAcceptWildcardOrigins(): void
    {
        $origins = config('reverb.apps.apps')[0]['allowed_origins'];

        $this->assertNotEmpty($origins);
        $this->assertNotContains('*', $origins, 'Reverb must not accept connections from arbitrary origins');
        $this->assertContains(
            (string) parse_url((string) config('app.url'), PHP_URL_HOST),
            $origins,
            'Reverb must allow connections from the host the game is served from'
        );
    }
}
