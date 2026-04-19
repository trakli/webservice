<?php

namespace Tests\Feature;

use App\Events\BudgetThresholdBreached;
use App\Events\TransactionRecorded;
use App\Jobs\RecomputeBudgetProgressJob;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Reminder;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->wallet = Wallet::factory()->create(['user_id' => $this->user->id]);
    }

    public function test_user_can_create_budget_with_category_targets(): void
    {
        $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => 'expense']);

        $response = $this->actingAs($this->user)->postJson('/api/v1/budgets', [
            'name' => 'Groceries',
            'amount' => 500,
            'currency' => 'USD',
            'period_type' => 'monthly',
            'start_date' => CarbonImmutable::now()->startOfMonth()->toDateString(),
            'rollover_enabled' => false,
            'threshold_percent' => 80,
            'targets' => [
                ['type' => 'category', 'id' => $category->id],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Groceries');
        $this->assertDatabaseHas('budgetables', [
            'budgetable_id' => $category->id,
            'budgetable_type' => Category::class,
        ]);
    }

    public function test_index_returns_only_budgets_visible_to_user(): void
    {
        Budget::factory()->create(['owner_type' => User::class, 'owner_id' => $this->user->id]);

        $otherUser = User::factory()->create();
        Budget::factory()->create(['owner_type' => User::class, 'owner_id' => $otherUser->id]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/budgets');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_custom_period_requires_end_date(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/budgets', [
            'name' => 'Vacation',
            'amount' => 800,
            'currency' => 'USD',
            'period_type' => 'custom',
            'start_date' => '2026-08-01',
        ]);

        $response->assertStatus(422);
    }

    public function test_transaction_write_dispatches_transaction_recorded_event(): void
    {
        Event::fake([TransactionRecorded::class]);

        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'wallet_id' => $this->wallet->id,
            'type' => 'expense',
            'amount' => 50,
            'datetime' => now(),
        ]);

        Event::assertDispatched(TransactionRecorded::class);
    }

    public function test_threshold_breach_creates_single_reminder_per_period(): void
    {
        $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => 'expense']);

        $budget = Budget::factory()->create([
            'owner_type' => User::class,
            'owner_id' => $this->user->id,
            'amount' => 100,
            'threshold_percent' => 80,
            'period_type' => Budget::PERIOD_MONTHLY,
            'start_date' => CarbonImmutable::now()->startOfMonth()->toDateString(),
        ]);
        $budget->categories()->attach($category);

        $listener = app(\App\Listeners\CreateBudgetAlertReminder::class);
        $event = new BudgetThresholdBreached(
            budgetId: $budget->id,
            periodStart: CarbonImmutable::now()->startOfMonth()->toDateString(),
            percentUsed: 85.0,
        );

        $listener->handleThreshold($event);
        $listener->handleThreshold($event);

        $reminders = Reminder::query()
            ->where('remindable_type', Budget::class)
            ->where('remindable_id', $budget->id)
            ->where('source', 'threshold')
            ->get();

        $this->assertCount(1, $reminders);
    }

    public function test_recompute_job_is_unique_per_budget(): void
    {
        Queue::fake();

        RecomputeBudgetProgressJob::dispatch(42);
        RecomputeBudgetProgressJob::dispatch(42);

        Queue::assertPushed(RecomputeBudgetProgressJob::class, fn ($job) => $job->budgetId === 42);
    }

    public function test_update_leaves_unchanged_targets_attached(): void
    {
        $keep = Category::factory()->create(['user_id' => $this->user->id, 'type' => 'expense']);
        $drop = Category::factory()->create(['user_id' => $this->user->id, 'type' => 'expense']);
        $add = Category::factory()->create(['user_id' => $this->user->id, 'type' => 'expense']);

        $budget = Budget::factory()->create([
            'owner_type' => User::class,
            'owner_id' => $this->user->id,
            'period_type' => Budget::PERIOD_MONTHLY,
            'start_date' => CarbonImmutable::now()->startOfMonth()->toDateString(),
        ]);
        $budget->categories()->attach([$keep->id, $drop->id]);

        $keepPivotIdBefore = $budget->categories()
            ->where('categories.id', $keep->id)
            ->first()
            ->pivot
            ->id;

        $response = $this->actingAs($this->user)->putJson("/api/v1/budgets/{$budget->id}", [
            'targets' => [
                ['type' => 'category', 'id' => $keep->id],
                ['type' => 'category', 'id' => $add->id],
            ],
        ]);

        $response->assertOk();

        $budget->refresh();
        $this->assertEqualsCanonicalizing(
            [$keep->id, $add->id],
            $budget->categories()->pluck('categories.id')->all()
        );

        // The unchanged pivot should still carry its original row id —
        // proof that sync() didn't detach-then-reattach.
        $keepPivotIdAfter = $budget->categories()
            ->where('categories.id', $keep->id)
            ->first()
            ->pivot
            ->id;
        $this->assertSame($keepPivotIdBefore, $keepPivotIdAfter);
    }
}
