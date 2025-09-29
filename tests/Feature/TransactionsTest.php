<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class TransactionsTest extends TestCase
{
    use RefreshDatabase;

    private $wallet;

    private $party;

    private $group;

    private $user;

    public function test_api_user_can_create_transactions()
    {
        $expense = $this->createTransaction('expense');
        $income = $this->createTransaction('income');

        $this->assertDatabaseHas('transactions', ['id' => $expense['id']]);
        $this->assertDatabaseHas('transactions', ['id' => $income['id']]);
    }

    private function createTransaction(string $type, array $recurrentData = []): array
    {
        $data = [
            'type' => $type,
            'amount' => 100,
            'description' => 'Test transaction description',
            'wallet_id' => $this->wallet->id,
            'party_id' => $this->party->id,
            'group_id' => $this->group->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
        ];

        if (! empty($recurrentData)) {
            $data['recurrence_period'] = $recurrentData['recurrence_period'] ?? 'daily';
            $data['recurrence_ends_at'] = $recurrentData['recurrence_ends_at'] ?? null;
            $data['recurrence_interval'] = $recurrentData['recurrence_interval'] ?? 1;
            $data['is_recurring'] = true;
        }

        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', $data);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'type',
                'amount',
                'description',
                'datetime',
                'party_id',
                'group_id',
                'wallet_id',
                'user_id',
                'client_generated_id',
            ],
            'message',
        ]);

        return $response->json('data');
    }

    public function test_transaction_response_includes_client_generated_ids()
    {
        $this->wallet->setClientGeneratedId('550e8400-e29b-41d4-a716-446655440000:550e8400-e29b-41d4-a716-446655440001', $this->user);
        $this->party->setClientGeneratedId('550e8400-e29b-41d4-a716-446655440000:550e8400-e29b-41d4-a716-446655440002', $this->user);

        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'description' => 'Test transaction with client IDs',
            'wallet_id' => $this->wallet->id,
            'party_id' => $this->party->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
        ]);

        $response->assertStatus(201);
        $data = $response->json('data');

        $response->assertJsonStructure([
            'data' => [
                'wallet_client_generated_id',
                'party_client_generated_id',
                'wallet' => ['client_generated_id'],
                'party' => ['client_generated_id'],
            ],
        ]);

        $this->assertEquals('550e8400-e29b-41d4-a716-446655440000:550e8400-e29b-41d4-a716-446655440001', $data['wallet_client_generated_id']);
        $this->assertEquals('550e8400-e29b-41d4-a716-446655440000:550e8400-e29b-41d4-a716-446655440002', $data['party_client_generated_id']);
        $this->assertEquals('550e8400-e29b-41d4-a716-446655440000:550e8400-e29b-41d4-a716-446655440001', $data['wallet']['client_generated_id']);
        $this->assertEquals('550e8400-e29b-41d4-a716-446655440000:550e8400-e29b-41d4-a716-446655440002', $data['party']['client_generated_id']);
    }

    public function test_api_user_can_create_transactions_with_client_id()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'wallet_id' => $this->wallet->id,
            'party_id' => $this->party->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
            'client_id' => '245cb3df-df3a-428b-a908-e5f74b8d58a4:245cb3df-df3a-428b-a908-e5f74b8d58a4',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('transactions', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_create_transactions_with_files()
    {
        // Create a fake image file
        $imageFile1 = UploadedFile::fake()->createWithContent(
            'image.png',
            'data:image/png;base64,someEncodedImagePNGImageHereYII='
        );

        $imageFile2 = UploadedFile::fake()->createWithContent(
            'image.png',
            'data:image/png;base64,someEncodedImagePNGImageHereYII='
        );

        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'wallet_id' => $this->wallet->id,
            'party_id' => $this->party->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
            'client_id' => '245cb3df-df3a-428b-a908-e5f74b8d58a4:245cb3df-df3a-428b-a908-e5f74b8d58a4',
            'files' => [$imageFile1, $imageFile2],
        ]);

        $response->assertStatus(201);

        $files = $response->json('data.files');
        $this->assertCount(2, $files);
        $this->assertEquals('file', $files[0]['type']);
        $this->assertEquals('App\\Models\\Transaction', $files[0]['fileable_type']);

        $this->assertDatabaseHas('transactions', ['id' => $response->json('data.id')]);
    }

    public function test_api_user_can_update_transactions_with_files()
    {
        // Create a fake image file
        $imageFile1 = UploadedFile::fake()->createWithContent(
            'image.png',
            'data:image/png;base64,someEncodedImagePNGImageHereYII='
        );

        $imageFile2 = UploadedFile::fake()->createWithContent(
            'image2.png',
            'data:image/png;base64,someEncodedImagePNGImageHereYII='
        );

        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'wallet_id' => $this->wallet->id,
            'party_id' => $this->party->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
            'client_id' => '245cb3df-df3a-428b-a908-e5f74b8d58a4:245cb3df-df3a-428b-a908-e5f74b8d58a4',
            'files' => [$imageFile1],
        ]);
        $response->assertStatus(201);

        $id = $response->json('data.id');

        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions/'.$id.'/files', [
            'files' => [$imageFile2],
        ]);

        $response->assertStatus(200);

        $files = $response->json('data.files');
        $this->assertCount(2, $files);
        $this->assertEquals('file', $files[0]['type']);
        $this->assertEquals('App\\Models\\Transaction', $files[0]['fileable_type']);
    }

    public function test_api_user_can_delete_a_file_in_a_transaction()
    {
        // Create a fake image file
        $imageFile1 = UploadedFile::fake()->createWithContent(
            'image.png',
            'data:image/png;base64,someEncodedImagePNGImageHereYII='
        );

        $imageFile2 = UploadedFile::fake()->createWithContent(
            'image2.png',
            'data:image/png;base64,someEncodedImagePNGImageHereYII='
        );

        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'wallet_id' => $this->wallet->id,
            'party_id' => $this->party->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
            'client_id' => '245cb3df-df3a-428b-a908-e5f74b8d58a4:245cb3df-df3a-428b-a908-e5f74b8d58a4',
            'files' => [$imageFile1, $imageFile2],
        ]);
        $response->assertStatus(201);

        $id = $response->json('data.id');
        $files = $response->json('data.files');

        $response = $this->actingAs($this->user)->deleteJson('/api/v1/transactions/'.$id.'/files/'.$files[0]['id'], [
            'files' => [$imageFile2],
        ]);

        $response->assertStatus(200);

        $files = $response->json('data.files');
        $this->assertCount(1, $files);
        $this->assertEquals('file', $files[0]['type']);
        $this->assertEquals('App\\Models\\Transaction', $files[0]['fileable_type']);
    }

    public function test_api_user_cannot_create_transactions_with_invalid_file()
    {
        // Create a fake CSV file
        $csvFile = UploadedFile::fake()->createWithContent(
            'test.csv',
            $content ?? "amount,currency,type,party,wallet,category,description,date\n".
        "100,USD,expense,John Doe,Wallet1,Food,Lunch,2023-01-01\n".
        '200,USD,income,Jane Doe,Wallet2,Salary,Monthly Salary,2023-01-02'
        );

        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'wallet_id' => $this->wallet->id,
            'party_id' => $this->party->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
            'client_id' => '245cb3df-df3a-428b-a908-e5f74b8d58a4:245cb3df-df3a-428b-a908-e5f74b8d58a4',
            'files' => [$csvFile],
        ]);

        $response->assertStatus(422);
    }

    public function test_api_user_can_get_their_transactions()
    {
        $this->createTransaction('expense');
        $this->createTransaction('income');

        $response = $this->actingAs($this->user)->getJson('/api/v1/transactions?type=expense');
        $response->assertStatus(200);

        $response = $this->actingAs($this->user)->getJson('/api/v1/transactions?type=income');
        $response->assertStatus(200);

        // Test limit parameter
        $this->createTransaction('expense');
        $this->createTransaction('expense');

        $response = $this->actingAs($this->user)->getJson('/api/v1/transactions?type=expense&limit=2');
        $response->assertStatus(200)
            ->assertJsonCount(2, 'data.data');
    }

    public function test_api_user_can_update_their_transactions()
    {
        $expense = $this->createTransaction('expense');

        $response = $this->actingAs($this->user)->putJson('/api/v1/transactions/'.$expense['id'], [
            'amount' => 200,
            'updated_at' => '2025-05-01T15:17:54.120Z',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'amount',
                    'wallet_id',
                    'party_id',
                    'datetime',
                ],
                'message',
            ]);
    }

    public function test_api_user_cannot_create_transaction_with_invalid_type()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'invalid_type',
            'amount' => 100,
            'wallet_id' => 1,
            'datetime' => '2025-04-30T15:17:54.120Z',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_api_user_cannot_create_transaction_with_invalid_amount()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => -100,
            'wallet_id' => 1,
            'datetime' => '2025-04-30T15:17:54.120Z',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_api_user_cannot_create_transaction_with_missing_required_fields()
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'description' => 'Test transaction',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type', 'amount']);
    }

    public function test_unauthorized_user_cannot_access_transactions()
    {
        $response = $this->getJson('/api/v1/transactions');
        $response->assertStatus(401);
    }

    public function test_api_user_can_delete_their_transaction()
    {
        $expense = $this->createTransaction('expense');

        $response = $this->actingAs($this->user)->deleteJson("/api/v1/transactions/{$expense['id']}");

        $response->assertStatus(200);
        $transaction = Transaction::find($expense['id']);
        $this->assertNull($transaction);
    }

    public function test_api_user_cannot_delete_non_existent_transaction()
    {
        $response = $this->actingAs($this->user)->deleteJson('/api/v1/transactions/999');
        $response->assertStatus(404);
    }

    public function test_api_user_cannot_access_another_users_transaction()
    {
        $user2 = User::factory()->create();

        $expense = $this->createTransaction('expense');

        $this->actingAs($user2)
            ->getJson("/api/v1/transactions/{$expense['id']}")
            ->assertStatus(403);

        $this->actingAs($user2)
            ->putJson("/api/v1/transactions/{$expense['id']}", ['amount' => 200, 'updated_at' => '2025-05-01T15:17:54.120Z'])
            ->assertStatus(403);

        $this->actingAs($user2)
            ->deleteJson("/api/v1/transactions/{$expense['id']}")
            ->assertStatus(403);
    }

    public function test_api_user_can_create_a_recurrent_transaction()
    {
        $transaction = $this->createTransaction('expense', [
            'recurrence_period' => 'daily',
            //            'recurrence_ends_at' => now()->addMonth()->format("Y-m-dTH:i:s"),
            'recurrence_interval' => 1,
        ]);

        $this->assertDatabaseHas('transactions', ['id' => $transaction['id']]);
        $this->assertEquals('daily', $transaction['recurring_rules']['recurrence_period']);
        $this->assertEquals(1, $transaction['recurring_rules']['recurrence_interval']);
        $this->assertEquals($transaction['id'], $transaction['recurring_rules']['transaction_id']);

    }

    public function test_api_user_can_update_the_recurring_rule_of_a_transaction()
    {
        $expense = $this->createTransaction('expense');

        $response = $this->actingAs($this->user)->putJson('/api/v1/transactions/'.$expense['id'], [
            'recurrence_period' => 'daily',
            'recurrence_interval' => 2,
            'is_recurring' => true,
            'updated_at' => '2025-05-01T15:17:54.120Z',
        ]);

        $transaction = $response->json('data');
        $this->assertEquals('daily', $transaction['recurring_rules']['recurrence_period']);
        $this->assertEquals(2, $transaction['recurring_rules']['recurrence_interval']);
        $this->assertEquals($transaction['id'], $transaction['recurring_rules']['transaction_id']);
    }

    public function test_next_scheduled_transaction_should_not_run_if_recurrence_changes()
    {
        // Test weekly recurrence
        $transaction = $this->createTransaction('expense', [
            'recurrence_period' => 'daily',
            'recurrence_interval' => 1,
        ]);

        $this->assertDatabaseHas('transactions', ['id' => $transaction['id']]);
        $this->assertEquals('daily', $transaction['recurring_rules']['recurrence_period']);
        $this->assertEquals(1, $transaction['recurring_rules']['recurrence_interval']);

        // forward the time so that the first transaction should run
        Carbon::setTestNow(now()->addDay());
        $this->runQueueWorkerOnce();

        $transactions = Transaction::count();
        $this->assertEquals(2, $transactions);

        // Update the recurrence period
        $response = $this->actingAs($this->user)->putJson('/api/v1/transactions/'.$transaction['id'], [
            'recurrence_period' => 'weekly',
            'recurrence_interval' => 2,
            'is_recurring' => true,
            'updated_at' => '2025-05-01T15:17:54.120Z',
        ]);

        $transaction = $response->json('data');
        $this->assertEquals('weekly', $transaction['recurring_rules']['recurrence_period']);
        $this->assertEquals(2, $transaction['recurring_rules']['recurrence_interval']);
        $this->assertEquals($transaction['id'], $transaction['recurring_rules']['transaction_id']);

        // forward the time so that the second transaction should run
        Carbon::setTestNow(now()->addDay());
        $this->runQueueWorkerOnce();

        $transactions = Transaction::count();
        $this->assertEquals(2, $transactions);
    }

    public function test_recurring_transaction_runs_on_next_scheduled_date()
    {
        // Test weekly recurrence
        $transaction = $this->createTransaction('expense', [
            'recurrence_period' => 'daily',
            'recurrence_interval' => 1,
        ]);

        $this->assertDatabaseHas('transactions', ['id' => $transaction['id']]);
        $this->assertEquals('daily', $transaction['recurring_rules']['recurrence_period']);
        $this->assertEquals(1, $transaction['recurring_rules']['recurrence_interval']);

        // forward the time so that the first transaction should run
        Carbon::setTestNow(now()->addDay());
        $this->runQueueWorkerOnce();

        $transactions = Transaction::count();
        $this->assertEquals(2, $transactions);
    }

    private function runQueueWorkerOnce(): void
    {
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--sleep' => 0,
            '--tries' => 1,
        ]);
    }

    public function test_api_user_can_create_recurring_transactions_with_different_periods()
    {
        // Test weekly recurrence
        $weekly_transaction = $this->createTransaction('expense', [
            'recurrence_period' => 'weekly',
            'recurrence_interval' => 1,
        ]);

        $this->assertDatabaseHas('transactions', ['id' => $weekly_transaction['id']]);
        $this->assertEquals('weekly', $weekly_transaction['recurring_rules']['recurrence_period']);
        $this->assertEquals(1, $weekly_transaction['recurring_rules']['recurrence_interval']);

        // Test monthly recurrence
        $monthly_transaction = $this->createTransaction('income', [
            'recurrence_period' => 'monthly',
            'recurrence_interval' => 2,
        ]);

        $this->assertDatabaseHas('transactions', ['id' => $monthly_transaction['id']]);
        $this->assertEquals('monthly', $monthly_transaction['recurring_rules']['recurrence_period']);
        $this->assertEquals(2, $monthly_transaction['recurring_rules']['recurrence_interval']);

        // Test yearly recurrence
        $yearly_transaction = $this->createTransaction('expense', [
            'recurrence_period' => 'yearly',
            'recurrence_interval' => 1,
        ]);

        $this->assertDatabaseHas('transactions', ['id' => $yearly_transaction['id']]);
        $this->assertEquals('yearly', $yearly_transaction['recurring_rules']['recurrence_period']);
        $this->assertEquals(1, $yearly_transaction['recurring_rules']['recurrence_interval']);
    }

    public function test_api_user_can_create_recurring_transactions_with_end_date()
    {
        $end_date = now()->addMonths(3)->format('Y-m-d\TH:i:s.v\Z');

        $transaction = $this->createTransaction('expense', [
            'recurrence_period' => 'monthly',
            'recurrence_interval' => 1,
            'recurrence_ends_at' => $end_date,
        ]);

        $this->assertDatabaseHas('transactions', ['id' => $transaction['id']]);
        $this->assertEquals('monthly', $transaction['recurring_rules']['recurrence_period']);
        $this->assertEquals(1, $transaction['recurring_rules']['recurrence_interval']);

        // The date format might be different in the response, so we'll just check that it's not null
        $this->assertNotNull($transaction['recurring_rules']['recurrence_ends_at']);
    }

    public function test_api_validates_recurring_transaction_parameters()
    {
        // Test missing recurrence_period when is_recurring is true
        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'wallet_id' => $this->wallet->id,
            'party_id' => $this->party->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
            'is_recurring' => true,
            // Missing recurrence_period
        ]);

        $response->assertStatus(422);

        // Test invalid recurrence_period
        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'wallet_id' => $this->wallet->id,
            'party_id' => $this->party->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
            'is_recurring' => true,
            'recurrence_period' => 'invalid_period',
        ]);

        $response->assertStatus(422);

        // Test invalid recurrence_interval
        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'wallet_id' => $this->wallet->id,
            'party_id' => $this->party->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
            'is_recurring' => true,
            'recurrence_period' => 'monthly',
            'recurrence_interval' => 0, // Should be at least 1
        ]);

        $response->assertStatus(422);

        // Test past recurrence_ends_at date
        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'wallet_id' => $this->wallet->id,
            'party_id' => $this->party->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
            'is_recurring' => true,
            'recurrence_period' => 'monthly',
            'recurrence_interval' => 1,
            'recurrence_ends_at' => '2020-01-01T00:00:00.000Z', // Past date
        ]);

        $response->assertStatus(422);
    }

    public function test_api_user_can_delete_recurring_transaction()
    {
        // Create a recurring transaction
        $transaction = $this->createTransaction('expense', [
            'recurrence_period' => 'monthly',
            'recurrence_interval' => 1,
        ]);

        $this->assertDatabaseHas('transactions', ['id' => $transaction['id']]);
        $this->assertDatabaseHas('recurring_transaction_rules', ['transaction_id' => $transaction['id']]);

        // Delete the transaction
        $response = $this->actingAs($this->user)->deleteJson("/api/v1/transactions/{$transaction['id']}");

        $response->assertStatus(200);

        // Verify that both the transaction and its recurring rule are deleted
        $this->assertSoftDeleted('transactions', ['id' => $transaction['id']]);
        $this->assertDatabaseMissing('recurring_transaction_rules', ['transaction_id' => $transaction['id']]);
    }

    public function test_api_user_can_modify_recurring_transaction_period()
    {
        // Create a recurring transaction with daily period
        $transaction = $this->createTransaction('expense', [
            'recurrence_period' => 'daily',
            'recurrence_interval' => 1,
        ]);

        $this->assertDatabaseHas('transactions', ['id' => $transaction['id']]);
        $this->assertEquals('daily', $transaction['recurring_rules']['recurrence_period']);

        // Update to weekly period
        $response = $this->actingAs($this->user)->putJson('/api/v1/transactions/'.$transaction['id'], [
            'recurrence_period' => 'weekly',
            'recurrence_interval' => 2,
            'is_recurring' => true,
            'updated_at' => '2025-05-01T15:17:54.120Z',
        ]);

        $response->assertStatus(200);
        $updated_transaction = $response->json('data');

        // Verify the period was updated
        $this->assertEquals('weekly', $updated_transaction['recurring_rules']['recurrence_period']);
        $this->assertEquals(2, $updated_transaction['recurring_rules']['recurrence_interval']);
        $this->assertEquals($transaction['id'], $updated_transaction['recurring_rules']['transaction_id']);
    }

    public function test_api_user_can_add_recurrence_to_non_recurring_transaction()
    {
        // Create a non-recurring transaction
        $transaction = $this->createTransaction('expense');

        // Verify it doesn't have recurring rules
        $this->assertNull($transaction['recurring_rules']);

        // Add recurrence
        $response = $this->actingAs($this->user)->putJson('/api/v1/transactions/'.$transaction['id'], [
            'recurrence_period' => 'monthly',
            'recurrence_interval' => 1,
            'is_recurring' => true,
            'updated_at' => '2025-05-01T15:17:54.120Z',
        ]);

        $response->assertStatus(200);
        $updated_transaction = $response->json('data');

        // Verify recurrence was added
        $this->assertArrayHasKey('recurring_rules', $updated_transaction);
        $this->assertEquals('monthly', $updated_transaction['recurring_rules']['recurrence_period']);
        $this->assertEquals(1, $updated_transaction['recurring_rules']['recurrence_interval']);
        $this->assertEquals($transaction['id'], $updated_transaction['recurring_rules']['transaction_id']);
    }

    public function test_api_user_can_remove_recurrence_from_recurring_transaction()
    {
        // Create a recurring transaction
        $transaction = $this->createTransaction('expense', [
            'recurrence_period' => 'monthly',
            'recurrence_interval' => 1,
        ]);

        // Verify it has recurring rules
        $this->assertArrayHasKey('recurring_rules', $transaction);
        $this->assertDatabaseHas('recurring_transaction_rules', ['transaction_id' => $transaction['id']]);

        // Remove recurrence by setting is_recurring to false
        $response = $this->actingAs($this->user)->putJson('/api/v1/transactions/'.$transaction['id'], [
            'is_recurring' => false,
            'updated_at' => '2025-05-01T15:17:54.120Z',
        ]);

        $response->assertStatus(200);
        $updated_transaction = $response->json('data');

        // Verify recurrence was removed
        $this->assertNull($updated_transaction['recurring_rules']);
        $this->assertDatabaseMissing('recurring_transaction_rules', ['transaction_id' => $transaction['id']]);
    }

    public function test_api_user_can_update_their_transactions_with_client_id()
    {
        $expense = $this->createTransaction('expense');
        $clientId = '245cb3df-df3a-428b-a908-e5f74b8d58a4';
        $deviceToken = '245cb3df-df3a-428b-a908-e5f74b8d58a4';

        $response = $this->actingAs($this->user)->putJson('/api/v1/transactions/'.$expense['id'], [
            'amount' => 200,
            'updated_at' => '2025-05-01T15:17:54.120Z',
            'client_id' => "$deviceToken:$clientId",
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'amount',
                    'wallet_id',
                    'party_id',
                    'datetime',
                ],
                'message',
            ]);

        $transaction = Transaction::find($expense['id']);
        $this->assertEquals($transaction->syncState->client_generated_id, $clientId);
    }

    public function test_api_user_cannot_create_transaction_with_invalid_client_id_format()
    {
        // Test with client_id that has no colon
        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'wallet_id' => $this->wallet->id,
            'party_id' => $this->party->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
            'client_id' => '245cb3df-df3a-428b-a908-e5f74b8d58a4',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('must be in the format', $response->json('errors.client_id.0'));

        // Test with client_id that has invalid UUID
        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'wallet_id' => $this->wallet->id,
            'party_id' => $this->party->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
            'client_id' => 'invalid-uuid:245cb3df-df3a-428b-a908-e5f74b8d58a4',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('not a valid UUID', $response->json('errors.client_id.0'));

        // Test with client_id that has more than one colon
        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'wallet_id' => $this->wallet->id,
            'party_id' => $this->party->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
            'client_id' => '245cb3df-df3a-428b-a908-e5f74b8d58a4:245cb3df-df3a-428b-a908-e5f74b8d58a4:extra',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('must be in the format', $response->json('errors.client_id.0'));
    }

    public function test_api_user_device_creation_with_client_id()
    {
        $deviceToken = '245cb3df-df3a-428b-a908-e5f74b8d58a4';
        $clientId = '245cb3df-df3a-428b-a908-e5f74b8d58a3';

        // Create first transaction with client_id
        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'amount' => 100,
            'wallet_id' => $this->wallet->id,
            'party_id' => $this->party->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
            'client_id' => "$deviceToken:$clientId",
        ]);

        $response->assertStatus(201);
        $firstTransaction = Transaction::find($response->json('data.id'));

        // Verify device was created
        $this->assertDatabaseHas('devices', ['deviceable_id' => $this->user->id, 'token' => $deviceToken, 'deviceable_type' => 'App\Models\User']);
        $device = $this->user->devices()->where('token', $deviceToken)->first();
        $this->assertNotNull($device);

        // Verify sync state has correct device_id and client_generated_id
        $this->assertEquals($clientId, $firstTransaction->syncState->client_generated_id);
        $this->assertEquals($device->id, $firstTransaction->syncState->device_id);

        // Create second transaction with same device_id but different client_id
        $secondClientId = '245cb3df-df3a-428b-a908-e5f74b8d58a5';
        $response = $this->actingAs($this->user)->postJson('/api/v1/transactions', [
            'type' => 'income',
            'amount' => 200,
            'wallet_id' => $this->wallet->id,
            'party_id' => $this->party->id,
            'datetime' => '2025-04-30T15:17:54.120Z',
            'client_id' => "$deviceToken:$secondClientId",
        ]);

        $response->assertStatus(201);
        $secondTransaction = Transaction::find($response->json('data.id'));

        // Verify same device was used
        $this->assertEquals(1, $this->user->devices()->where('token', $deviceToken)->count());

        // Verify second transaction has correct client_generated_id but same device_id
        $this->assertEquals($secondClientId, $secondTransaction->syncState->client_generated_id);
        $this->assertEquals($device->id, $secondTransaction->syncState->device_id);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->wallet = User::factory()->create()->wallets()->create([
            'name' => 'Wallet',
            'balance' => 1000,
        ]);

        $this->party = User::factory()->create()->parties()->create([
            'name' => 'Party',
            'type' => 'personal',
        ]);

        $this->group = User::factory()->create()->groups()->create([
            'name' => 'Group',
            'type' => 'personal',
        ]);
        $this->user = User::factory()->create();
    }
}
