<?php

namespace App\Http\Controllers\API\v1;

use App\Events\TransactionRecorded;
use App\Http\Controllers\API\ApiController;
use App\Models\Category;
use App\Models\Group;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\Workspace;
use App\Services\WorkspaceFinance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceFinanceController extends ApiController
{
    public function __construct(private WorkspaceFinance $finance)
    {
    }

    public function overview(Request $request, Workspace $workspace): JsonResponse
    {
        $wallets = $this->finance->wallets($workspace)->withCount('transactions')->get()
            ->map(fn ($wallet) => $this->presentWallet($request, $workspace, $wallet));
        $budgets = $workspace->budgets()->with(['categories', 'groups', 'wallets'])->latest()->get();
        $totals = [];
        foreach ($this->finance->transactions($workspace)->whereNull('transfer_id')->with('wallet')->cursor() as $transaction) {
            $currency = $transaction->wallet->currency;
            $totals[$currency] ??= ['currency' => $currency, 'income' => 0, 'expenses' => 0];
            $key = TransactionRecorded::normalizeType($transaction->type) === 'income' ? 'income' : 'expenses';
            $totals[$currency][$key] += (float) $transaction->amount;
        }

        return $this->success(['wallets' => $wallets, 'budgets' => $budgets,
            'categories' => Category::where('workspace_id', $workspace->id)->get(['id', 'name', 'type']),
            'groups' => Group::where('workspace_id', $workspace->id)->get(['id', 'name']),
            'totals' => array_values($totals)]);
    }

    public function transactions(Request $request, Workspace $workspace): JsonResponse
    {
        $query = $this->finance->transactions($workspace)->with('wallet')->orderByDesc('datetime')->orderByDesc('id');
        $filters = $request->validate(['wallet_id' => 'nullable|integer', 'type' => 'nullable|in:income,expense',
            'start_date' => 'nullable|date', 'end_date' => 'nullable|date', 'page' => 'nullable|integer|min:1']);
        foreach (['wallet_id', 'type'] as $key) {
            if (isset($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (isset($filters['start_date'])) {
            $query->whereDate('datetime', '>=', $filters['start_date']);
        }
        if (isset($filters['end_date'])) {
            $query->whereDate('datetime', '<=', $filters['end_date']);
        }
        $page = $query->paginate(25);

        return $this->success(['data' => collect($page->items())->map(fn ($transaction) => $this->finance->presentTransaction(
            $workspace,
            $transaction,
            $request->user()
        )),
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total()]);
    }

    public function storeWallet(Request $request, Workspace $workspace): JsonResponse
    {
        $this->finance->authorize($workspace, $request->user(), manage: true, write: true);
        $data = $request->validate($this->walletRules());
        $wallet = DB::transaction(function () use ($request, $workspace, $data) {
            $wallet = new Wallet();
            $wallet->forceFill($data + ['workspace_id' => $workspace->id, 'user_id' => null,
                'created_by_user_id' => $request->user()->id])->save();
            return $wallet;
        });

        return $this->success($this->presentWallet($request, $workspace, $wallet), 'Shared wallet created', 201);
    }

    public function updateWallet(Request $request, Workspace $workspace, int $walletId): JsonResponse
    {
        $this->finance->authorize($workspace, $request->user(), manage: true, write: true);
        $wallet = Wallet::where('workspace_id', $workspace->id)->findOrFail($walletId);
        $data = $request->validate($this->walletRules(update: true));
        if (isset($data['currency']) && $data['currency'] !== $wallet->currency && $wallet->transactions()->exists()) {
            $this->finance->deny('A wallet with transactions cannot change currency.');
        }
        $wallet->fill($data)->save();

        return $this->success($this->presentWallet($request, $workspace, $wallet), 'Wallet updated');
    }

    public function deleteWallet(Request $request, Workspace $workspace, int $walletId): JsonResponse
    {
        $this->finance->authorize($workspace, $request->user(), manage: true, write: true);
        $wallet = Wallet::where('workspace_id', $workspace->id)->findOrFail($walletId);
        if ($wallet->transactions()->exists()) {
            $this->finance->deny('Remove the wallet transactions before deleting this wallet.');
        }
        $wallet->delete();

        return $this->success(null, 'Wallet deleted');
    }

    public function shareWallet(Request $request, Workspace $workspace): JsonResponse
    {
        $this->finance->authorize($workspace, $request->user(), write: true);
        $data = $request->validate(['wallet_id' => 'required|integer', 'confirmed' => 'required|accepted']);
        $wallet = $request->user()->wallets()->findOrFail($data['wallet_id']);
        DB::table('workspace_wallet_shares')->updateOrInsert(
            ['workspace_id' => $workspace->id, 'wallet_id' => $wallet->id],
            ['user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]
        );
        $this->finance->refresh($workspace);

        return $this->success($this->presentWallet($request, $workspace, $wallet), 'Wallet shared', 201);
    }

    public function unshareWallet(Request $request, Workspace $workspace, int $walletId): JsonResponse
    {
        $share = DB::table('workspace_wallet_shares')->where('workspace_id', $workspace->id)->where('wallet_id', $walletId)->first();
        if (! $share) {
            $this->finance->deny('Wallet share not found.', 404);
        }
        if ($share->user_id !== $request->user()->id) {
            $this->finance->authorize($workspace, $request->user(), manage: true);
        }
        DB::transaction(function () use ($workspace, $walletId) {
            DB::table('workspace_wallet_shares')->where('workspace_id', $workspace->id)->where('wallet_id', $walletId)->delete();
            DB::table('workspace_transaction_shares')->where('workspace_id', $workspace->id)
                ->whereIn('transaction_id', Transaction::withTrashed()->where('wallet_id', $walletId)->select('id'))->delete();
            $this->finance->refresh($workspace);
        });

        return $this->success(null, 'Wallet and its transaction shares revoked');
    }

    public function shareTransaction(Request $request, Workspace $workspace): JsonResponse
    {
        $this->finance->authorize($workspace, $request->user(), write: true);
        $data = $request->validate(['transaction_id' => 'required|integer', 'confirmed' => 'required|accepted']);
        $transaction = $request->user()->transactions()->findOrFail($data['transaction_id']);
        DB::table('workspace_transaction_shares')->updateOrInsert(
            ['workspace_id' => $workspace->id, 'transaction_id' => $transaction->id],
            ['user_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]
        );
        $this->finance->refresh($workspace);

        return $this->success($this->finance->presentTransaction($workspace, $transaction, $request->user()), 'Transaction shared', 201);
    }

    public function unshareTransaction(Request $request, Workspace $workspace, int $transactionId): JsonResponse
    {
        $share = DB::table('workspace_transaction_shares')->where('workspace_id', $workspace->id)->where('transaction_id', $transactionId)->first();
        if (! $share) {
            $this->finance->deny('Transaction share not found. Unshare its wallet to revoke full-wallet access.', 404);
        }
        if ($share->user_id !== $request->user()->id) {
            $this->finance->authorize($workspace, $request->user(), manage: true);
        }
        DB::table('workspace_transaction_shares')->where('id', $share->id)->delete();
        $this->finance->refresh($workspace);

        return $this->success(null, 'Transaction share revoked');
    }

    public function storeTransaction(Request $request, Workspace $workspace): JsonResponse
    {
        $this->finance->authorize($workspace, $request->user(), write: true);
        $data = $request->validate($this->transactionRules());
        $wallet = Wallet::where('workspace_id', $workspace->id)->findOrFail($data['wallet_id']);
        $transaction = DB::transaction(function () use ($request, $workspace, $data, $wallet) {
            Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            $transaction = new Transaction();
            $transaction->forceFill(collect($data)->except(['category_ids', 'group_ids', 'is_refund'])->all() + [
                'user_id' => null, 'created_by_user_id' => $request->user()->id, 'wallet_id' => $wallet->id,
            ])->save();
            $this->setRefund($transaction, $data);
            $this->setLabels($workspace, $transaction, $data);
            $this->finance->refresh($workspace);
            return $transaction;
        });

        return $this->success($this->finance->presentTransaction($workspace, $transaction, $request->user()), 'Transaction recorded', 201);
    }

    public function updateTransaction(Request $request, Workspace $workspace, int $transactionId): JsonResponse
    {
        $this->finance->authorize($workspace, $request->user(), write: true);
        $transaction = $this->editableTransaction($request, $workspace, $transactionId);
        $data = $request->validate($this->transactionRules(update: true));
        if (isset($data['wallet_id'])) {
            Wallet::where('workspace_id', $workspace->id)->findOrFail($data['wallet_id']);
        }
        DB::transaction(function () use ($workspace, $transaction, $data) {
            Wallet::whereIn('id', [$transaction->wallet_id, $data['wallet_id'] ?? $transaction->wallet_id])->orderBy('id')->lockForUpdate()->get();
            $transaction->fill(collect($data)->except(['category_ids', 'group_ids', 'is_refund'])->all())->save();
            $transaction->markAsSynced();
            $this->setRefund($transaction, $data);
            $this->setLabels($workspace, $transaction, $data);
            $this->finance->refresh($workspace);
        });

        return $this->success($this->finance->presentTransaction($workspace, $transaction, $request->user()), 'Transaction updated');
    }

    public function deleteTransaction(Request $request, Workspace $workspace, int $transactionId): JsonResponse
    {
        $this->finance->authorize($workspace, $request->user(), write: true);
        $transaction = $this->editableTransaction($request, $workspace, $transactionId);
        DB::transaction(function () use ($workspace, $transaction) {
            Wallet::whereKey($transaction->wallet_id)->lockForUpdate()->firstOrFail();
            $transaction->delete();
            $this->finance->refresh($workspace);
        });

        return $this->success(null, 'Transaction deleted');
    }

    public function classifyTransaction(Request $request, Workspace $workspace, int $transactionId): JsonResponse
    {
        $this->finance->authorize($workspace, $request->user(), manage: true, write: true);
        $transaction = $this->finance->transactions($workspace)->findOrFail($transactionId);
        $data = $request->validate(['category_ids' => 'present|array', 'category_ids.*' => 'integer',
            'group_ids' => 'present|array', 'group_ids.*' => 'integer']);
        DB::transaction(function () use ($workspace, $transaction, $data) {
            $this->setLabels($workspace, $transaction, $data);
            $this->finance->refresh($workspace);
        });

        return $this->success($this->finance->presentTransaction($workspace, $transaction, $request->user()), 'Workspace classification saved');
    }

    public function storeLabel(Request $request, Workspace $workspace, string $kind): JsonResponse
    {
        $this->finance->authorize($workspace, $request->user(), manage: true, write: true);
        $model = match ($kind) {
            'categories' => Category::class, 'groups' => Group::class, default => abort(404)
        };
        $data = $request->validate(['name' => 'required|string|max:255', 'description' => 'nullable|string|max:500',
            'type' => 'sometimes|required|in:income,expense']);
        $label = new $model();
        $label->forceFill($data + ['type' => 'expense', 'workspace_id' => $workspace->id,
            'user_id' => null, 'created_by_user_id' => $request->user()->id])->save();

        return $this->success(['id' => $label->id, 'name' => $label->name, 'type' => $label->type], 'Label created', 201);
    }

    private function editableTransaction(Request $request, Workspace $workspace, int $transactionId): Transaction
    {
        $transaction = $this->finance->transactions($workspace)->findOrFail($transactionId);
        if ((int) $transaction->wallet->workspace_id !== $workspace->id) {
            $this->finance->deny('Only the personal owner can change the original transaction.', 403);
        }
        if ($transaction->created_by_user_id !== $request->user()->id) {
            $this->finance->authorize($workspace, $request->user(), manage: true, write: true);
        }

        return $transaction;
    }

    private function presentWallet(Request $request, Workspace $workspace, Wallet $wallet): array
    {
        $native = (int) $wallet->workspace_id === $workspace->id;
        $admin = in_array($this->finance->role($workspace, $request->user()), ['owner', 'admin'], true);
        return ['id' => $wallet->id, 'name' => $wallet->name, 'type' => $wallet->type,
            'currency' => $wallet->currency, 'balance' => (float) $wallet->balance,
            'source' => $native ? 'workspace' : 'shared_wallet',
            'transactions_count' => (int) $wallet->transactions_count,
            'can_edit' => $native && $admin && $this->finance->eligible($workspace),
            'can_unshare' => ! $native && ($wallet->user_id === $request->user()->id || $admin)];
    }

    private function setLabels(Workspace $workspace, Transaction $transaction, array $data): void
    {
        foreach (['category' => Category::class, 'group' => Group::class] as $kind => $model) {
            $key = "{$kind}_ids";
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $ids = array_values(array_unique($data[$key]));
            if ($model::where('workspace_id', $workspace->id)->whereIn('id', $ids)->count() !== count($ids)) {
                $this->finance->deny('Choose labels belonging to this workspace.');
            }
            DB::table('workspace_transaction_' . Str::plural($kind))->where('workspace_id', $workspace->id)
                ->where('transaction_id', $transaction->id)->delete();
            foreach ($ids as $id) {
                DB::table('workspace_transaction_' . Str::plural($kind))->insert(['workspace_id' => $workspace->id, 'transaction_id' => $transaction->id,
                    "{$kind}_id" => $id]);
            }
        }
    }

    private function walletRules(bool $update = false): array
    {
        $required = $update ? 'sometimes|required' : 'required';
        return ['name' => "$required|string|max:255", 'type' => "$required|in:bank,cash,credit_card,mobile",
            'currency' => "$required|string|size:3|regex:/^[A-Z]{3}$/", 'description' => 'nullable|string|max:500',
            'balance' => 'sometimes|numeric'];
    }

    private function setRefund(Transaction $transaction, array $data): void
    {
        if (! array_key_exists('is_refund', $data)) {
            return;
        }
        if ($data['is_refund']) {
            if (TransactionRecorded::normalizeType($transaction->type) !== 'income') {
                $this->finance->deny('Only income can be marked as a refund.');
            }
            $transaction->markAsRefund();
        } else {
            $transaction->unmarkRefund();
        }
    }

    private function transactionRules(bool $update = false): array
    {
        $required = $update ? 'sometimes|required' : 'required';
        return ['amount' => "$required|numeric|min:0.0001", 'wallet_id' => "$required|integer",
            'type' => "$required|in:income,expense", 'datetime' => "$required|date", 'description' => 'nullable|string|max:2000',
            'category_ids' => 'sometimes|array', 'category_ids.*' => 'integer',
            'group_ids' => 'sometimes|array', 'group_ids.*' => 'integer', 'is_refund' => 'sometimes|boolean'];
    }
}
