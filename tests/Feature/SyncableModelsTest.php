<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Group;
use App\Models\Party;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncableModelsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function transaction_creates_a_sync_state_on_creation()
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id]);

        /** @var Transaction */
        $transaction = Transaction::factory()->create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
        ]);

        $this->assertNotNull($transaction->syncState);
        $this->assertEquals($transaction->syncState->syncable_type, Transaction::class);
        $this->assertEquals($transaction->syncState->syncable_id, $transaction->id);
    }

    /** @test */
    public function category_creates_a_sync_state_on_creation()
    {
        $category = Category::factory()->create([
            'user_id' => User::factory()->create()->id,
        ]);

        $this->assertNotNull($category->syncState);
        $this->assertEquals($category->syncState->syncable_type, Category::class);
        $this->assertEquals($category->syncState->syncable_id, $category->id);
    }

    /** @test */
    public function party_creates_a_sync_state_on_creation()
    {
        $party = Party::factory()->create([
            'user_id' => User::factory()->create()->id,
        ]);

        $this->assertNotNull($party->syncState);
        $this->assertEquals($party->syncState->syncable_type, Party::class);
        $this->assertEquals($party->syncState->syncable_id, $party->id);
    }

    /** @test */
    public function group_creates_a_sync_state_on_creation()
    {
        $group = Group::factory()->create([
            'user_id' => User::factory()->create()->id,
        ]);

        $this->assertNotNull($group->syncState);
        $this->assertEquals($group->syncState->syncable_type, Group::class);
        $this->assertEquals($group->syncState->syncable_id, $group->id);
    }

    /** @test */
    public function transfer_creates_a_sync_state_on_creation()
    {
        $transfer = Transfer::factory()->create([
            'user_id' => User::factory()->create()->id,
        ]);

        $this->assertNotNull($transfer->syncState);
        $this->assertEquals($transfer->syncState->syncable_type, Transfer::class);
        $this->assertEquals($transfer->syncState->syncable_id, $transfer->id);
    }

    /** @test */
    public function user_can_not_update_a_transaction_if_the_updated_at_is_less_than_the_created_at()
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id]);

        /** @var Transaction */
        $transaction = Transaction::factory()->create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
        ]);

        $response = $this->actingAs($user)->putJson('/api/v1/transactions/'.$transaction['id'], [
            'amount' => 200,
            'updated_at' => '2020-05-01T15:17:54.120Z',
        ]);

        $response->assertStatus(400);
        $message = $response->json()['message'];

        $this->assertEquals('The updated at date is less than the created at date', $message);

    }
}
