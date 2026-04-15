<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
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

        return response()->json([
            'year' => $year,
            'months' => array_values($monthMap),
        ]);
    }
}
