<?php

namespace Database\Seeders;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Group;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Workspace;
use App\Services\WorkspaceFinance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Whilesmart\Workspaces\Models\WorkspaceInvitation;

class SharedSpaceSeeder
{
    public ?string $invitationUrl = null;

    private array $categories = ['Groceries', 'Utilities', 'Household', 'Kids'];

    private array $entries = [
        [1, 'Groceries', 'owner', 86.40, 'Weekly groceries'],
        [3, 'Utilities', 'owner', 64.20, 'Electricity bill'],
        [5, 'Household', 'member', 23.90, 'Cleaning supplies'],
        [8, 'Groceries', 'member', 54.75, 'Market run'],
        [11, 'Kids', 'owner', 35.00, 'School trip'],
        [14, 'Groceries', 'owner', 92.10, 'Weekly groceries'],
        [16, 'Utilities', 'member', 39.99, 'Home internet'],
        [19, 'Household', 'owner', 48.60, 'New towels'],
        [21, 'Groceries', 'member', 61.30, 'Weekly groceries'],
        [24, 'Kids', 'member', 18.50, 'Swimming lesson'],
        [27, 'Groceries', 'owner', 44.80, 'Top-up shop'],
    ];

    public function run(User $owner, User $member, string $invitee): Workspace
    {
        $workspace = $owner->createWorkspace('Fischer household', [
            'type' => 'team', 'description' => 'Bills and groceries we share at home', 'metadata' => ['kind' => 'family'],
        ]);
        if (! app(WorkspaceFinance::class)->eligible($workspace)) {
            return $workspace;
        }
        $member->joinWorkspace($workspace, 'member');

        $wallet = new Wallet();
        $wallet->forceFill(['name' => 'Household account', 'type' => 'bank', 'currency' => 'EUR', 'balance' => 1200,
            'description' => 'Joint account for the house', 'workspace_id' => $workspace->id, 'user_id' => null,
            'created_by_user_id' => $owner->id])->save();

        $categories = [];
        foreach ($this->categories as $name) {
            $category = new Category();
            $category->forceFill(['name' => $name, 'type' => 'expense', 'workspace_id' => $workspace->id,
                'user_id' => null, 'created_by_user_id' => $owner->id])->save();
            $categories[$name] = $category;
        }
        $group = new Group();
        $group->forceFill(['name' => 'Summer holiday', 'workspace_id' => $workspace->id,
            'user_id' => null, 'created_by_user_id' => $owner->id])->save();

        $start = Carbon::today()->subDays(29);
        foreach ($this->entries as [$day, $category, $author, $amount, $description]) {
            $datetime = $start->copy()->addDays($day)->setTime(9 + $day % 10, ($day * 13) % 60);
            if ($datetime->isFuture()) {
                $datetime = Carbon::now()->subMinutes(30);
            }
            $transaction = new Transaction();
            $transaction->forceFill(['user_id' => null, 'created_by_user_id' => ($author === 'owner' ? $owner : $member)->id,
                'wallet_id' => $wallet->id, 'amount' => $amount, 'type' => 'expense', 'description' => $description,
                'datetime' => $datetime])->save();
            DB::table('workspace_transaction_categorys')->insert(['workspace_id' => $workspace->id,
                'transaction_id' => $transaction->id, 'category_id' => $categories[$category]->id]);
        }

        $this->budget($workspace, 'Groceries', 350, [['category', $categories['Groceries']->id]]);
        $this->budget($workspace, 'Bills', 120, [['category', $categories['Utilities']->id]]);

        $this->invitationUrl = WorkspaceInvitation::create(['workspace_id' => $workspace->id, 'email' => $invitee,
            'role' => 'member', 'invited_by_user_id' => $owner->id])->url();
        app(WorkspaceFinance::class)->refresh($workspace);

        return $workspace;
    }

    private function budget(Workspace $workspace, string $name, float $amount, array $targets): Budget
    {
        $budget = $workspace->budgets()->create(['name' => $name, 'amount' => $amount, 'currency' => 'EUR',
            'period_type' => 'monthly', 'start_date' => Carbon::today()->startOfMonth()->toDateString()]);
        foreach ($targets as [$type, $id]) {
            $budget->{$type === 'category' ? 'categories' : 'groups'}()->attach($id);
        }

        return $budget;
    }
}
