<?php

namespace Tests\Feature;

use Tests\TestCase;

class SwaggerDocsTest extends TestCase
{
    public function test_swagger_ui_is_served_outside_production(): void
    {
        $response = $this->get('/docs/swagger');

        $response->assertOk();
        $response->assertSee('swagger-ui', false);
        $response->assertSee('/docs/api.json', false);
    }

    public function test_welcome_route_links_to_docs_outside_production(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertJsonFragment([]);
        $this->assertStringContainsString('/docs/swagger', $response->json('welcome'));
    }
}
