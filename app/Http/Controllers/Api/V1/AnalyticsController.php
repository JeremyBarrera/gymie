<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\InvoiceTransactionResource;
use App\Models\InvoiceTransaction;
use App\Services\Analytics\AnalyticsService;
use App\Support\Analytics\AnalyticsDateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AnalyticsController extends ApiController
{
    

    public function financial(Request $request): JsonResponse
    {
        $range = AnalyticsDateRange::fromFilters($request->all());
        $metrics = app(AnalyticsService::class)->financialMetrics($range);

        return response()->json([
            'data' => [
                'range' => [
                    'start' => $range->start->toDateString(),
                    'end' => $range->end->toDateString(),
                ],
                'metrics' => $metrics,
            ],
        ]);
    }

    

    public function membership(Request $request): JsonResponse
    {
        $range = AnalyticsDateRange::fromFilters($request->all());
        $metrics = app(AnalyticsService::class)->membershipMetrics($range);

        return response()->json([
            'data' => [
                'range' => [
                    'start' => $range->start->toDateString(),
                    'end' => $range->end->toDateString(),
                ],
                'metrics' => $metrics,
            ],
        ]);
    }

    

    public function cashflowTrend(Request $request): JsonResponse
    {
        $range = AnalyticsDateRange::fromFilters($request->all());
        $service = app(AnalyticsService::class);

        $days = $range->end->diffInDays($range->start) + 1;
        $grouping = $days <= 31 ? 'day' : 'month';

        $collected = $grouping === 'day'
            ? $service->collectedTrendByDate($range)
            : $service->collectedTrendByMonth($range);

        $expenses = $grouping === 'day'
            ? $service->expenseTrendByDate($range)
            : $service->expenseTrendByMonth($range);

        $labels = collect(array_keys($collected))
            ->merge(array_keys($expenses))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $collectedSeries = [];
        $expenseSeries = [];

        foreach ($labels as $label) {
            $collectedSeries[] = (float) ($collected[$label] ?? 0);
            $expenseSeries[] = (float) ($expenses[$label] ?? 0);
        }

        return response()->json([
            'data' => [
                'grouping' => $grouping,
                'labels' => $labels,
                'series' => [
                    'collected' => $collectedSeries,
                    'expenses' => $expenseSeries,
                ],
            ],
        ]);
    }

    

    public function expenseCategories(Request $request): JsonResponse
    {
        $range = AnalyticsDateRange::fromFilters($request->all());
        $rows = app(AnalyticsService::class)->expenseBreakdownByCategory($range, 10);

        return response()->json([
            'data' => $rows->values()->all(),
        ]);
    }

    

    public function topPlans(Request $request): JsonResponse
    {
        $range = AnalyticsDateRange::fromFilters($request->all());
        $rows = app(AnalyticsService::class)->topPlansByCollected($range, 5);

        return response()->json([
            'data' => $rows->values()->all(),
        ]);
    }

    

    public function recentTransactions(Request $request): AnonymousResourceCollection
    {
        $limit = (int) $request->query('limit', 5);
        $limit = $limit > 0 ? min($limit, 50) : 5;

        $rows = InvoiceTransaction::query()
            ->with([
                'invoice.subscription.member',
                'invoice.subscription.plan',
            ])
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();

        return InvoiceTransactionResource::collection($rows);
    }
}
