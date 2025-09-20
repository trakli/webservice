<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unhandled_exception_returns_json_response_for_api_requests()
    {
        Config::set('app.debug', false);

        $this->app['router']->get('/api/test-500-error', function () {
            throw new \Exception('Test unhandled exception');
        });

        $response = $this->getJson('/api/test-500-error');

        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
                'message' => 'An error occurred while processing your request.',
                'errors' => [],
            ]);
    }

    public function test_unhandled_exception_returns_debug_info_when_debug_enabled()
    {
        Config::set('app.debug', true);

        $this->app['router']->get('/api/test-500-error-debug', function () {
            throw new \RuntimeException('Test debug exception');
        });

        $response = $this->getJson('/api/test-500-error-debug');

        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
                'message' => 'Test debug exception',
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'errors' => [
                    'exception',
                    'file',
                    'line',
                ],
            ]);

        $this->assertEquals('RuntimeException', $response->json('errors.exception'));
    }

    public function test_database_exception_returns_proper_api_error()
    {
        Config::set('app.debug', false);

        $this->app['router']->get('/api/test-db-error', function () {
            throw new \Illuminate\Database\QueryException(
                'select * from non_existent_table',
                [],
                new \PDOException('Table does not exist')
            );
        });

        $response = $this->getJson('/api/test-db-error');

        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
                'message' => 'An error occurred while processing your request.',
                'errors' => [],
            ]);
    }

    public function test_fatal_error_returns_proper_api_error()
    {
        Config::set('app.debug', false);

        $this->app['router']->get('/api/test-fatal-error', function () {
            throw new \ErrorException('Fatal error occurred');
        });

        $response = $this->getJson('/api/test-fatal-error');

        $response->assertStatus(500)
            ->assertJson([
                'success' => false,
                'message' => 'An error occurred while processing your request.',
                'errors' => [],
            ]);
    }

    public function test_authentication_exception_returns_proper_json()
    {
        $response = $this->getJson('/api/v1/categories');

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthenticated. Please log in to access this resource.',
                'errors' => [],
            ]);
    }

    public function test_existing_json_responses_are_not_overridden()
    {
        $this->app['router']->get('/api/test-existing-json', function () {
            return response()->json([
                'custom' => 'response',
                'status' => 'handled',
            ], 500);
        });

        $response = $this->getJson('/api/test-existing-json');

        $response->assertStatus(500)
            ->assertJson([
                'custom' => 'response',
                'status' => 'handled',
            ]);

        $this->assertArrayNotHasKey('success', $response->json());
    }
}
