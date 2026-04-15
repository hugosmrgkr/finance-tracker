<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $defaultConnection = config('database.default');
        $driver = config("database.connections.{$defaultConnection}.driver");

        if ($driver === 'sqlite' && !extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension is required to run sqlite database feature tests.');
        }
    }

    public function test_can_crud_wallets(): void
    {
        $user = User::factory()->create();

        $create = $this->postJson('/api/wallets', [
            'user_id' => $user->id,
            'name' => 'Cash',
        ]);

        $create->assertCreated();
        $walletId = $create->json('id');

        $this->getJson('/api/wallets?user_id=' . $user->id)
            ->assertOk()
            ->assertJsonFragment(['id' => $walletId]);

        $this->putJson('/api/wallets/' . $walletId, [
            'user_id' => $user->id,
            'name' => 'Cash (Updated)',
        ])->assertOk();

        $this->deleteJson('/api/wallets/' . $walletId, [
            'user_id' => $user->id,
        ])->assertNoContent();
    }

    public function test_wallet_name_must_be_unique_per_user(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/wallets', [
            'user_id' => $user->id,
            'name' => 'Cash',
        ])->assertCreated();

        $this->postJson('/api/wallets', [
            'user_id' => $user->id,
            'name' => 'Cash',
        ])->assertStatus(422);
    }

    public function test_can_create_transaction_with_wallet_id_and_reject_invalid_wallet_id(): void
    {
        $user = User::factory()->create();

        $wallet = $this->postJson('/api/wallets', [
            'user_id' => $user->id,
            'name' => 'BCA',
        ])->assertCreated()->json();

        $category = Category::create([
            'user_id' => $user->id,
            'name' => 'Salary',
            'type' => 'income',
        ]);

        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $category->id,
            'wallet_id' => $wallet['id'],
            'amount' => 1500000,
            'type' => 'income',
            'date' => '2026-04-01',
        ])
            ->assertCreated()
            ->assertJsonFragment(['wallet_id' => $wallet['id']]);

        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $category->id,
            'wallet_id' => 999999,
            'amount' => 1500000,
            'type' => 'income',
            'date' => '2026-04-01',
        ])->assertStatus(422);
    }
}
