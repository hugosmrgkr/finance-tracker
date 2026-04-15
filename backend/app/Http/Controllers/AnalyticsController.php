<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Transaction;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    private function resolveUserId(Request $request, array $validated): int
    {
        return (int) ($request->user()?->id ?? $validated['user_id']);
    }

    private function baseRules(Request $request): array
    {
        $requiresUserId = $request->user() === null;

        return [
            'user_id' => array_values(array_filter([
                $requiresUserId ? 'required' : 'sometimes',
                'integer',
                'exists:users,id',
            ])),
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
        ];
    }

    private function applyPeriodFilter($query, array $validated)
    {
        if (!empty($validated['start_date'])) {
            $query->whereDate('date', '>=', $validated['start_date']);
        }

        if (!empty($validated['end_date'])) {
            $query->whereDate('date', '<=', $validated['end_date']);
        }

        return $query;
    }

    private function monthAndYearExpressions(): array
    {
        $driver = DB::connection()->getDriverName();

        $monthExpr = match ($driver) {
            'sqlite' => "CAST(strftime('%m', date) AS INTEGER)",
            'pgsql' => 'EXTRACT(MONTH FROM date)',
            default => 'MONTH(date)',
        };

        $yearExpr = match ($driver) {
            'sqlite' => "CAST(strftime('%Y', date) AS INTEGER)",
            'pgsql' => 'EXTRACT(YEAR FROM date)',
            default => 'YEAR(date)',
        };

        return [$monthExpr, $yearExpr];
    }

    private function dayExpression(): string
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'sqlite' => "date(date)",
            default => 'DATE(date)',
        };
    }

    private function buildDailyMap(int $userId, string $startDate, string $endDate, array $validated): array
    {
        $dayExpr = $this->dayExpression();

        $query = Transaction::query()
            ->where('user_id', $userId);

        $this->applyPeriodFilter($query, [
            ...$validated,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        $rows = $query
            ->selectRaw($dayExpr . ' as day, type, SUM(amount) as total')
            ->groupByRaw($dayExpr . ', type')
            ->orderByRaw($dayExpr . ' asc')
            ->get();

        $map = [];
        $cursor = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();

        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $map[$key] = [
                'date' => $key,
                'income' => 0.0,
                'expense' => 0.0,
                'balance' => 0.0,
            ];
            $cursor->addDay();
        }

        foreach ($rows as $row) {
            $day = Carbon::parse($row->day)->toDateString();
            if (!isset($map[$day])) {
                continue;
            }

            $type = $row->type;
            $value = round((float) $row->total, 2);

            if ($type === 'income') {
                $map[$day]['income'] = $value;
            }

            if ($type === 'expense') {
                $map[$day]['expense'] = $value;
            }

            $map[$day]['balance'] = round($map[$day]['income'] - $map[$day]['expense'], 2);
        }

        return $map;
    }

    private function buildMonthlyMap(int $userId, int $year, array $validated): array
    {
        [$monthExpr, $yearExpr] = $this->monthAndYearExpressions();

        $query = Transaction::query()
            ->where('user_id', $userId)
            ->whereRaw($yearExpr . ' = ?', [$year]);

        $this->applyPeriodFilter($query, $validated);

        $rows = $query
            ->selectRaw($monthExpr . ' as month, type, SUM(amount) as total')
            ->groupByRaw($monthExpr . ', type')
            ->orderByRaw($monthExpr . ' asc')
            ->get();

        $monthMap = [];
        for ($month = 1; $month <= 12; $month++) {
            $monthMap[$month] = [
                'month' => $month,
                'income' => 0.0,
                'expense' => 0.0,
                'balance' => 0.0,
            ];
        }

        foreach ($rows as $row) {
            $month = (int) $row->month;
            $type = $row->type;
            $value = round((float) $row->total, 2);

            if (!isset($monthMap[$month])) {
                continue;
            }

            if ($type === 'income') {
                $monthMap[$month]['income'] = $value;
            }

            if ($type === 'expense') {
                $monthMap[$month]['expense'] = $value;
            }

            $monthMap[$month]['balance'] = round(
                $monthMap[$month]['income'] - $monthMap[$month]['expense'],
                2
            );
        }

        return $monthMap;
    }

    private function buildYearlyMap(int $userId, int $startYear, int $endYear, array $validated): array
    {
        [, $yearExpr] = $this->monthAndYearExpressions();

        $query = Transaction::query()
            ->where('user_id', $userId)
            ->whereRaw($yearExpr . ' BETWEEN ? AND ?', [$startYear, $endYear]);

        $this->applyPeriodFilter($query, $validated);

        $rows = $query
            ->selectRaw($yearExpr . ' as year, type, SUM(amount) as total')
            ->groupByRaw($yearExpr . ', type')
            ->orderByRaw($yearExpr . ' asc')
            ->get();

        $yearMap = [];
        for ($year = $startYear; $year <= $endYear; $year++) {
            $yearMap[$year] = [
                'year' => $year,
                'income' => 0.0,
                'expense' => 0.0,
                'balance' => 0.0,
            ];
        }

        foreach ($rows as $row) {
            $year = (int) $row->year;
            $type = $row->type;
            $value = round((float) $row->total, 2);

            if (!isset($yearMap[$year])) {
                continue;
            }

            if ($type === 'income') {
                $yearMap[$year]['income'] = $value;
            }

            if ($type === 'expense') {
                $yearMap[$year]['expense'] = $value;
            }

            $yearMap[$year]['balance'] = round(
                $yearMap[$year]['income'] - $yearMap[$year]['expense'],
                2
            );
        }

        return $yearMap;
    }

    private function growthRate(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return $current == 0.0 ? 0.0 : null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 2);
    }

    private function totalsForDateRange(int $userId, string $startDate, string $endDate): array
    {
        $totals = Transaction::query()
            ->where('user_id', $userId)
            ->whereDate('date', '>=', $startDate)
            ->whereDate('date', '<=', $endDate)
            ->select('type', DB::raw('SUM(amount) as total'))
            ->groupBy('type')
            ->pluck('total', 'type');

        $income = round((float) ($totals['income'] ?? 0), 2);
        $expense = round((float) ($totals['expense'] ?? 0), 2);

        return [
            'income' => $income,
            'expense' => $expense,
            'balance' => round($income - $expense, 2),
        ];
    }

    public function summary(Request $request)
    {
        $validated = $request->validate($this->baseRules($request));
        $userId = $this->resolveUserId($request, $validated);

        $baseQuery = Transaction::query()->where('user_id', $userId);
        $this->applyPeriodFilter($baseQuery, $validated);

        $totals = (clone $baseQuery)
            ->select('type', DB::raw('SUM(amount) as total'))
            ->groupBy('type')
            ->pluck('total', 'type');

        $income = (float) ($totals['income'] ?? 0);
        $expense = (float) ($totals['expense'] ?? 0);

        return response()->json([
            'income' => round($income, 2),
            'expense' => round($expense, 2),
            'balance' => round($income - $expense, 2),
            'period' => [
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
            ],
        ]);
    }

    public function byCategory(Request $request)
    {
        $validated = $request->validate($this->baseRules($request));
        $userId = $this->resolveUserId($request, $validated);

        $query = Transaction::query()
            ->join('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('transactions.user_id', $userId);

        $this->applyPeriodFilter($query, $validated);

        $rows = $query
            ->selectRaw('categories.id as category_id, categories.name as category_name, categories.type as type, SUM(transactions.amount) as total')
            ->groupBy('categories.id', 'categories.name', 'categories.type')
            ->orderByDesc('total')
            ->get();

        return response()->json($rows->map(function ($row) {
            return [
                'category_id' => (int) $row->category_id,
                'category_name' => $row->category_name,
                'type' => $row->type,
                'total' => round((float) $row->total, 2),
            ];
        }));
    }

    public function monthly(Request $request)
    {
        $rules = $this->baseRules($request);
        $rules['year'] = ['nullable', 'integer', 'min:1900', 'max:2100'];

        $validated = $request->validate($rules);
        $userId = $this->resolveUserId($request, $validated);
        $year = (int) ($validated['year'] ?? now()->year);

        $monthMap = $this->buildMonthlyMap($userId, $year, $validated);

        return response()->json([
            'year' => $year,
            'months' => array_values($monthMap),
        ]);
    }

    public function daily(Request $request)
    {
        $rules = $this->baseRules($request);
        $rules['reference_date'] = ['nullable', 'date'];
        $rules['end_date'] = ['nullable', 'date', 'after_or_equal:start_date'];

        $validated = $request->validate($rules);
        $userId = $this->resolveUserId($request, $validated);

        $referenceDate = Carbon::parse($validated['reference_date'] ?? now()->toDateString());
        $startDate = $validated['start_date'] ?? $referenceDate->copy()->startOfMonth()->toDateString();
        $endDate = $validated['end_date'] ?? $referenceDate->copy()->endOfMonth()->toDateString();

        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();
        if ($start->diffInDays($end, false) < 0) {
            return response()->json([
                'message' => 'The end_date must be a date after or equal to start_date.',
                'errors' => [
                    'end_date' => ['The end_date must be a date after or equal to start_date.'],
                ],
            ], 422);
        }

        if ($start->diffInDays($end) > 366) {
            return response()->json([
                'message' => 'The selected date range is too large.',
                'errors' => [
                    'date_range' => ['The date range must be 366 days or less.'],
                ],
            ], 422);
        }

        $dayMap = $this->buildDailyMap($userId, $startDate, $endDate, $validated);

        return response()->json([
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'days' => array_values($dayMap),
        ]);
    }

    public function monthlyGrowth(Request $request)
    {
        $rules = $this->baseRules($request);
        $rules['year'] = ['nullable', 'integer', 'min:1900', 'max:2100'];
        $rules['metric'] = ['nullable', 'in:income,expense,balance'];
        $rules['compact'] = ['nullable', 'boolean'];

        $validated = $request->validate($rules);
        $userId = $this->resolveUserId($request, $validated);
        $year = (int) ($validated['year'] ?? now()->year);
        $metric = $validated['metric'] ?? null;
        $compact = (bool) ($validated['compact'] ?? false);

        $monthMap = $this->buildMonthlyMap($userId, $year, $validated);

        $previous = null;
        $result = [];

        foreach (array_values($monthMap) as $month) {
            $income = (float) $month['income'];
            $expense = (float) $month['expense'];
            $balance = (float) $month['balance'];

            $result[] = [
                'month' => (int) $month['month'],
                'income' => $income,
                'expense' => $expense,
                'balance' => $balance,
                'income_growth_rate' => $previous === null
                    ? null
                    : $this->growthRate($income, (float) $previous['income']),
                'expense_growth_rate' => $previous === null
                    ? null
                    : $this->growthRate($expense, (float) $previous['expense']),
                'balance_growth_rate' => $previous === null
                    ? null
                    : $this->growthRate($balance, (float) $previous['balance']),
            ];

            $previous = $month;
        }

        if ($metric !== null) {
            $result = array_map(function (array $month) use ($metric) {
                return [
                    'month' => $month['month'],
                    'value' => $month[$metric],
                    'growth_rate' => $month[$metric . '_growth_rate'],
                ];
            }, $result);
        }

        if ($compact) {
            $result = array_map(function (array $month) use ($metric) {
                if ($metric !== null) {
                    return [
                        'month' => $month['month'],
                        'value' => $month['value'],
                        'growth_rate' => $month['growth_rate'],
                    ];
                }

                return [
                    'month' => $month['month'],
                    'balance' => $month['balance'],
                    'balance_growth_rate' => $month['balance_growth_rate'],
                ];
            }, $result);
        }

        return response()->json([
            'year' => $year,
            'metric' => $metric,
            'compact' => $compact,
            'months' => $result,
        ]);
    }

    public function yearlyGrowth(Request $request)
    {
        $rules = $this->baseRules($request);
        $rules['start_year'] = ['nullable', 'integer', 'min:1900', 'max:2100'];
        $rules['end_year'] = ['nullable', 'integer', 'min:1900', 'max:2100', 'gte:start_year'];
        $rules['metric'] = ['nullable', 'in:income,expense,balance'];
        $rules['compact'] = ['nullable', 'boolean'];

        $validated = $request->validate($rules);
        $userId = $this->resolveUserId($request, $validated);

        $currentYear = (int) now()->year;
        $startYear = (int) ($validated['start_year'] ?? ($currentYear - 4));
        $endYear = (int) ($validated['end_year'] ?? $currentYear);
        $metric = $validated['metric'] ?? null;
        $compact = (bool) ($validated['compact'] ?? false);

        $yearMap = $this->buildYearlyMap($userId, $startYear, $endYear, $validated);

        $previous = null;
        $result = [];

        foreach (array_values($yearMap) as $yearData) {
            $income = (float) $yearData['income'];
            $expense = (float) $yearData['expense'];
            $balance = (float) $yearData['balance'];

            $result[] = [
                'year' => (int) $yearData['year'],
                'income' => $income,
                'expense' => $expense,
                'balance' => $balance,
                'income_growth_rate' => $previous === null
                    ? null
                    : $this->growthRate($income, (float) $previous['income']),
                'expense_growth_rate' => $previous === null
                    ? null
                    : $this->growthRate($expense, (float) $previous['expense']),
                'balance_growth_rate' => $previous === null
                    ? null
                    : $this->growthRate($balance, (float) $previous['balance']),
            ];

            $previous = $yearData;
        }

        if ($metric !== null) {
            $result = array_map(function (array $yearData) use ($metric) {
                return [
                    'year' => $yearData['year'],
                    'value' => $yearData[$metric],
                    'growth_rate' => $yearData[$metric . '_growth_rate'],
                ];
            }, $result);
        }

        if ($compact) {
            $result = array_map(function (array $yearData) use ($metric) {
                if ($metric !== null) {
                    return [
                        'year' => $yearData['year'],
                        'value' => $yearData['value'],
                        'growth_rate' => $yearData['growth_rate'],
                    ];
                }

                return [
                    'year' => $yearData['year'],
                    'balance' => $yearData['balance'],
                    'balance_growth_rate' => $yearData['balance_growth_rate'],
                ];
            }, $result);
        }

        return response()->json([
            'start_year' => $startYear,
            'end_year' => $endYear,
            'metric' => $metric,
            'compact' => $compact,
            'years' => $result,
        ]);
    }

    public function kpis(Request $request)
    {
        $rules = $this->baseRules($request);
        $rules['reference_date'] = ['nullable', 'date'];

        $validated = $request->validate($rules);
        $userId = $this->resolveUserId($request, $validated);

        $referenceDate = Carbon::parse($validated['reference_date'] ?? now()->toDateString());

        $currentMonthStart = $referenceDate->copy()->startOfMonth()->toDateString();
        $currentMonthEnd = $referenceDate->copy()->endOfMonth()->toDateString();
        $previousMonth = $referenceDate->copy()->subMonthNoOverflow();
        $previousMonthStart = $previousMonth->copy()->startOfMonth()->toDateString();
        $previousMonthEnd = $previousMonth->copy()->endOfMonth()->toDateString();

        $currentYearStart = $referenceDate->copy()->startOfYear()->toDateString();
        $currentYearEnd = $referenceDate->copy()->endOfYear()->toDateString();
        $previousYear = $referenceDate->copy()->subYearNoOverflow();
        $previousYearStart = $previousYear->copy()->startOfYear()->toDateString();
        $previousYearEnd = $previousYear->copy()->endOfYear()->toDateString();

        $currentMonthTotals = $this->totalsForDateRange($userId, $currentMonthStart, $currentMonthEnd);
        $previousMonthTotals = $this->totalsForDateRange($userId, $previousMonthStart, $previousMonthEnd);
        $currentYearTotals = $this->totalsForDateRange($userId, $currentYearStart, $currentYearEnd);
        $previousYearTotals = $this->totalsForDateRange($userId, $previousYearStart, $previousYearEnd);

        return response()->json([
            'reference_date' => $referenceDate->toDateString(),
            'month' => [
                'current' => $currentMonthTotals,
                'previous' => $previousMonthTotals,
                'growth_rates' => [
                    'income' => $this->growthRate($currentMonthTotals['income'], $previousMonthTotals['income']),
                    'expense' => $this->growthRate($currentMonthTotals['expense'], $previousMonthTotals['expense']),
                    'balance' => $this->growthRate($currentMonthTotals['balance'], $previousMonthTotals['balance']),
                ],
            ],
            'year' => [
                'current' => $currentYearTotals,
                'previous' => $previousYearTotals,
                'growth_rates' => [
                    'income' => $this->growthRate($currentYearTotals['income'], $previousYearTotals['income']),
                    'expense' => $this->growthRate($currentYearTotals['expense'], $previousYearTotals['expense']),
                    'balance' => $this->growthRate($currentYearTotals['balance'], $previousYearTotals['balance']),
                ],
            ],
            'cards' => [
                'month_balance' => $currentMonthTotals['balance'],
                'month_balance_growth_rate' => $this->growthRate($currentMonthTotals['balance'], $previousMonthTotals['balance']),
                'year_balance' => $currentYearTotals['balance'],
                'year_balance_growth_rate' => $this->growthRate($currentYearTotals['balance'], $previousYearTotals['balance']),
            ],
        ]);
    }

    public function insights(Request $request)
    {
        $rules = $this->baseRules($request);
        $rules['reference_date'] = ['nullable', 'date'];

        $validated = $request->validate($rules);
        $userId = $this->resolveUserId($request, $validated);

        $referenceDate = Carbon::parse($validated['reference_date'] ?? now()->toDateString());

        $monthStart = $referenceDate->copy()->startOfMonth()->toDateString();
        $monthEnd = $referenceDate->copy()->endOfMonth()->toDateString();

        $topExpenseCategory = Transaction::query()
            ->join('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('transactions.user_id', $userId)
            ->where('transactions.type', 'expense')
            ->whereDate('transactions.date', '>=', $monthStart)
            ->whereDate('transactions.date', '<=', $monthEnd)
            ->selectRaw('categories.id as category_id, categories.name as category_name, SUM(transactions.amount) as total')
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('total')
            ->first();

        $currentMonthTotals = $this->totalsForDateRange($userId, $monthStart, $monthEnd);
        $currentExpense = (float) $currentMonthTotals['expense'];

        // Baseline: average expense of previous 3 full months.
        $baselineSum = 0.0;
        $baselineCount = 0;

        for ($i = 1; $i <= 3; $i++) {
            $d = $referenceDate->copy()->subMonthsNoOverflow($i);
            $start = $d->copy()->startOfMonth()->toDateString();
            $end = $d->copy()->endOfMonth()->toDateString();

            $totals = $this->totalsForDateRange($userId, $start, $end);
            $baselineSum += (float) $totals['expense'];
            $baselineCount++;
        }

        $baselineAvg = $baselineCount > 0 ? round($baselineSum / $baselineCount, 2) : 0.0;
        $ratio = $baselineAvg > 0.0 ? round($currentExpense / $baselineAvg, 2) : null;

        // Simple anomaly rule: current month expense > 150% of baseline avg.
        $isAnomaly = $baselineAvg > 0.0 && $currentExpense > ($baselineAvg * 1.5);

        return response()->json([
            'reference_date' => $referenceDate->toDateString(),
            'month' => [
                'start_date' => $monthStart,
                'end_date' => $monthEnd,
            ],
            'top_expense_category' => $topExpenseCategory
                ? [
                    'category_id' => (int) $topExpenseCategory->category_id,
                    'category_name' => $topExpenseCategory->category_name,
                    'total' => round((float) $topExpenseCategory->total, 2),
                ]
                : null,
            'expense_anomaly' => [
                'current_month_expense' => round($currentExpense, 2),
                'baseline_avg_previous_3_months' => round((float) $baselineAvg, 2),
                'ratio' => $ratio,
                'is_anomaly' => $isAnomaly,
            ],
        ]);
    }

    public function topCategories(Request $request)
    {
        $rules = $this->baseRules($request);
        $rules['type'] = ['nullable', 'in:income,expense'];
        $rules['limit'] = ['nullable', 'integer', 'min:1', 'max:50'];

        $validated = $request->validate($rules);
        $userId = $this->resolveUserId($request, $validated);
        $type = $validated['type'] ?? 'expense';
        $limit = (int) ($validated['limit'] ?? 5);

        $baseQuery = Transaction::query()
            ->join('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('transactions.user_id', $userId)
            ->where('transactions.type', $type);

        $this->applyPeriodFilter($baseQuery, $validated);

        $total = (clone $baseQuery)->sum('transactions.amount');
        $total = round((float) $total, 2);

        $topRows = (clone $baseQuery)
            ->selectRaw('categories.id as category_id, categories.name as category_name, SUM(transactions.amount) as total')
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        $top = $topRows->map(function ($row) use ($total) {
            $rowTotal = round((float) $row->total, 2);

            return [
                'category_id' => (int) $row->category_id,
                'category_name' => $row->category_name,
                'total' => $rowTotal,
                'percentage' => $total > 0 ? round(($rowTotal / $total) * 100, 2) : 0.0,
            ];
        })->values();

        $topSum = round((float) $top->sum('total'), 2);
        $otherTotal = round($total - $topSum, 2);

        return response()->json([
            'type' => $type,
            'limit' => $limit,
            'period' => [
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
            ],
            'total' => $total,
            'categories' => $top,
            'other' => $total > 0
                ? [
                    'total' => $otherTotal,
                    'percentage' => $total > 0 ? round(($otherTotal / $total) * 100, 2) : 0.0,
                ]
                : null,
        ]);
    }

    public function budgetVsActual(Request $request)
    {
        $rules = $this->baseRules($request);
        $rules['year'] = ['nullable', 'integer', 'min:1900', 'max:2100'];
        $rules['month'] = ['nullable', 'integer', 'min:1', 'max:12'];
        $rules['category_id'] = ['nullable', 'integer'];

        $validated = $request->validate($rules);
        $userId = $this->resolveUserId($request, $validated);

        $year = (int) ($validated['year'] ?? now()->year);
        $month = (int) ($validated['month'] ?? now()->month);
        $categoryId = !empty($validated['category_id']) ? (int) $validated['category_id'] : null;

        $periodStart = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();

        $budgetQuery = Budget::query()
            ->join('categories', 'categories.id', '=', 'budgets.category_id')
            ->where('budgets.user_id', $userId)
            ->where('budgets.year', $year)
            ->where('budgets.month', $month)
            ->selectRaw('budgets.category_id as category_id, categories.name as category_name, budgets.amount as budget_amount');

        if ($categoryId !== null) {
            $budgetQuery->where('budgets.category_id', $categoryId);
        }

        $budgets = $budgetQuery->get();

        $actualQuery = Transaction::query()
            ->join('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('transactions.user_id', $userId)
            ->where('transactions.type', 'expense')
            ->whereDate('transactions.date', '>=', $periodStart->toDateString())
            ->whereDate('transactions.date', '<=', $periodEnd->toDateString())
            ->selectRaw('transactions.category_id as category_id, categories.name as category_name, SUM(transactions.amount) as actual_amount')
            ->groupBy('transactions.category_id', 'categories.name');

        if ($categoryId !== null) {
            $actualQuery->where('transactions.category_id', $categoryId);
        }

        $actuals = $actualQuery->get();

        $categoryMap = [];

        foreach ($budgets as $row) {
            $categoryMap[(int) $row->category_id] = [
                'category_id' => (int) $row->category_id,
                'category_name' => $row->category_name,
                'has_budget' => true,
                'budget' => round((float) $row->budget_amount, 2),
                'actual' => 0.0,
            ];
        }

        foreach ($actuals as $row) {
            $id = (int) $row->category_id;
            $actual = round((float) $row->actual_amount, 2);

            if (!isset($categoryMap[$id])) {
                $categoryMap[$id] = [
                    'category_id' => $id,
                    'category_name' => $row->category_name,
                    'has_budget' => false,
                    'budget' => 0.0,
                    'actual' => $actual,
                ];
                continue;
            }

            $categoryMap[$id]['actual'] = $actual;
        }

        $categories = array_values(array_map(function (array $row) {
            $budget = (float) $row['budget'];
            $actual = (float) $row['actual'];

            $remaining = round($budget - $actual, 2);
            $utilization = $budget > 0 ? round(($actual / $budget) * 100, 2) : null;

            return [
                ...$row,
                'remaining' => $remaining,
                'utilization_pct' => $utilization,
            ];
        }, $categoryMap));

        usort($categories, function (array $a, array $b) {
            return ($b['actual'] <=> $a['actual'])
                ?: ($b['budget'] <=> $a['budget'])
                ?: strcmp($a['category_name'], $b['category_name']);
        });

        $totalBudget = round((float) collect($categories)->sum('budget'), 2);
        $totalActual = round((float) collect($categories)->sum('actual'), 2);
        $totalRemaining = round($totalBudget - $totalActual, 2);
        $totalUtilization = $totalBudget > 0 ? round(($totalActual / $totalBudget) * 100, 2) : null;

        return response()->json([
            'year' => $year,
            'month' => $month,
            'period' => [
                'start_date' => $periodStart->toDateString(),
                'end_date' => $periodEnd->toDateString(),
            ],
            'totals' => [
                'budget' => $totalBudget,
                'actual' => $totalActual,
                'remaining' => $totalRemaining,
                'utilization_pct' => $totalUtilization,
            ],
            'categories' => $categories,
        ]);
    }

    public function budgetSummary(Request $request)
    {
        $rules = $this->baseRules($request);
        $rules['year'] = ['nullable', 'integer', 'min:1900', 'max:2100'];
        $rules['month'] = ['nullable', 'integer', 'min:1', 'max:12'];
        $rules['limit'] = ['nullable', 'integer', 'min:1', 'max:50'];

        $validated = $request->validate($rules);
        $userId = $this->resolveUserId($request, $validated);

        $year = (int) ($validated['year'] ?? now()->year);
        $month = (int) ($validated['month'] ?? now()->month);
        $limit = (int) ($validated['limit'] ?? 5);

        $periodStart = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();

        $budgets = Budget::query()
            ->join('categories', 'categories.id', '=', 'budgets.category_id')
            ->where('budgets.user_id', $userId)
            ->where('budgets.year', $year)
            ->where('budgets.month', $month)
            ->selectRaw('budgets.category_id as category_id, categories.name as category_name, budgets.amount as budget_amount')
            ->get();

        $actuals = Transaction::query()
            ->join('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('transactions.user_id', $userId)
            ->where('transactions.type', 'expense')
            ->whereDate('transactions.date', '>=', $periodStart->toDateString())
            ->whereDate('transactions.date', '<=', $periodEnd->toDateString())
            ->selectRaw('transactions.category_id as category_id, categories.name as category_name, SUM(transactions.amount) as actual_amount')
            ->groupBy('transactions.category_id', 'categories.name')
            ->get();

        $categoryMap = [];

        foreach ($budgets as $row) {
            $categoryMap[(int) $row->category_id] = [
                'category_id' => (int) $row->category_id,
                'category_name' => $row->category_name,
                'has_budget' => true,
                'budget' => round((float) $row->budget_amount, 2),
                'actual' => 0.0,
            ];
        }

        foreach ($actuals as $row) {
            $id = (int) $row->category_id;
            $actual = round((float) $row->actual_amount, 2);

            if (!isset($categoryMap[$id])) {
                $categoryMap[$id] = [
                    'category_id' => $id,
                    'category_name' => $row->category_name,
                    'has_budget' => false,
                    'budget' => 0.0,
                    'actual' => $actual,
                ];
                continue;
            }

            $categoryMap[$id]['actual'] = $actual;
        }

        $categories = array_values(array_map(function (array $row) {
            $budget = (float) $row['budget'];
            $actual = (float) $row['actual'];

            $remaining = round($budget - $actual, 2);
            $utilization = $budget > 0 ? round(($actual / $budget) * 100, 2) : null;

            return [
                ...$row,
                'remaining' => $remaining,
                'utilization_pct' => $utilization,
            ];
        }, $categoryMap));

        $totalBudget = round((float) collect($categories)->sum('budget'), 2);
        $totalActual = round((float) collect($categories)->sum('actual'), 2);
        $totalRemaining = round($totalBudget - $totalActual, 2);
        $totalUtilization = $totalBudget > 0 ? round(($totalActual / $totalBudget) * 100, 2) : null;

        $overspent = collect($categories)
            ->filter(fn(array $row) => (bool) $row['has_budget'] && (float) $row['actual'] > (float) $row['budget'])
            ->map(function (array $row) {
                $budget = (float) $row['budget'];
                $actual = (float) $row['actual'];
                $overspentAmount = round($actual - $budget, 2);

                return [
                    'category_id' => $row['category_id'],
                    'category_name' => $row['category_name'],
                    'budget' => round($budget, 2),
                    'actual' => round($actual, 2),
                    'overspent' => $overspentAmount,
                    'utilization_pct' => $row['utilization_pct'],
                ];
            })
            ->sortByDesc('overspent')
            ->take($limit)
            ->values();

        return response()->json([
            'year' => $year,
            'month' => $month,
            'limit' => $limit,
            'period' => [
                'start_date' => $periodStart->toDateString(),
                'end_date' => $periodEnd->toDateString(),
            ],
            'totals' => [
                'budget' => $totalBudget,
                'actual' => $totalActual,
                'remaining' => $totalRemaining,
                'utilization_pct' => $totalUtilization,
            ],
            'overspent_categories' => $overspent,
        ]);
    }

    public function budgetTemplateRecommendations(Request $request)
    {
        $rules = $this->baseRules($request);
        $rules['reference_date'] = ['nullable', 'date'];
        $rules['months'] = ['nullable', 'integer', 'min:1', 'max:12'];

        $validated = $request->validate($rules);
        $userId = $this->resolveUserId($request, $validated);

        $referenceDate = Carbon::parse($validated['reference_date'] ?? now()->toDateString());
        $months = (int) ($validated['months'] ?? 3);

        $categories = Category::query()
            ->where('user_id', $userId)
            ->where('type', 'expense')
            ->orderBy('name')
            ->get(['id', 'name']);

        $monthWindows = [];
        for ($i = 1; $i <= $months; $i++) {
            $d = $referenceDate->copy()->subMonthsNoOverflow($i);
            $monthWindows[] = [
                'year' => (int) $d->year,
                'month' => (int) $d->month,
                'start' => $d->copy()->startOfMonth()->toDateString(),
                'end' => $d->copy()->endOfMonth()->toDateString(),
            ];
        }

        $spendByCategoryByMonth = [];
        foreach ($categories as $category) {
            $spendByCategoryByMonth[(int) $category->id] = array_fill(0, $months, 0.0);
        }

        foreach ($monthWindows as $idx => $win) {
            $rows = Transaction::query()
                ->where('user_id', $userId)
                ->where('type', 'expense')
                ->whereDate('date', '>=', $win['start'])
                ->whereDate('date', '<=', $win['end'])
                ->selectRaw('category_id, SUM(amount) as total')
                ->groupBy('category_id')
                ->get();

            foreach ($rows as $row) {
                $categoryId = (int) $row->category_id;
                if (!isset($spendByCategoryByMonth[$categoryId])) {
                    continue;
                }
                $spendByCategoryByMonth[$categoryId][$idx] = round((float) $row->total, 2);
            }
        }

        $recommendations = $categories->map(function ($category) use ($months, $monthWindows, $spendByCategoryByMonth) {
            $id = (int) $category->id;
            $values = $spendByCategoryByMonth[$id] ?? array_fill(0, $months, 0.0);
            $avg = $months > 0 ? round(array_sum($values) / $months, 2) : 0.0;

            $history = [];
            foreach ($monthWindows as $idx => $win) {
                $history[] = [
                    'year' => (int) $win['year'],
                    'month' => (int) $win['month'],
                    'actual' => (float) ($values[$idx] ?? 0.0),
                ];
            }

            return [
                'category_id' => $id,
                'category_name' => $category->name,
                'method' => 'average',
                'months' => $months,
                'recommended_amount' => $avg,
                'history' => $history,
            ];
        })->values();

        return response()->json([
            'reference_date' => $referenceDate->toDateString(),
            'months' => $months,
            'recommendations' => $recommendations,
        ]);
    }
}
