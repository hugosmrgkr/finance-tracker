<?php

namespace App\Http\Controllers;

use App\Models\BudgetTemplate;
use App\Models\Category;
use Illuminate\Http\Request;

class BudgetTemplateController extends Controller
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
            'category_id' => ['nullable', 'integer'],
        ];

        $validated = $request->validate($rules);
        $userId = $this->resolveUserId($request, $validated);

        $query = BudgetTemplate::query()
            ->where('user_id', $userId)
            ->with('category')
            ->orderByDesc('id');

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
            return response()->json(['message' => 'Budget templates can only be set for expense categories'], 422);
        }

        $exists = BudgetTemplate::query()
            ->where('user_id', $userId)
            ->where('category_id', (int) $validated['category_id'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Template already exists for this category'], 422);
        }

        $template = BudgetTemplate::create([
            'user_id' => $userId,
            'category_id' => (int) $validated['category_id'],
            'amount' => $validated['amount'],
            'note' => $validated['note'] ?? null,
        ]);

        $template->load('category');

        return response()->json($template, 201);
    }

    public function update(Request $request, BudgetTemplate $budgetTemplate)
    {
        $rules = $this->baseRules($request);
        $rules += [
            'category_id' => ['sometimes', 'integer'],
            'amount' => ['sometimes', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:255'],
        ];

        $validated = $request->validate($rules);
        $userId = $this->resolveUserId($request, $validated);

        if ((int) $budgetTemplate->user_id !== $userId) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $newCategoryId = array_key_exists('category_id', $validated)
            ? (int) $validated['category_id']
            : (int) $budgetTemplate->category_id;

        $category = Category::query()
            ->whereKey($newCategoryId)
            ->where('user_id', $userId)
            ->first();

        if (!$category) {
            return response()->json(['message' => 'Invalid category_id'], 422);
        }

        if ($category->type !== 'expense') {
            return response()->json(['message' => 'Budget templates can only be set for expense categories'], 422);
        }

        $exists = BudgetTemplate::query()
            ->where('user_id', $userId)
            ->where('category_id', $newCategoryId)
            ->where('id', '!=', (int) $budgetTemplate->id)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Template already exists for this category'], 422);
        }

        $budgetTemplate->fill([
            'category_id' => $newCategoryId,
        ]);

        $budgetTemplate->fill(collect($validated)->only(['amount', 'note'])->all());
        $budgetTemplate->save();

        $budgetTemplate->load('category');

        return response()->json($budgetTemplate);
    }

    public function destroy(Request $request, BudgetTemplate $budgetTemplate)
    {
        $validated = $request->validate($this->baseRules($request));
        $userId = $this->resolveUserId($request, $validated);

        if ((int) $budgetTemplate->user_id !== $userId) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $budgetTemplate->delete();

        return response()->json(null, 204);
    }
}
