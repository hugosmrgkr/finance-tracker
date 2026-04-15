<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Http\Request;

class TransactionController extends Controller
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
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'type' => ['nullable', 'in:income,expense'],
            'category_id' => ['nullable', 'integer'],
            'wallet_id' => ['nullable', 'integer'],
        ];

        $validated = $request->validate($rules);
        $userId = $this->resolveUserId($request, $validated);

        $query = Transaction::query()
            ->where('user_id', $userId)
            ->with(['category', 'wallet'])
            ->orderByDesc('date')
            ->orderByDesc('id');

        if (!empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (!empty($validated['category_id'])) {
            $query->where('category_id', (int) $validated['category_id']);
        }

        if (!empty($validated['wallet_id'])) {
            $query->where('wallet_id', (int) $validated['wallet_id']);
        }

        if (!empty($validated['start_date'])) {
            $query->whereDate('date', '>=', $validated['start_date']);
        }

        if (!empty($validated['end_date'])) {
            $query->whereDate('date', '<=', $validated['end_date']);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $rules = $this->baseRules($request);
        $rules += [
            'category_id' => ['required', 'integer'],
            'wallet_id' => ['nullable', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'type' => ['required', 'in:income,expense'],
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string'],
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

        if ($validated['type'] !== $category->type) {
            return response()->json(['message' => 'Transaction type must match category type'], 422);
        }

        $walletId = null;
        if (array_key_exists('wallet_id', $validated) && $validated['wallet_id'] !== null) {
            $walletId = (int) $validated['wallet_id'];
            $wallet = Wallet::query()
                ->whereKey($walletId)
                ->where('user_id', $userId)
                ->first();

            if (!$wallet) {
                return response()->json(['message' => 'Invalid wallet_id'], 422);
            }
        }

        $transaction = Transaction::create([
            'user_id' => $userId,
            'category_id' => (int) $validated['category_id'],
            'wallet_id' => $walletId,
            'amount' => $validated['amount'],
            'type' => $validated['type'],
            'date' => $validated['date'],
            'note' => $validated['note'] ?? null,
        ]);

        $transaction->load(['category', 'wallet']);

        return response()->json($transaction, 201);
    }

    public function update(Request $request, Transaction $transaction)
    {
        $rules = $this->baseRules($request);
        $rules += [
            'category_id' => ['sometimes', 'integer'],
            'wallet_id' => ['sometimes', 'nullable', 'integer'],
            'amount' => ['sometimes', 'numeric', 'min:0.01'],
            'type' => ['sometimes', 'in:income,expense'],
            'date' => ['sometimes', 'date'],
            'note' => ['nullable', 'string'],
        ];

        $validated = $request->validate($rules);
        $userId = $this->resolveUserId($request, $validated);

        if ((int) $transaction->user_id !== $userId) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $categoryId = array_key_exists('category_id', $validated)
            ? (int) $validated['category_id']
            : (int) $transaction->category_id;

        $category = Category::query()
            ->whereKey($categoryId)
            ->where('user_id', $userId)
            ->first();

        if (!$category) {
            return response()->json(['message' => 'Invalid category_id'], 422);
        }

        $type = array_key_exists('type', $validated)
            ? $validated['type']
            : $transaction->type;

        if ($type !== $category->type) {
            return response()->json(['message' => 'Transaction type must match category type'], 422);
        }

        if (array_key_exists('wallet_id', $validated)) {
            if ($validated['wallet_id'] === null) {
                $transaction->wallet_id = null;
            } else {
                $walletId = (int) $validated['wallet_id'];
                $wallet = Wallet::query()
                    ->whereKey($walletId)
                    ->where('user_id', $userId)
                    ->first();

                if (!$wallet) {
                    return response()->json(['message' => 'Invalid wallet_id'], 422);
                }

                $transaction->wallet_id = $walletId;
            }
        }

        $transaction->fill(collect($validated)->only(['category_id', 'amount', 'type', 'date', 'note'])->all());
        $transaction->save();

        $transaction->load(['category', 'wallet']);

        return response()->json($transaction);
    }

    public function destroy(Request $request, Transaction $transaction)
    {
        $validated = $request->validate($this->baseRules($request));
        $userId = $this->resolveUserId($request, $validated);

        if ((int) $transaction->user_id !== $userId) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $transaction->delete();

        return response()->json(null, 204);
    }
}
