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
        parent::setUp();

        $defaultConnection = config('database.default');
        $driver = config("database.connections.{$defaultConnection}.driver");

        if ($driver === 'sqlite' && !extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension is required to run sqlite database feature tests.');
        }
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

        $summary = $this->getJson('/api/analytics/summary?user_id=' . $user->id . '&start_date=2026-04-01&end_date=2026-04-30');
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

        $byCategory = $this->getJson('/api/analytics/by-category?user_id=' . $user->id . '&start_date=2026-04-01&end_date=2026-04-30');
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

        $monthly = $this->getJson('/api/analytics/monthly?user_id=' . $user->id . '&year=2026');
        $monthly->assertOk();
        $monthly->assertJsonPath('year', 2026);

        $april = collect($monthly->json('months'))->firstWhere('month', 4);
        $this->assertNotNull($april);
        $this->assertSame(1250000.0, (float) $april['income']);
        $this->assertSame(200000.0, (float) $april['expense']);
        $this->assertSame(1050000.0, (float) $april['balance']);
    }

    public function test_monthly_growth_endpoint_returns_expected_growth_rates(): void
    {
        $user = User::factory()->create();

        $salary = Category::create([
            'user_id' => $user->id,
            'name' => 'Salary',
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
            'amount' => 1000,
            'type' => 'income',
            'date' => '2026-01-05',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $food->id,
            'amount' => 500,
            'type' => 'expense',
            'date' => '2026-01-10',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'amount' => 1200,
            'type' => 'income',
            'date' => '2026-02-05',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $food->id,
            'amount' => 400,
            'type' => 'expense',
            'date' => '2026-02-10',
        ])->assertCreated();

        $response = $this->getJson('/api/analytics/monthly-growth?user_id=' . $user->id . '&year=2026');
        $response->assertOk();
        $response->assertJsonPath('year', 2026);

        $months = collect($response->json('months'));

        $january = $months->firstWhere('month', 1);
        $this->assertNotNull($january);
        $this->assertSame(1000.0, (float) $january['income']);
        $this->assertSame(500.0, (float) $january['expense']);
        $this->assertSame(500.0, (float) $january['balance']);
        $this->assertNull($january['income_growth_rate']);
        $this->assertNull($january['expense_growth_rate']);
        $this->assertNull($january['balance_growth_rate']);

        $february = $months->firstWhere('month', 2);
        $this->assertNotNull($february);
        $this->assertSame(1200.0, (float) $february['income']);
        $this->assertSame(400.0, (float) $february['expense']);
        $this->assertSame(800.0, (float) $february['balance']);
        $this->assertSame(20.0, (float) $february['income_growth_rate']);
        $this->assertSame(-20.0, (float) $february['expense_growth_rate']);
        $this->assertSame(60.0, (float) $february['balance_growth_rate']);
    }

    public function test_monthly_growth_supports_metric_and_compact_mode(): void
    {
        $user = User::factory()->create();

        $salary = Category::create([
            'user_id' => $user->id,
            'name' => 'Salary',
            'type' => 'income',
        ]);

        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'amount' => 1000,
            'type' => 'income',
            'date' => '2026-01-05',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'amount' => 1500,
            'type' => 'income',
            'date' => '2026-02-05',
        ])->assertCreated();

        $response = $this->getJson('/api/analytics/monthly-growth?user_id=' . $user->id . '&year=2026&metric=income&compact=1');
        $response->assertOk();
        $response->assertJsonPath('year', 2026);
        $response->assertJsonPath('metric', 'income');
        $response->assertJsonPath('compact', true);

        $january = collect($response->json('months'))->firstWhere('month', 1);
        $this->assertNotNull($january);
        $this->assertArrayHasKey('value', $january);
        $this->assertArrayHasKey('growth_rate', $january);
        $this->assertArrayNotHasKey('balance', $january);
        $this->assertSame(1000.0, (float) $january['value']);
        $this->assertNull($january['growth_rate']);

        $february = collect($response->json('months'))->firstWhere('month', 2);
        $this->assertNotNull($february);
        $this->assertSame(1500.0, (float) $february['value']);
        $this->assertSame(50.0, (float) $february['growth_rate']);
    }

    public function test_yearly_growth_endpoint_returns_expected_values(): void
    {
        $user = User::factory()->create();

        $salary = Category::create([
            'user_id' => $user->id,
            'name' => 'Salary',
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
            'amount' => 1000,
            'type' => 'income',
            'date' => '2025-03-05',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $food->id,
            'amount' => 600,
            'type' => 'expense',
            'date' => '2025-03-10',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'amount' => 1800,
            'type' => 'income',
            'date' => '2026-04-05',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $food->id,
            'amount' => 900,
            'type' => 'expense',
            'date' => '2026-04-10',
        ])->assertCreated();

        $response = $this->getJson('/api/analytics/yearly-growth?user_id=' . $user->id . '&start_year=2025&end_year=2026');
        $response->assertOk();
        $response->assertJsonPath('start_year', 2025);
        $response->assertJsonPath('end_year', 2026);

        $years = collect($response->json('years'));

        $y2025 = $years->firstWhere('year', 2025);
        $this->assertNotNull($y2025);
        $this->assertSame(1000.0, (float) $y2025['income']);
        $this->assertSame(600.0, (float) $y2025['expense']);
        $this->assertSame(400.0, (float) $y2025['balance']);
        $this->assertNull($y2025['income_growth_rate']);
        $this->assertNull($y2025['expense_growth_rate']);
        $this->assertNull($y2025['balance_growth_rate']);

        $y2026 = $years->firstWhere('year', 2026);
        $this->assertNotNull($y2026);
        $this->assertSame(1800.0, (float) $y2026['income']);
        $this->assertSame(900.0, (float) $y2026['expense']);
        $this->assertSame(900.0, (float) $y2026['balance']);
        $this->assertSame(80.0, (float) $y2026['income_growth_rate']);
        $this->assertSame(50.0, (float) $y2026['expense_growth_rate']);
        $this->assertSame(125.0, (float) $y2026['balance_growth_rate']);
    }

    public function test_yearly_growth_supports_metric_and_compact_mode(): void
    {
        $user = User::factory()->create();

        $salary = Category::create([
            'user_id' => $user->id,
            'name' => 'Salary',
            'type' => 'income',
        ]);

        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'amount' => 1000,
            'type' => 'income',
            'date' => '2025-01-05',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'amount' => 1300,
            'type' => 'income',
            'date' => '2026-01-05',
        ])->assertCreated();

        $response = $this->getJson('/api/analytics/yearly-growth?user_id=' . $user->id . '&start_year=2025&end_year=2026&metric=income&compact=1');
        $response->assertOk();
        $response->assertJsonPath('metric', 'income');
        $response->assertJsonPath('compact', true);

        $y2025 = collect($response->json('years'))->firstWhere('year', 2025);
        $this->assertNotNull($y2025);
        $this->assertArrayHasKey('value', $y2025);
        $this->assertArrayHasKey('growth_rate', $y2025);
        $this->assertSame(1000.0, (float) $y2025['value']);
        $this->assertNull($y2025['growth_rate']);

        $y2026 = collect($response->json('years'))->firstWhere('year', 2026);
        $this->assertNotNull($y2026);
        $this->assertSame(1300.0, (float) $y2026['value']);
        $this->assertSame(30.0, (float) $y2026['growth_rate']);
    }

    public function test_kpis_endpoint_returns_dashboard_card_metrics(): void
    {
        $user = User::factory()->create();

        $incomeCategory = Category::create([
            'user_id' => $user->id,
            'name' => 'Salary',
            'type' => 'income',
        ]);

        $expenseCategory = Category::create([
            'user_id' => $user->id,
            'name' => 'Bills',
            'type' => 'expense',
        ]);

        // Previous year baseline.
        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $incomeCategory->id,
            'amount' => 1000,
            'type' => 'income',
            'date' => '2025-05-05',
        ])->assertCreated();
        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $expenseCategory->id,
            'amount' => 400,
            'type' => 'expense',
            'date' => '2025-05-10',
        ])->assertCreated();

        // Previous month (Jan 2026).
        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $incomeCategory->id,
            'amount' => 1500,
            'type' => 'income',
            'date' => '2026-01-10',
        ])->assertCreated();
        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $expenseCategory->id,
            'amount' => 500,
            'type' => 'expense',
            'date' => '2026-01-12',
        ])->assertCreated();

        // Current month (Feb 2026).
        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $incomeCategory->id,
            'amount' => 2000,
            'type' => 'income',
            'date' => '2026-02-10',
        ])->assertCreated();
        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $expenseCategory->id,
            'amount' => 700,
            'type' => 'expense',
            'date' => '2026-02-12',
        ])->assertCreated();

        $response = $this->getJson('/api/analytics/kpis?user_id=' . $user->id . '&reference_date=2026-02-15');
        $response->assertOk();
        $response->assertJsonPath('reference_date', '2026-02-15');

        $this->assertSame(2000.0, (float) $response->json('month.current.income'));
        $this->assertSame(700.0, (float) $response->json('month.current.expense'));
        $this->assertSame(1300.0, (float) $response->json('month.current.balance'));
        $this->assertSame(1000.0, (float) $response->json('month.previous.balance'));
        $this->assertSame(30.0, (float) $response->json('month.growth_rates.balance'));

        $this->assertSame(3500.0, (float) $response->json('year.current.income'));
        $this->assertSame(1200.0, (float) $response->json('year.current.expense'));
        $this->assertSame(2300.0, (float) $response->json('year.current.balance'));
        $this->assertSame(600.0, (float) $response->json('year.previous.balance'));
        $this->assertSame(283.33, (float) $response->json('year.growth_rates.balance'));

        $this->assertSame(1300.0, (float) $response->json('cards.month_balance'));
        $this->assertSame(30.0, (float) $response->json('cards.month_balance_growth_rate'));
        $this->assertSame(2300.0, (float) $response->json('cards.year_balance'));
        $this->assertSame(283.33, (float) $response->json('cards.year_balance_growth_rate'));
    }

    public function test_insights_endpoint_returns_top_expense_category_and_anomaly_flag(): void
    {
        $user = User::factory()->create();

        $food = Category::create([
            'user_id' => $user->id,
            'name' => 'Food',
            'type' => 'expense',
        ]);

        $rent = Category::create([
            'user_id' => $user->id,
            'name' => 'Rent',
            'type' => 'expense',
        ]);

        // Baseline previous 3 months (Nov/Dec 2025, Jan 2026) expense = 100 each month.
        foreach (['2025-11-10', '2025-12-10', '2026-01-10'] as $date) {
            $this->postJson('/api/transactions', [
                'user_id' => $user->id,
                'category_id' => $food->id,
                'amount' => 100,
                'type' => 'expense',
                'date' => $date,
            ])->assertCreated();
        }

        // Current month (Feb 2026): rent dominates and expense spikes.
        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $rent->id,
            'amount' => 200,
            'type' => 'expense',
            'date' => '2026-02-05',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $food->id,
            'amount' => 50,
            'type' => 'expense',
            'date' => '2026-02-06',
        ])->assertCreated();

        $response = $this->getJson('/api/analytics/insights?user_id=' . $user->id . '&reference_date=2026-02-15');
        $response->assertOk();
        $response->assertJsonPath('reference_date', '2026-02-15');

        $response->assertJsonPath('top_expense_category.category_id', $rent->id);
        $response->assertJsonPath('top_expense_category.category_name', 'Rent');
        $this->assertSame(200.0, (float) $response->json('top_expense_category.total'));

        $this->assertSame(250.0, (float) $response->json('expense_anomaly.current_month_expense'));
        $this->assertSame(100.0, (float) $response->json('expense_anomaly.baseline_avg_previous_3_months'));
        $this->assertSame(2.5, (float) $response->json('expense_anomaly.ratio'));
        $response->assertJsonPath('expense_anomaly.is_anomaly', true);
    }

    public function test_top_categories_endpoint_returns_top_n_plus_other(): void
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

        $shopping = Category::create([
            'user_id' => $user->id,
            'name' => 'Shopping',
            'type' => 'expense',
        ]);

        $misc = Category::create([
            'user_id' => $user->id,
            'name' => 'Misc',
            'type' => 'expense',
        ]);

        foreach ([
            [$rent->id, 500],
            [$food->id, 300],
            [$transport->id, 100],
            [$shopping->id, 50],
            [$misc->id, 25],
        ] as [$categoryId, $amount]) {
            $this->postJson('/api/transactions', [
                'user_id' => $user->id,
                'category_id' => $categoryId,
                'amount' => $amount,
                'type' => 'expense',
                'date' => '2026-04-10',
            ])->assertCreated();
        }

        $response = $this->getJson('/api/analytics/top-categories?user_id=' . $user->id . '&type=expense&limit=2&start_date=2026-04-01&end_date=2026-04-30');
        $response->assertOk();

        $response->assertJsonPath('type', 'expense');
        $response->assertJsonPath('limit', 2);
        $this->assertSame(975.0, (float) $response->json('total'));

        $categories = $response->json('categories');
        $this->assertCount(2, $categories);
        $this->assertSame('Rent', $categories[0]['category_name']);
        $this->assertSame(500.0, (float) $categories[0]['total']);
        $this->assertSame('Food', $categories[1]['category_name']);
        $this->assertSame(300.0, (float) $categories[1]['total']);

        $this->assertSame(175.0, (float) $response->json('other.total'));
    }

    public function test_daily_endpoint_returns_cashflow_per_day_including_zeros(): void
    {
        $user = User::factory()->create();

        $salary = Category::create([
            'user_id' => $user->id,
            'name' => 'Salary',
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
            'amount' => 1000,
            'type' => 'income',
            'date' => '2026-04-01',
        ])->assertCreated();

        $this->postJson('/api/transactions', [
            'user_id' => $user->id,
            'category_id' => $food->id,
            'amount' => 250,
            'type' => 'expense',
            'date' => '2026-04-03',
        ])->assertCreated();

        $response = $this->getJson('/api/analytics/daily?user_id=' . $user->id . '&start_date=2026-04-01&end_date=2026-04-04');
        $response->assertOk();

        $days = collect($response->json('days'));
        $this->assertCount(4, $days);

        $d1 = $days->firstWhere('date', '2026-04-01');
        $this->assertNotNull($d1);
        $this->assertSame(1000.0, (float) $d1['income']);
        $this->assertSame(0.0, (float) $d1['expense']);
        $this->assertSame(1000.0, (float) $d1['balance']);

        $d2 = $days->firstWhere('date', '2026-04-02');
        $this->assertNotNull($d2);
        $this->assertSame(0.0, (float) $d2['income']);
        $this->assertSame(0.0, (float) $d2['expense']);
        $this->assertSame(0.0, (float) $d2['balance']);

        $d3 = $days->firstWhere('date', '2026-04-03');
        $this->assertNotNull($d3);
        $this->assertSame(0.0, (float) $d3['income']);
        $this->assertSame(250.0, (float) $d3['expense']);
        $this->assertSame(-250.0, (float) $d3['balance']);
    }
}
