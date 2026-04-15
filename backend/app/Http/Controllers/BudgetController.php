<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Category;
use Illuminate\Http\Request;

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
}
