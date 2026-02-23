<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategorySeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_defaults_creates_categories_in_french_when_accept_language_is_fr()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withHeaders(['Accept-Language' => 'fr'])
            ->postJson('/api/v1/categories/seed-defaults');

        $response->assertStatus(201);

        $categories = $response->json('data.categories');
        $names = array_column($categories, 'name');

        $this->assertContains('Salaire', $names);
        $this->assertContains('Alimentation et restauration', $names);
        $this->assertContains('Autres dépenses', $names);
        $this->assertNotContains('Salary', $names);
    }

    public function test_seed_defaults_creates_categories_in_english_when_accept_language_is_en()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withHeaders(['Accept-Language' => 'en'])
            ->postJson('/api/v1/categories/seed-defaults');

        $response->assertStatus(201);

        $categories = $response->json('data.categories');
        $names = array_column($categories, 'name');

        $this->assertContains('Salary', $names);
        $this->assertContains('Food & Dining', $names);
        $this->assertContains('Other Expenses', $names);
    }

    public function test_seed_defaults_creates_categories_in_default_locale_without_accept_language()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/categories/seed-defaults');

        $response->assertStatus(201);

        $categories = $response->json('data.categories');
        $names = array_column($categories, 'name');

        $this->assertContains('Salary', $names);
    }
}
