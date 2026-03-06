<?php

namespace Tests\Feature;

use App\Events\AccountDeleted;
use App\Models\Category;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);
    }

    public function test_user_can_delete_own_account()
    {
        Event::fake([AccountDeleted::class]);

        $response = $this->actingAs($this->user)->deleteJson('/api/v1/account', [
            'confirm_delete' => true,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Account deleted successfully.',
            ]);

        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
        Event::assertDispatched(AccountDeleted::class);
    }

    public function test_user_can_delete_account_with_reason()
    {
        Event::fake([AccountDeleted::class]);

        $response = $this->actingAs($this->user)->deleteJson('/api/v1/account', [
            'confirm_delete' => true,
            'reason' => 'No longer need the service',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);

        Event::assertDispatched(AccountDeleted::class, function ($event) {
            return $event->reason === 'No longer need the service';
        });
    }

    public function test_user_cannot_delete_account_without_confirmation()
    {
        $response = $this->actingAs($this->user)->deleteJson('/api/v1/account', []);

        $response->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    public function test_user_cannot_delete_with_false_confirmation()
    {
        $response = $this->actingAs($this->user)->deleteJson('/api/v1/account', [
            'confirm_delete' => false,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    public function test_account_deletion_cascades_related_data()
    {
        Event::fake([AccountDeleted::class]);

        Wallet::factory()->count(2)->create(['user_id' => $this->user->id]);
        Category::factory()->count(3)->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->deleteJson('/api/v1/account', [
            'confirm_delete' => true,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('wallets', ['user_id' => $this->user->id]);
        $this->assertDatabaseMissing('categories', ['user_id' => $this->user->id]);
    }

    public function test_account_deletion_revokes_tokens()
    {
        Event::fake([AccountDeleted::class]);

        $this->user->createToken('test-token');
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $this->user->id]);

        $this->actingAs($this->user)->deleteJson('/api/v1/account', [
            'confirm_delete' => true,
        ]);

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $this->user->id]);
    }

    public function test_unauthenticated_user_cannot_delete_account()
    {
        $response = $this->deleteJson('/api/v1/account', [
            'confirm_delete' => true,
        ]);

        $response->assertStatus(401);
    }
}
