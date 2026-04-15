<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WalletController extends Controller
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
        $validated = $request->validate($this->baseRules($request));
        $userId = $this->resolveUserId($request, $validated);

        return response()->json(
            Wallet::query()
                ->where('user_id', $userId)
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $rules = $this->baseRules($request);
        $rules['name'] = ['required', 'string', 'max:64'];

        $validated = $request->validate($rules);
        $userId = $this->resolveUserId($request, $validated);

        try {
            $wallet = Wallet::create([
                'user_id' => $userId,
                'name' => $validated['name'],
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            throw ValidationException::withMessages([
                'name' => ['Wallet name already exists.'],
            ]);
        }

        return response()->json($wallet, 201);
    }

    public function update(Request $request, Wallet $wallet)
    {
        $rules = $this->baseRules($request);
        $rules['name'] = ['sometimes', 'string', 'max:64'];

        $validated = $request->validate($rules);
        $userId = $this->resolveUserId($request, $validated);

        if ((int) $wallet->user_id !== $userId) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (array_key_exists('name', $validated)) {
            $wallet->name = $validated['name'];
        }

        try {
            $wallet->save();
        } catch (\Illuminate\Database\QueryException $e) {
            throw ValidationException::withMessages([
                'name' => ['Wallet name already exists.'],
            ]);
        }

        return response()->json($wallet);
    }

    public function destroy(Request $request, Wallet $wallet)
    {
        $validated = $request->validate($this->baseRules($request));
        $userId = $this->resolveUserId($request, $validated);

        if ((int) $wallet->user_id !== $userId) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $wallet->delete();

        return response()->json(null, 204);
    }
}
