<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetTemplateApiTest extends TestCase
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

    public function test_can_crud_budget_templates_and_apply_to_month(): void
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

        $createRent = $this->postJson('/api/budget-templates', [
            'user_id' => $user->id,
            'category_id' => $rent->id,
            'amount' => 500,
            'note' => 'Default rent',
        ]);
        $createRent->assertCreated();
        $rentTemplateId = $createRent->json('id');

        $createFood = $this->postJson('/api/budget-templates', [
            'user_id' => $user->id,
            'category_id' => $food->id,
            'amount' => 300,
        ]);
        $createFood->assertCreated();
        $foodTemplateId = $createFood->json('id');

        $index = $this->getJson('/api/budget-templates?user_id=' . $user->id);
        $index->assertOk();
        $index->assertJsonFragment(['id' => $rentTemplateId]);
        $index->assertJsonFragment(['id' => $foodTemplateId]);

        $this->putJson('/api/budget-templates/' . $foodTemplateId, [
            'user_id' => $user->id,
            'amount' => 350,
        ])->assertOk();

        // Apply templates to April 2026.
        $apply = $this->postJson('/api/budgets/apply-templates', [
            'user_id' => $user->id,
            'year' => 2026,
            'month' => 4,
        ]);
        $apply->assertOk();
        $apply->assertJsonPath('result.created', 2);

        $budgets = $this->getJson('/api/budgets?user_id=' . $user->id . '&year=2026&month=4');
        $budgets->assertOk();

        $rows = collect($budgets->json());
        $rentBudget = $rows->firstWhere('category_id', $rent->id);
        $this->assertNotNull($rentBudget);
        $this->assertSame(500.0, (float) $rentBudget['amount']);

        $foodBudget = $rows->firstWhere('category_id', $food->id);
        $this->assertNotNull($foodBudget);
        $this->assertSame(350.0, (float) $foodBudget['amount']);

        // Overwrite should update existing budget.
        $this->putJson('/api/budget-templates/' . $rentTemplateId, [
            'user_id' => $user->id,
            'amount' => 550,
        ])->assertOk();

        $apply2 = $this->postJson('/api/budgets/apply-templates', [
            'user_id' => $user->id,
            'year' => 2026,
            'month' => 4,
            'overwrite' => true,
        ]);
        $apply2->assertOk();
        $apply2->assertJsonPath('result.updated', 2);

        $budgets2 = $this->getJson('/api/budgets?user_id=' . $user->id . '&year=2026&month=4');
        $budgets2->assertOk();
        $rows2 = collect($budgets2->json());
        $rentBudget2 = $rows2->firstWhere('category_id', $rent->id);
        $this->assertNotNull($rentBudget2);
        $this->assertSame(550.0, (float) $rentBudget2['amount']);

        $this->deleteJson('/api/budget-templates/' . $rentTemplateId, [
            'user_id' => $user->id,
        ])->assertNoContent();
    }

    public function test_cannot_create_template_for_income_category(): void
    {
        $user = User::factory()->create();

        $salary = Category::create([
            'user_id' => $user->id,
            'name' => 'Salary',
            'type' => 'income',
        ]);

        $this->postJson('/api/budget-templates', [
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'amount' => 1000,
        ])->assertStatus(422);
    }
}
