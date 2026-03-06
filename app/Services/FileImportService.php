<?php

namespace App\Services;

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
                        $this->importTransaction($data, $transactionType, $user);
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
                } elseif ($transactionType == '+Transfer') {
                    if (isset($csvData[$i + 1]) && $csvData[$i + 1][2] == '-Transfer') {
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
     * @throws Exception
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function importTransaction(array $data, string $transactionType, User $user): void
    {
        DB::transaction(function () use ($user, $transactionType, $data) {
            // get the data we need

            $amount = floatval($data[0]);
            $currency = $data[1];
            $party = $data[3];
            $wallet = $data[4];
            $category = $data[5];
            $description = $data[6];
            $date = $data[7];
            $existingWallet = null;
            $existingParty = null;

            if (! empty($wallet)) {
                // check if the wallet exists, if not create a new wallet
                if (empty($currency)) {
                    $existingWallet = $user->wallets()->where('name', $wallet)->first();
                } else {
                    $existingWallet = $user->wallets()->where('name', $wallet)->where('currency', $currency)->first();
                }

                if (is_null($existingWallet) && ! empty($currency)) {
                    $existingWallet = $user->wallets()->create([
                        'name' => $wallet,
                        'currency' => $currency,
                    ]);
                }
            }

            if (! empty($party)) {
                // check if the party exists, if not create a new party
                $existingParty = $user->parties()->where('name', $party)->first();
                if (is_null($existingParty)) {
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
                // check if the category exists, if not create a new category
                $existingCategory = $user->categories()->where('name', $category)->first();
                if (is_null($existingCategory)) {
                    $existingCategory = $user->categories()->create([
                        'type' => $transactionType,
                        'name' => $category,
                    ]);
                }

                $transaction->categories()->sync([$existingCategory->id]);
            }
        });
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
