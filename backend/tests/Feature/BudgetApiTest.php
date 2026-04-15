<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetApiTest extends TestCase
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

    public function test_can_crud_budgets(): void
    {
        $user = User::factory()->create();

        $rent = Category::create([
            'user_id' => $user->id,
            'name' => 'Rent',
            'type' => 'expense',
        ]);

        $create = $this->postJson('/api/budgets', [
            'user_id' => $user->id,
            'category_id' => $rent->id,
            'year' => 2026,
            'month' => 4,
            'amount' => 500,
            'note' => 'April budget',
        ]);

        $create->assertCreated();
        $budgetId = $create->json('id');

        $index = $this->getJson('/api/budgets?user_id=' . $user->id . '&year=2026&month=4');
        $index->assertOk();
        $index->assertJsonFragment([
            'id' => $budgetId,
            'year' => 2026,
            'month' => 4,
        ]);

        $update = $this->putJson('/api/budgets/' . $budgetId, [
            'user_id' => $user->id,
            'amount' => 600,
            'note' => 'Updated',
        ]);
        $update->assertOk();
        $this->assertSame(600.0, (float) $update->json('amount'));
        $this->assertSame('Updated', $update->json('note'));

        $this->deleteJson('/api/budgets/' . $budgetId, [
            'user_id' => $user->id,
        ])->assertNoContent();
    }

    public function test_cannot_create_budget_for_income_category(): void
    {
        $user = User::factory()->create();

        $salary = Category::create([
            'user_id' => $user->id,
            'name' => 'Salary',
            'type' => 'income',
        ]);

        $this->postJson('/api/budgets', [
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'year' => 2026,
            'month' => 4,
            'amount' => 1000,
        ])->assertStatus(422);
    }

    public function test_budget_vs_actual_returns_expected_monthly_values(): void
    {
        $user = User::factory()->create();

        $rent = Category::create([
            'user_id' => $user->id,
            'name' => 'Rent',
            'type' => 'expense',
        ]);

        $food = Category::create([
            'user_id' => $user->id,
            'name' => 'Food',
            'type' => 'expense',
        ]);

        $transport = Category::create([
            'user_id' => $user->id,
            'name' => 'Transport',
            'type' => 'expense',
        ]);

        $this->postJson('/api/budgets', [
            'user_id' => $user->id,
            'category_id' => $rent->id,
            'year' => 2026,
            'month' => 4,
            'amount' => 500,
        ])->assertCreated();

        $this->postJson('/api/budgets', [
            'user_id' => $user->id,
            'category_id' => $food->id,
            'year' => 2026,
            'month' => 4,
            'amount' => 300,
        ])->assertCreated();

        // Actual expenses in April 2026.
        foreach ([
            [$rent->id, 450, '2026-04-05'],
            [$food->id, 100, '2026-04-06'],
            [$transport->id, 50, '2026-04-07'],
        ] as [$categoryId, $amount, $date]) {
            $this->postJson('/api/transactions', [
                'user_id' => $user->id,
                'category_id' => $categoryId,
                'amount' => $amount,
                'type' => 'expense',
                'date' => $date,
            ])->assertCreated();
        }

        $response = $this->getJson('/api/analytics/budget-vs-actual?user_id=' . $user->id . '&year=2026&month=4');
        $response->assertOk();
        $response->assertJsonPath('year', 2026);
        $response->assertJsonPath('month', 4);

        $this->assertSame(800.0, (float) $response->json('totals.budget'));
        $this->assertSame(600.0, (float) $response->json('totals.actual'));
        $this->assertSame(200.0, (float) $response->json('totals.remaining'));
        $this->assertSame(75.0, (float) $response->json('totals.utilization_pct'));

        $categories = collect($response->json('categories'));

        $rentRow = $categories->firstWhere('category_id', $rent->id);
        $this->assertNotNull($rentRow);
        $this->assertTrue((bool) $rentRow['has_budget']);
        $this->assertSame(500.0, (float) $rentRow['budget']);
        $this->assertSame(450.0, (float) $rentRow['actual']);
        $this->assertSame(50.0, (float) $rentRow['remaining']);
        $this->assertSame(90.0, (float) $rentRow['utilization_pct']);

        $transportRow = $categories->firstWhere('category_id', $transport->id);
        $this->assertNotNull($transportRow);
        $this->assertFalse((bool) $transportRow['has_budget']);
        $this->assertSame(0.0, (float) $transportRow['budget']);
        $this->assertSame(50.0, (float) $transportRow['actual']);
        $this->assertSame(-50.0, (float) $transportRow['remaining']);
        $this->assertNull($transportRow['utilization_pct']);
    }
}
