<?php

namespace App\Services;

use App\Enums\TransactionIntent;
use App\Enums\TransactionType;
use App\Events\ImportComplete;
use App\Events\ImportFailed;
use App\Exceptions\FileImportException;
use App\Models\FileImport;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FileImportService
{
    private TransferService $transferService;

    public function __construct(TransferService $transferService)
    {
        $this->transferService = $transferService;
    }

    /**
     * @SuppressWarnings(PHPMD.IfStatementAssignment)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function processImports(string $path, FileImport $fileImport): void
    {
        $user = $fileImport->user;

        $csvData = [];

        if ($fileImport->num_rows > -1 && $fileImport->progress >= $fileImport->num_rows) {
            return;
        }
        // populate csv data

        if (($handle = fopen($path, 'r')) !== false) {
            $isFirstRow = true;
            while (($data = fgetcsv($handle, null, ',')) !== false) {
                if ($isFirstRow) {
                    $isFirstRow = false;

                    continue;
                }
                $csvData[] = $data;
            }

            if ($fileImport->num_rows == -1) {
                $fileImport->num_rows = count($csvData);
                $fileImport->save();
            }

            $count = count($csvData);
            for ($i = $fileImport->progress; $i < $count; $i++) {
                $data = $csvData[$i];
                if (! $this->isValidDate($data[7])) {
                    $this->saveFailedImport($fileImport, $data, $user, __('Date must be in the format YYYY-MM-DD'));

                    $fileImport->progress = $i + 1;
                    $fileImport->save();

                    continue;
                }

                $transactionType = strtolower(trim($data[2]));
                if (in_array($transactionType, [TransactionType::EXPENSE->value, TransactionType::INCOME->value])) {
                    try {
                        $this->importTransaction(
                            $data,
                            $transactionType,
                            $user,
                            autoCreateWallets: true,
                            autoCreateParties: true,
                            autoCreateCategories: true,
                        );
                    } catch (FileImportException $e) {
                        $this->saveFailedImport($fileImport, $data, $user, $e->getMessage());
                        Log::error($e);
                    } catch (Exception $e) {
                        $this->saveFailedImport(
                            $fileImport,
                            $data,
                            $user,
                            __('An error occurred while importing this transaction')
                        );
                        Log::error($e);
                    }
                } elseif ($transactionType == '+transfer') {
                    if (isset($csvData[$i + 1]) && strtolower(trim($csvData[$i + 1][2])) == '-transfer') {
                        try {
                            $this->importTransfer($data, $csvData[$i + 1], $user);
                        } catch (FileImportException $e) {
                            $this->saveFailedImport($fileImport, $data, $user, $e->getMessage());
                            Log::error($e);
                        } catch (Exception $e) {
                            $this->saveFailedImport(
                                $fileImport,
                                $data,
                                $user,
                                __('An error occurred while importing this transaction')
                            );
                            $this->saveFailedImport(
                                $fileImport,
                                $csvData[$i + 1],
                                $user,
                                __('An error occurred while importing this transaction')
                            );
                            Log::error($e);
                        } finally {
                            $i += 1;
                        }
                    } else {
                        $this->saveFailedImport(
                            $fileImport,
                            $data,
                            $user,
                            __('Corresponding -Transfer transaction not found')
                        );
                    }
                } else {
                    $this->saveFailedImport($fileImport, $data, $user, __('Invalid transaction type'));
                }

                $fileImport->progress = $i + 1;
                $fileImport->save();
            }

            $fileImport->refresh();

            // delete the file after reading
            unlink($path);
            fclose($handle);
            ImportComplete::dispatch($fileImport);
        } else {
            Log::error("Unable to open file: {$path}");
            ImportFailed::dispatch($fileImport);
            //            return;
        }
    }

    public function isValidDate(string $date): bool
    {
        try {
            $newDate = Carbon::createFromFormat('Y-m-d', $date);

            return $newDate && $newDate->format('Y-m-d') === $date;
        } catch (Exception $e) {
            return false;
        }
    }

    private function saveFailedImport(FileImport $fileImport, array $data, User $user, string $reason = ''): void
    {
        $fileImport->failedImports()->create([
            'amount' => $data[0] ?? '',
            'currency' => $data[1] ?? '',
            'type' => $data[2] ?? '',
            'party' => $data[3] ?? '',
            'wallet' => $data[4] ?? '',
            'category' => $data[5] ?? '',
            'description' => $data[6] ?? '',
            'user_id' => $user->id,
            'date' => $data[7] ?? '',
            'reason' => $reason,
        ]);
    }

    /**
     * Used by the raw CSV import path (/api/v1/import), which has no ability to
     * pass resource IDs. Name-based resolution with optional auto-create is the
     * only way to ingest a freshly uploaded spreadsheet.
     *
     * @throws Exception
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function importTransaction(
        array $data,
        string $transactionType,
        User $user,
        bool $autoCreateWallets = false,
        bool $autoCreateParties = false,
        bool $autoCreateCategories = false,
    ): void {
        DB::transaction(function () use ($user, $transactionType, $data, $autoCreateWallets, $autoCreateParties, $autoCreateCategories) {
            // get the data we need

            $amount = floatval($data[0]);
            if ($amount <= 0) {
                throw new FileImportException(__('Amount must be a number greater than zero'));
            }
            $currency = $data[1];
            $party = $data[3];
            $wallet = $data[4];
            $category = $data[5];
            $description = $data[6];
            $date = $data[7];
            $existingWallet = null;
            $existingParty = null;

            if (! empty($wallet)) {
                if (empty($currency)) {
                    $existingWallet = $user->wallets()->where('name', $wallet)->first();
                } else {
                    $existingWallet = $user->wallets()->where('name', $wallet)->where('currency', $currency)->first();
                }

                if (is_null($existingWallet) && ! empty($currency) && $autoCreateWallets) {
                    $existingWallet = $user->wallets()->create([
                        'name' => $wallet,
                        'currency' => $currency,
                    ]);
                }
            }

            if (! empty($party)) {
                $existingParty = $user->parties()->where('name', $party)->first();
                if (is_null($existingParty) && $autoCreateParties) {
                    $existingParty = $user->parties()->create([
                        'name' => $party,
                    ]);
                }
            }

            $transactionData = [
                'amount' => $amount,
                'description' => $description,
                'datetime' => $date,
                'type' => $transactionType,
            ];
            if (! is_null($existingWallet)) {
                $transactionData['wallet_id'] = $existingWallet->id;
            }
            if (! is_null($existingParty)) {
                $transactionData['party_id'] = $existingParty->id;
            }

            $transaction = $user->transactions()->create($transactionData);

            if (! empty($category)) {
                $existingCategory = $user->categories()->where('name', $category)->first();
                if (is_null($existingCategory) && $autoCreateCategories) {
                    $existingCategory = $user->categories()->create([
                        'type' => $transactionType,
                        'name' => $category,
                    ]);
                }

                if (! is_null($existingCategory)) {
                    $transaction->categories()->sync([$existingCategory->id]);
                }
            }
        });
    }

    /**
     * Used by the analyze/confirm flow (/api/v1/import/confirm).
     *
     * Each resource is resolved in order: caller-supplied ID first (strict
     * ownership check), then the analyzer's suggested name when the matching
     * auto_create flag is on. Otherwise the slot stays empty -- which is fatal
     * for wallet (transactions require one), permitted for party/category.
     *
     * @param array $merged  Suggestion row merged with the user's accepted item.
     *                       Structure: [
     *                         'amount' => float, 'description' => ?string, 'date' => ?string,
     *                         'wallet_id' => ?int, 'wallet' => ?string, 'currency' => ?string,
     *                         'party_id' => ?int, 'party' => ?string,
     *                         'category_id' => ?int, 'category' => ?string,
     *                       ]
     *
     * @throws FileImportException
     */
    public function importTransactionFromConfirm(
        array $merged,
        string $transactionType,
        User $user,
        bool $autoCreateWallets = false,
        bool $autoCreateParties = false,
        bool $autoCreateCategories = false,
        bool $linkFee = false,
    ): void {
        DB::transaction(function () use ($merged, $transactionType, $user, $autoCreateWallets, $autoCreateParties, $autoCreateCategories, $linkFee) {
            $amount = (float) ($merged['amount'] ?? 0);
            if ($amount <= 0) {
                throw new FileImportException(__('Amount must be a number greater than zero'));
            }

            $wallet = $this->resolveWalletForConfirm(
                $user,
                $merged['wallet_id'] ?? null,
                $merged['wallet'] ?? null,
                $merged['currency'] ?? null,
                $autoCreateWallets,
            );
            $party = $this->resolvePartyForConfirm(
                $user,
                $merged['party_id'] ?? null,
                $merged['party'] ?? null,
                $autoCreateParties,
            );
            $category = $this->resolveCategoryForConfirm(
                $user,
                $merged['category_id'] ?? null,
                $merged['category'] ?? null,
                $transactionType,
                $autoCreateCategories,
            );

            $transaction = $user->transactions()->create([
                'amount' => $amount,
                'description' => $merged['description'] ?? null,
                'datetime' => $merged['date'] ?? null,
                'type' => $transactionType,
                'wallet_id' => $wallet->id,
                'party_id' => $party?->id,
            ]);

            if (! is_null($category)) {
                $transaction->categories()->sync([$category->id]);
            }

            $fee = (float) ($merged['fee'] ?? 0);
            if ($fee > 0) {
                $this->createFeeTransaction(
                    $user,
                    $wallet,
                    $fee,
                    $merged['date'] ?? null,
                    $linkFee ? $transaction->id : null,
                );
            }
        });
    }

    /**
     * A statement fee is real spending that went to the provider, so it becomes
     * its own expense attributed to a "Charges" party and tagged with the fee
     * intent. It is linked back to the transaction it came from only on request.
     */
    private function createFeeTransaction(
        User $user,
        \App\Models\Wallet $wallet,
        float $fee,
        ?string $date,
        ?int $linkedTransactionId,
    ): void {
        $charges = $this->resolveChargesParty($user);

        $user->transactions()->create([
            'amount' => $fee,
            'description' => __('Transaction fee'),
            'datetime' => $date,
            'type' => TransactionType::EXPENSE->value,
            'intent' => TransactionIntent::FEE->value,
            'wallet_id' => $wallet->id,
            'party_id' => $charges->id,
            'metadata' => $linkedTransactionId ? ['fee_of_transaction_id' => $linkedTransactionId] : null,
        ]);
    }

    private function resolveChargesParty(User $user): \App\Models\Party
    {
        $existing = $user->parties()->where('name', 'Charges')->first();
        if (! is_null($existing)) {
            return $existing;
        }

        return $user->parties()->create([
            'name' => 'Charges',
            'type' => 'service',
        ]);
    }

    /**
     * @throws FileImportException
     */
    private function resolveWalletForConfirm(
        User $user,
        ?int $walletId,
        ?string $walletName,
        ?string $currency,
        bool $autoCreate,
    ): \App\Models\Wallet {
        if (! is_null($walletId)) {
            $found = $user->wallets()->find($walletId);
            if (is_null($found)) {
                throw new FileImportException(__('Wallet with id :id not found', ['id' => $walletId]));
            }

            return $found;
        }

        if ($autoCreate && ! empty($walletName) && ! empty($currency)) {
            // Double-check by name+currency first so two successive accepts of the
            // same analyzer output don't spawn duplicate wallets.
            $existing = $user->wallets()->where('name', $walletName)->where('currency', $currency)->first();
            if (! is_null($existing)) {
                return $existing;
            }

            return $user->wallets()->create([
                'name' => $walletName,
                'currency' => $currency,
            ]);
        }

        throw new FileImportException(__('A wallet must be selected or auto-created.'));
    }

    /**
     * @throws FileImportException
     */
    private function resolvePartyForConfirm(
        User $user,
        ?int $partyId,
        ?string $partyName,
        bool $autoCreate,
    ): ?\App\Models\Party {
        if (! is_null($partyId)) {
            $found = $user->parties()->find($partyId);
            if (is_null($found)) {
                throw new FileImportException(__('Party with id :id not found', ['id' => $partyId]));
            }

            return $found;
        }

        if ($autoCreate && ! empty($partyName)) {
            $existing = $user->parties()->where('name', $partyName)->first();
            if (! is_null($existing)) {
                return $existing;
            }

            return $user->parties()->create(['name' => $partyName]);
        }

        return null;
    }

    /**
     * @throws FileImportException
     */
    private function resolveCategoryForConfirm(
        User $user,
        ?int $categoryId,
        ?string $categoryName,
        string $transactionType,
        bool $autoCreate,
    ): ?\App\Models\Category {
        if (! is_null($categoryId)) {
            $found = $user->categories()->find($categoryId);
            if (is_null($found)) {
                throw new FileImportException(__('Category with id :id not found', ['id' => $categoryId]));
            }

            return $found;
        }

        if ($autoCreate && ! empty($categoryName)) {
            $existing = $user->categories()->where('name', $categoryName)->first();
            if (! is_null($existing)) {
                return $existing;
            }

            return $user->categories()->create([
                'type' => $transactionType,
                'name' => $categoryName,
            ]);
        }

        return null;
    }

    /**
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function importTransfer(array $fromData, array $toData, User $user): void
    {
        DB::transaction(function () use ($fromData, $toData, $user) {

            // get the data we need
            $fromAmount = floatval($fromData[0]);
            $fromCurrency = $fromData[1];
            $fromWallet = $fromData[4];

            $existingFromWallet = null;
            $existingToWallet = null;

            $toAmount = floatval($toData[0]);
            $toCurrency = $toData[1];
            $toWallet = $toData[4];

            if (! empty($fromWallet)) {
                // check if the wallet to send from exists, if not create a new wallet
                if (empty($fromCurrency)) {
                    $existingFromWallet = $user->wallets()->where('name', $fromWallet)->first();
                } else {
                    $existingFromWallet = $user->wallets()
                        ->where('name', $fromWallet)
                        ->where('currency', $fromCurrency)
                        ->first();
                }

                if (is_null($existingFromWallet) && ! empty($fromCurrency)) {
                    $existingFromWallet = $user->wallets()->create([
                        'name' => $fromWallet,
                        'currency' => $fromCurrency,
                        'balance' => $fromAmount,
                    ]);
                }
            }

            if (! empty($toWallet)) {
                // check if the wallet to receive exists, if not create a new wallet
                if (empty($toCurrency)) {
                    $existingToWallet = $user->wallets()->where('name', $toWallet)->first();
                } else {
                    $existingToWallet = $user->wallets()
                        ->where('name', $toWallet)
                        ->where('currency', $toCurrency)->first();
                }

                if (is_null($existingToWallet) && ! empty($toCurrency)) {
                    $existingToWallet = $user->wallets()->create([
                        'name' => $toWallet,
                        'currency' => $toCurrency,
                    ]);
                }
            }

            if (is_null($existingFromWallet) || is_null($existingToWallet)) {
                throw new FileImportException('no wallets found');
            }

            if ($fromAmount == 0) {
                throw new FileImportException(__('Cannot compute transfer with zero send amount'));
            }
            $exchangeRate = $toAmount / $fromAmount;

            // create transfer
            return $this->transferService->transfer(
                $fromAmount,
                $existingFromWallet,
                $toAmount,
                $existingToWallet,
                $user,
                $exchangeRate
            );
        });
    }
}
