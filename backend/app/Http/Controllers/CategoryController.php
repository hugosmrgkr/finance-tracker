<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
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
            'type' => ['nullable', 'in:income,expense'],
        ];
    }

    public function index(Request $request)
    {
        $validated = $request->validate($this->baseRules($request));
        $userId = $this->resolveUserId($request, $validated);

        $query = Category::query()->where('user_id', $userId);

        if (!empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $rules = $this->baseRules($request);
        $rules['name'] = ['required', 'string', 'max:255'];
        $rules['type'] = ['required', 'in:income,expense'];

        $validated = $request->validate($rules);
        $userId = $this->resolveUserId($request, $validated);

        $category = Category::create([
            'user_id' => $userId,
            'name' => $validated['name'],
            'type' => $validated['type'],
        ]);

        return response()->json($category, 201);
    }

    public function update(Request $request, Category $category)
    {
        $rules = $this->baseRules($request);
        $rules['name'] = ['sometimes', 'string', 'max:255'];
        $rules['type'] = ['sometimes', 'in:income,expense'];

        $validated = $request->validate($rules);
        $userId = $this->resolveUserId($request, $validated);

        if ((int) $category->user_id !== $userId) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $category->fill(collect($validated)->only(['name', 'type'])->all());
        $category->save();

        return response()->json($category);
    }

    public function destroy(Request $request, Category $category)
    {
        $validated = $request->validate($this->baseRules($request));
        $userId = $this->resolveUserId($request, $validated);

        if ((int) $category->user_id !== $userId) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $category->delete();

        return response()->json(null, 204);
    }
}
