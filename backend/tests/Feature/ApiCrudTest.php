<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension is required to run database feature tests.');
        }

        parent::setUp();
    }

    public function test_can_crud_categories_and_transactions(): void
    {
        $user = User::factory()->create();

        $categoryResponse = $this->postJson('/api/categories', [
            'user_id' => $user->id,
            'name' => 'Salary',
            'type' => 'income',
        ]);

        $categoryResponse->assertCreated();
        $categoryId = $categoryResponse->json('id');

        $this->getJson('/api/categories?user_id='.$user->id)
            ->assertOk()
            ->assertJsonFragment(['id' => $categoryId]);

        $transactionResponse = $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $categoryId,
            'amount' => 1500000,
            'type' => 'income',
            'date' => '2026-04-01',
            'note' => 'April salary',
        ]);

        $transactionResponse->assertCreated();
        $transactionId = $transactionResponse->json('id');

        $this->getJson('/api/transactions?user_id='.$user->id)
            ->assertOk()
            ->assertJsonFragment(['id' => $transactionId]);

        $this->putJson('/api/transactions/'.$transactionId, [
            'user_id' => $user->id,
            'amount' => 2000000,
        ])->assertOk();

        $this->deleteJson('/api/transactions/'.$transactionId, [
            'user_id' => $user->id,
        ])->assertNoContent();

        $this->assertDatabaseMissing(Transaction::class, ['id' => $transactionId]);

        $this->deleteJson('/api/categories/'.$categoryId, [
            'user_id' => $user->id,
        ])->assertNoContent();

        $this->assertDatabaseMissing(Category::class, ['id' => $categoryId]);
    }

    public function test_transaction_type_must_match_category_type(): void
    {
        $user = User::factory()->create();
        $category = Category::create([
            'user_id' => $user->id,
            'name' => 'Groceries',
            'type' => 'expense',
        ]);

        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $category->id,
            'amount' => 50000,
            'type' => 'income',
            'date' => '2026-04-01',
        ])->assertStatus(422);
    }
}
