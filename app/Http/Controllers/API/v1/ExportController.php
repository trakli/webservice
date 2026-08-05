<?php

namespace App\Http\Controllers\API\v1;

use App\Exports\ExportDocument;
use App\Exports\ExporterManager;
use App\Exports\ReportExportBuilder;
use App\Exports\TransactionExportBuilder;
use App\Http\Controllers\API\ApiController;
use App\Http\Traits\FiltersTransactions;
use App\Http\Traits\ResolvesStatsQuery;
use App\Models\User;
use App\Services\StatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Exports', description: 'Downloadable transaction lists and statements')]
class ExportController extends ApiController
{
    use FiltersTransactions;
    use ResolvesStatsQuery;

    public function __construct(
        private ExporterManager $exporters,
        private TransactionExportBuilder $transactionBuilder,
        private ReportExportBuilder $reportBuilder,
        private StatsService $statsService,
    ) {
    }

    #[OA\Get(
        path: '/transactions/export',
        summary: 'Download the filtered transaction list as a file',
        description: 'Accepts the same filters as the transaction list endpoint so the download matches what the client is showing.',
        tags: ['Exports'],
        parameters: [
            new OA\Parameter(
                name: 'format',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', default: 'csv', enum: ['csv', 'xlsx', 'pdf'])
            ),
            new OA\Parameter(
                name: 'type',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['income', 'expense'])
            ),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(
                name: 'wallet_ids',
                description: 'Comma-separated wallet ids',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'category_ids',
                description: 'Comma-separated category ids',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'intent',
                description: 'Comma-separated transaction intents',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(name: 'exclude_transfers', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The export as a file download'),
            new OA\Response(response: 422, description: 'Unsupported format, invalid filter, or too many rows'),
        ]
    )]
    public function transactions(Request $request): Response|JsonResponse
    {
        $format = (string) $request->query('format', 'csv');
        if (! $this->exporters->has($format)) {
            return $this->unsupportedFormat();
        }

        $type = $request->query('type');
        if (! empty($type) && ! in_array($type, ['income', 'expense'], true)) {
            return $this->failure(__('Invalid transaction type'), 422);
        }

        /** @var User $user */
        $user = $request->user();

        $query = $user->transactions()
            ->orderBy('datetime', 'desc')
            ->orderBy('created_at', 'desc');

        if (! empty($type)) {
            $query->where('type', $type);
        }

        $this->applyTransactionFilters($query, $request);

        $count = $this->transactionBuilder->countRows($query);
        if ($count > TransactionExportBuilder::MAX_ROWS) {
            return $this->failure(
                __('This export covers :count transactions, which is over the limit of :max. Narrow the date range or wallets and try again.', [
                    'count' => $count,
                    'max' => TransactionExportBuilder::MAX_ROWS,
                ]),
                422
            );
        }

        $document = $this->transactionBuilder->build($query, __('Transactions'), [
            __('Generated') => now()->toDayDateTimeString(),
            __('Transactions') => $count,
        ]);

        return $this->download($document, $format, 'transactions');
    }

    #[OA\Get(
        path: '/reports/export',
        summary: 'Download a financial statement as a file',
        description: 'Renders the same analytics as the stats endpoint into a formatted statement.',
        tags: ['Exports'],
        parameters: [
            new OA\Parameter(
                name: 'format',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', default: 'pdf', enum: ['csv', 'xlsx', 'pdf'])
            ),
            new OA\Parameter(name: 'start_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'end_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(
                name: 'preset',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['all_time', 'current_week', 'current_month', 'last_3_months'])
            ),
            new OA\Parameter(
                name: 'wallet_ids',
                description: 'Comma-separated wallet ids',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'period',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', default: 'month', enum: ['day', 'week', 'month', 'year'])
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The statement as a file download'),
            new OA\Response(response: 422, description: 'Unsupported format or invalid wallet ids'),
        ]
    )]
    public function report(Request $request): Response|JsonResponse
    {
        $format = (string) $request->query('format', 'pdf');
        if (! $this->exporters->has($format)) {
            return $this->unsupportedFormat();
        }

        $user = $request->user();

        [$startDate, $endDate] = $this->resolveDateRange($request);

        $walletIds = $this->resolveWalletIds($request, $user);
        if ($walletIds instanceof JsonResponse) {
            return $walletIds;
        }

        $stats = $this->statsService->compute(
            $user,
            $startDate,
            $endDate,
            $walletIds,
            (string) $request->input('period', 'month'),
            $user->getConfigValue('default-currency') ?? 'USD'
        );

        $document = $this->reportBuilder->build($stats, __('Financial statement'), [
            __('Period') => $startDate->toDateString() . ' to ' . $endDate->toDateString(),
            __('Generated') => now()->toDayDateTimeString(),
        ]);

        return $this->download($document, $format, 'statement');
    }

    private function download(ExportDocument $document, string $format, string $basename): Response
    {
        $exporter = $this->exporters->for($format);
        $filename = Str::slug($basename . ' ' . now()->toDateString()) . '.' . $exporter->extension();

        return response($exporter->export($document), Response::HTTP_OK, [
            'Content-Type' => $exporter->mimeType(),
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function unsupportedFormat(): JsonResponse
    {
        return $this->failure(__('Unsupported export format.'), Response::HTTP_UNPROCESSABLE_ENTITY, [
            'supported_formats' => $this->exporters->formats(),
        ]);
    }
}
