<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\BudgetTemplate;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BudgetController extends Controller
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
        ];
    }

    public function index(Request $request)
    {
        $rules = $this->baseRules($request);
        $rules += [
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'category_id' => ['nullable', 'integer'],
        ];

        $validated = $request->validate($rules);
        $userId = $this->resolveUserId($request, $validated);

        $query = Budget::query()
            ->where('user_id', $userId)
            ->with('category')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderByDesc('id');

        if (!empty($validated['year'])) {
            $query->where('year', (int) $validated['year']);
        }

        if (!empty($validated['month'])) {
            $query->where('month', (int) $validated['month']);
        }

        if (!empty($validated['category_id'])) {
            $query->where('category_id', (int) $validated['category_id']);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $rules = $this->baseRules($request);
        $rules += [
            'category_id' => ['required', 'integer'],
            'year' => ['required', 'integer', 'min:1900', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:255'],
        ];

        $validated = $request->validate($rules);
        $userId = $this->resolveUserId($request, $validated);

        $category = Category::query()
            ->whereKey((int) $validated['category_id'])
            ->where('user_id', $userId)
            ->first();

        if (!$category) {
            return response()->json(['message' => 'Invalid category_id'], 422);
        }

        if ($category->type !== 'expense') {
            return response()->json(['message' => 'Budgets can only be set for expense categories'], 422);
        }

        $exists = Budget::query()
            ->where('user_id', $userId)
            ->where('category_id', (int) $validated['category_id'])
            ->where('year', (int) $validated['year'])
            ->where('month', (int) $validated['month'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Budget already exists for this category and month'], 422);
        }

        $budget = Budget::create([
            'user_id' => $userId,
            'category_id' => (int) $validated['category_id'],
            'year' => (int) $validated['year'],
            'month' => (int) $validated['month'],
            'amount' => $validated['amount'],
            'note' => $validated['note'] ?? null,
        ]);

        $budget->load('category');

        return response()->json($budget, 201);
    }

    public function update(Request $request, Budget $budget)
    {
        $rules = $this->baseRules($request);
        $rules += [
            'category_id' => ['sometimes', 'integer'],
            'year' => ['sometimes', 'integer', 'min:1900', 'max:2100'],
            'month' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'amount' => ['sometimes', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:255'],
        ];

        $validated = $request->validate($rules);
        $userId = $this->resolveUserId($request, $validated);

        if ((int) $budget->user_id !== $userId) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $newCategoryId = array_key_exists('category_id', $validated)
            ? (int) $validated['category_id']
            : (int) $budget->category_id;

        $newYear = array_key_exists('year', $validated)
            ? (int) $validated['year']
            : (int) $budget->year;

        $newMonth = array_key_exists('month', $validated)
            ? (int) $validated['month']
            : (int) $budget->month;

        $category = Category::query()
            ->whereKey($newCategoryId)
            ->where('user_id', $userId)
            ->first();

        if (!$category) {
            return response()->json(['message' => 'Invalid category_id'], 422);
        }

        if ($category->type !== 'expense') {
            return response()->json(['message' => 'Budgets can only be set for expense categories'], 422);
        }

        $exists = Budget::query()
            ->where('user_id', $userId)
            ->where('category_id', $newCategoryId)
            ->where('year', $newYear)
            ->where('month', $newMonth)
            ->where('id', '!=', (int) $budget->id)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Budget already exists for this category and month'], 422);
        }

        $budget->fill([
            'category_id' => $newCategoryId,
            'year' => $newYear,
            'month' => $newMonth,
        ]);

        $budget->fill(collect($validated)->only(['amount', 'note'])->all());
        $budget->save();

        $budget->load('category');

        return response()->json($budget);
    }

    public function destroy(Request $request, Budget $budget)
    {
        $validated = $request->validate($this->baseRules($request));
        $userId = $this->resolveUserId($request, $validated);

        if ((int) $budget->user_id !== $userId) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $budget->delete();

        return response()->json(null, 204);
    }

    public function copyFromPreviousMonth(Request $request)
    {
        $rules = $this->baseRules($request);
        $rules += [
            'year' => ['nullable', 'integer', 'min:1900', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'overwrite' => ['nullable', 'boolean'],
        ];

        $validated = $request->validate($rules);
        $userId = $this->resolveUserId($request, $validated);

        $targetYear = (int) ($validated['year'] ?? now()->year);
        $targetMonth = (int) ($validated['month'] ?? now()->month);
        $overwrite = (bool) ($validated['overwrite'] ?? false);

        $target = Carbon::createFromDate($targetYear, $targetMonth, 1)->startOfMonth();
        $source = $target->copy()->subMonthNoOverflow();

        $sourceBudgets = Budget::query()
            ->where('user_id', $userId)
            ->where('year', (int) $source->year)
            ->where('month', (int) $source->month)
            ->get();

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($sourceBudgets as $budget) {
            $attributes = [
                'user_id' => $userId,
                'category_id' => (int) $budget->category_id,
                'year' => (int) $target->year,
                'month' => (int) $target->month,
            ];

            $values = [
                'amount' => $budget->amount,
                'note' => $budget->note,
            ];

            $existing = Budget::query()->where($attributes)->first();

            if ($existing) {
                if (!$overwrite) {
                    $skipped++;
                    continue;
                }

                $existing->fill($values);
                $existing->save();
                $updated++;
                continue;
            }

            Budget::create([...$attributes, ...$values]);
            $created++;
        }

        return response()->json([
            'source' => [
                'year' => (int) $source->year,
                'month' => (int) $source->month,
            ],
            'target' => [
                'year' => (int) $target->year,
                'month' => (int) $target->month,
            ],
            'overwrite' => $overwrite,
            'result' => [
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'source_count' => $sourceBudgets->count(),
            ],
        ]);
    }

    public function applyTemplates(Request $request)
    {
        $rules = $this->baseRules($request);
        $rules += [
            'year' => ['required', 'integer', 'min:1900', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'overwrite' => ['nullable', 'boolean'],
        ];

        $validated = $request->validate($rules);
        $userId = $this->resolveUserId($request, $validated);

        $year = (int) $validated['year'];
        $month = (int) $validated['month'];
        $overwrite = (bool) ($validated['overwrite'] ?? false);

        $templates = BudgetTemplate::query()
            ->where('user_id', $userId)
            ->get();

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($templates as $template) {
            $attributes = [
                'user_id' => $userId,
                'category_id' => (int) $template->category_id,
                'year' => $year,
                'month' => $month,
            ];

            $values = [
                'amount' => $template->amount,
                'note' => $template->note,
            ];

            $existing = Budget::query()->where($attributes)->first();
            if ($existing) {
                if (!$overwrite) {
                    $skipped++;
                    continue;
                }

                $existing->fill($values);
                $existing->save();
                $updated++;
                continue;
            }

            Budget::create([...$attributes, ...$values]);
            $created++;
        }

        return response()->json([
            'year' => $year,
            'month' => $month,
            'overwrite' => $overwrite,
            'result' => [
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'template_count' => $templates->count(),
            ],
        ]);
    }

    public function copyBetweenMonths(Request $request)
    {
        $rules = $this->baseRules($request);
        $rules += [
            'from_year' => ['required', 'integer', 'min:1900', 'max:2100'],
            'from_month' => ['required', 'integer', 'min:1', 'max:12'],
            'to_year' => ['required', 'integer', 'min:1900', 'max:2100'],
            'to_month' => ['required', 'integer', 'min:1', 'max:12'],
            'overwrite' => ['nullable', 'boolean'],
        ];

        $validated = $request->validate($rules);
        $userId = $this->resolveUserId($request, $validated);
        $overwrite = (bool) ($validated['overwrite'] ?? false);

        $from = Carbon::createFromDate((int) $validated['from_year'], (int) $validated['from_month'], 1)->startOfMonth();
        $to = Carbon::createFromDate((int) $validated['to_year'], (int) $validated['to_month'], 1)->startOfMonth();

        $sourceBudgets = Budget::query()
            ->where('user_id', $userId)
            ->where('year', (int) $from->year)
            ->where('month', (int) $from->month)
            ->get();

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($sourceBudgets as $budget) {
            $attributes = [
                'user_id' => $userId,
                'category_id' => (int) $budget->category_id,
                'year' => (int) $to->year,
                'month' => (int) $to->month,
            ];

            $values = [
                'amount' => $budget->amount,
                'note' => $budget->note,
            ];

            $existing = Budget::query()->where($attributes)->first();
            if ($existing) {
                if (!$overwrite) {
                    $skipped++;
                    continue;
                }

                $existing->fill($values);
                $existing->save();
                $updated++;
                continue;
            }

            Budget::create([...$attributes, ...$values]);
            $created++;
        }

        return response()->json([
            'from' => [
                'year' => (int) $from->year,
                'month' => (int) $from->month,
            ],
            'to' => [
                'year' => (int) $to->year,
                'month' => (int) $to->month,
            ],
            'overwrite' => $overwrite,
            'result' => [
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
                'source_count' => $sourceBudgets->count(),
            ],
        ]);
    }
}
