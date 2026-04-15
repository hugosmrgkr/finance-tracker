<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetRecommendationApiTest extends TestCase
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

    public function test_budget_template_recommendations_returns_average_of_previous_months(): void
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

        // Reference date: April 15, 2026 -> use previous 3 full months: Mar, Feb, Jan 2026.
        // Rent spend: Jan=300, Feb=0, Mar=600 => avg = 300
        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $rent->id,
            'amount' => 300,
            'type' => 'expense',
            'date' => '2026-01-05',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $rent->id,
            'amount' => 600,
            'type' => 'expense',
            'date' => '2026-03-05',
        ])->assertCreated();

        // Food spend: Jan=150, Feb=150, Mar=150 => avg = 150
        foreach (['2026-01-10', '2026-02-10', '2026-03-10'] as $d) {
            $this->postJson('/api/transactions', [
                'user_id' => $user->id,
                'category_id' => $food->id,
                'amount' => 150,
                'type' => 'expense',
                'date' => $d,
            ])->assertCreated();
        }

        $response = $this->getJson('/api/analytics/budget-template-recommendations?user_id=' . $user->id . '&reference_date=2026-04-15&months=3');
        $response->assertOk();
        $response->assertJsonPath('reference_date', '2026-04-15');
        $response->assertJsonPath('months', 3);

        $rows = collect($response->json('recommendations'));

        $rentRow = $rows->firstWhere('category_id', $rent->id);
        $this->assertNotNull($rentRow);
        $this->assertSame('average', $rentRow['method']);
        $this->assertSame(3, $rentRow['months']);
        $this->assertSame(300.0, (float) $rentRow['recommended_amount']);

        $foodRow = $rows->firstWhere('category_id', $food->id);
        $this->assertNotNull($foodRow);
        $this->assertSame(150.0, (float) $foodRow['recommended_amount']);
    }
}
