<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension is required to run database feature tests.');
        }

        parent::setUp();
    }

    public function test_summary_and_breakdown_endpoints_return_expected_values(): void
    {
        $user = User::factory()->create();

        $salary = Category::create([
            'user_id' => $user->id,
            'name' => 'Salary',
            'type' => 'income',
        ]);

        $freelance = Category::create([
            'user_id' => $user->id,
            'name' => 'Freelance',
            'type' => 'income',
        ]);

        $food = Category::create([
            'user_id' => $user->id,
            'name' => 'Food',
            'type' => 'expense',
        ]);

        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'amount' => 1000000,
            'type' => 'income',
            'date' => '2026-04-01',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $freelance->id,
            'amount' => 250000,
            'type' => 'income',
            'date' => '2026-04-10',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $food->id,
            'amount' => 200000,
            'type' => 'expense',
            'date' => '2026-04-12',
        ])->assertCreated();

        $summary = $this->getJson('/api/analytics/summary?user_id='.$user->id.'&start_date=2026-04-01&end_date=2026-04-30');
        $summary->assertOk();
        $summary->assertJson([
            'income' => 1250000,
            'expense' => 200000,
            'balance' => 1050000,
            'period' => [
                'start_date' => '2026-04-01',
                'end_date' => '2026-04-30',
            ],
        ]);

        $byCategory = $this->getJson('/api/analytics/by-category?user_id='.$user->id.'&start_date=2026-04-01&end_date=2026-04-30');
        $byCategory->assertOk();
        $byCategory->assertJsonFragment([
            'category_id' => $salary->id,
            'category_name' => 'Salary',
            'type' => 'income',
            'total' => 1000000,
        ]);
        $byCategory->assertJsonFragment([
            'category_id' => $freelance->id,
            'category_name' => 'Freelance',
            'type' => 'income',
            'total' => 250000,
        ]);
        $byCategory->assertJsonFragment([
            'category_id' => $food->id,
            'category_name' => 'Food',
            'type' => 'expense',
            'total' => 200000,
        ]);

        $monthly = $this->getJson('/api/analytics/monthly?user_id='.$user->id.'&year=2026');
        $monthly->assertOk();
        $monthly->assertJsonPath('year', 2026);

        $april = collect($monthly->json('months'))->firstWhere('month', 4);
        $this->assertNotNull($april);
        $this->assertSame(1250000.0, (float) $april['income']);
        $this->assertSame(200000.0, (float) $april['expense']);
        $this->assertSame(1050000.0, (float) $april['balance']);
    }
}
