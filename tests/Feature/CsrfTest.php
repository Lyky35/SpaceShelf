<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\IsolatedAccountTestCase;

/**
 * Test that certain actions require a CSRF token.
 */
class CsrfTest extends IsolatedAccountTestCase
{
    /**
     * Verify that issuing fastbuild requests without a CSRF token fails.
     *
     * A missing `_token` must be rejected the same way as a wrong one — the
     * controllers answer with a JSON "Invalid token." failure. They used to
     * crash with a TypeError (HTTP 500) because `hash_equals()` received null;
     * the token argument is now cast to string. Nothing gets queued either way.
     */
    public function testFastBuildQueuesWithoutCsrfFail(): void
    {
        // Resources: Missing: _token=' . csrf_token() . '
        $response = $this->get('/resources/add-buildrequest?&type=1&planet_id=' . $this->planetService->getPlanetId());
        $response->assertStatus(200);
        $response->assertJson(['success' => false, 'message' => 'Invalid token.']);

        // Facilities: Missing: _token=' . csrf_token() . '
        $response = $this->get('/facilities/add-buildrequest?&type=14&planet_id=' . $this->planetService->getPlanetId());
        $response->assertStatus(200);
        $response->assertJson(['success' => false, 'message' => 'Invalid token.']);

        // Research: Missing: _token=' . csrf_token() . '
        $response = $this->get('/research/add-buildrequest?&type=113&planet_id=' . $this->planetService->getPlanetId());
        $response->assertStatus(200);
        $response->assertJson(['success' => false, 'message' => 'Invalid token.']);

        // None of the rejected requests may have queued anything.
        $this->assertSame(
            0,
            DB::table('building_queues')->where('planet_id', $this->planetService->getPlanetId())->count()
        );
        $this->assertSame(
            0,
            DB::table('research_queues')->where('planet_id', $this->planetService->getPlanetId())->count()
        );
    }
}
