<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Whilesmart\ModelConfiguration\Enums\ConfigValueType;

class MyselfTransferTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private $myself;

    private $source;

    private $destination;

    public function test_paying_yourself_moves_money_between_wallets(): void
    {
        $response = $this->record([
            'party_id' => $this->myself->id,
            'from_wallet_id' => $this->source->id,
        ])->assertCreated();

        $this->assertNotNull($response->json('data.transfer_id'));
        $this->assertSame(800.0, (float) $this->source->fresh()->balance);
        $this->assertSame(200.0, (float) $this->destination->fresh()->balance);
    }

    public function test_the_request_can_refuse_the_conversion(): void
    {
        $response = $this->record([
            'party_id' => $this->myself->id,
            'from_wallet_id' => $this->source->id,
            'convert_myself_to_transfer' => false,
        ])->assertCreated();

        $this->assertNull($response->json('data.transfer_id'));
        $this->assertSame(1000.0, (float) $this->source->fresh()->balance);
    }

    public function test_turning_the_default_off_leaves_it_an_ordinary_transaction(): void
    {
        $this->user->setConfigValue('transfer-myself-transactions', false, ConfigValueType::Boolean);

        $response = $this->record([
            'party_id' => $this->myself->id,
            'from_wallet_id' => $this->source->id,
        ])->assertCreated();

        $this->assertNull($response->json('data.transfer_id'));
    }

    public function test_a_conversion_without_a_source_wallet_is_refused(): void
    {
        $this->record(['party_id' => $this->myself->id])
            ->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors' => ['from_wallet_id']]);
    }

    public function test_any_other_party_is_untouched(): void
    {
        $other = $this->user->parties()->create(['name' => 'Shop', 'type' => 'business']);

        $response = $this->record([
            'party_id' => $other->id,
            'from_wallet_id' => $this->source->id,
        ])->assertCreated();

        $this->assertNull($response->json('data.transfer_id'));
        $this->assertSame(1000.0, (float) $this->source->fresh()->balance);
    }

    public function test_a_party_can_be_created_as_myself(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/parties', ['name' => 'Me', 'type' => 'myself'])
            ->assertSuccessful()
            ->assertJsonPath('data.type', 'myself');
    }

    private function record(array $overrides)
    {
        return $this->actingAs($this->user)->postJson('/api/v1/transactions', array_merge([
            'type' => 'income',
            'amount' => 200,
            'wallet_id' => $this->destination->id,
            'datetime' => now()->toIso8601String(),
        ], $overrides));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->myself = $this->user->parties()->create(['name' => 'Me', 'type' => 'myself']);
        $this->source = $this->user->wallets()->create(['name' => 'Cash', 'balance' => 1000]);
        $this->destination = $this->user->wallets()->create(['name' => 'Bank', 'balance' => 0]);
    }
}
