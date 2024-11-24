<?php

namespace Tests\Unit;

use Tests\TestCase;

class ServerHomeTest extends TestCase
{
    /**
     * Base url is up and running
     *
     * @return void
     */
    public function test_hit_base_url_returns_200()
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
