<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlaidIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function plaid_transaction_sync_executes_real_trakli_write_path()
    {
        $plaidAccountClass = 'Trakli\Plaid\Models\PlaidAccount';
        $syncJobClass = 'Trakli\Plaid\Jobs\SyncPlaidTransactionsJob';

        if (!class_exists($plaidAccountClass) || !class_exists($syncJobClass)) {
            $this->markTestSkipped('Plaid plugin is not installed.');
        }

        // 1. Create a real host user
        $user = User::factory()->create();

        // 2. Create the Plaid account mapped to the real user
        $account = $plaidAccountClass::create([
            'owner_id' => $user->id,
            'owner_type' => User::class,
            'item_id' => 'host-item-123',
            'access_token' => 'access-sandbox-host-999',
            'is_active' => true,
        ]);

        // 3. Configure API variables
        config([
            'app.key' => 'base64:yhVb6eK76RwhjH0/8Q73hN9r/P6zZ44nZ2n495u03lI=',
            'plaid.client_id' => 'test-client-id',
            'plaid.client_secret' => 'test-secret',
            'plaid.webhook_url' => 'http://localhost/api/v1/plaid/webhook',
            'plaid.api_url' => 'https://sandbox.plaid.com',
        ]);

        // 4. Fake the Plaid API transactions sync response
        Http::fake([
            'https://sandbox.plaid.com/transactions/sync' => Http::response([
                'added' => [
                    [
                        'amount' => 50.00,
                        'iso_currency_code' => 'USD',
                        'personal_finance_category' => ['primary' => 'FOOD_AND_DRINK'],
                        'transaction_id' => 'tx-host-new-123',
                        'name' => 'Host Burger Diner',
                        'date' => '2026-06-20',
                    ]
                ],
                'modified' => [],
                'removed' => [],
                'next_cursor' => 'next-cursor-host-111',
                'has_more' => false,
            ]),
        ]);

        // 5. Run the background sync transactions job synchronously
        $job = new $syncJobClass('host-item-123');
        dispatch_sync($job);

        // 6. Assert the transaction was created in the host database
        $this->assertDatabaseHas('transactions', [
            'user_id' => $user->id,
            'amount' => 50.00,
            'description' => 'Host Burger Diner',
        ]);

        $transaction = Transaction::where('description', 'Host Burger Diner')->firstOrFail();

        // 7. Verify the dynamic config contains the external plaid_transaction_id
        $this->assertEquals('tx-host-new-123', $transaction->getConfigValue('plaid_transaction_id'));

        // 8. Verify the real Trakli write path observer executed and created a syncState
        $this->assertNotNull($transaction->syncState);
        $this->assertEquals(Transaction::class, $transaction->syncState->syncable_type);
        $this->assertEquals($transaction->id, $transaction->syncState->syncable_id);
    }
}
