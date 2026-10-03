<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Database;

use InvalidArgumentException;
use LogicException;
use Whitesmoke\Database\Query;
use Whitesmoke\Database\Schema\Blueprint;

/** GROUP BY, HAVING and per-group totals, with the same results and types on every database. */
final class QueryGroupTest extends DatabaseTestCase
{
    protected array $tables = ['qg_invoices', 'qg_customers'];

    private int $ana;
    private int $ben;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema()->create('qg_customers', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 100);
            $t->string('city', 100);
        });

        $this->schema()->create('qg_invoices', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('customer_id')->constrained('qg_customers');
            $t->string('status', 20);
            $t->integer('amount');
            $t->decimal('price', 10, 2)->nullable();
        });

        $this->ana = (int) table('qg_customers')->insert(['name' => 'Ana', 'city' => 'Manila']);
        $this->ben = (int) table('qg_customers')->insert(['name' => 'Ben', 'city' => 'Cebu']);
        table('qg_customers')->insert(['name' => 'Cara', 'city' => 'Manila']);   // no invoices

        // Ana: 100 + 200. Ben: 50 + 300 + 70, one without a price.
        foreach ([[$this->ana, 'open', 100, '10.25'], [$this->ana, 'paid', 200, '20.50'], [$this->ben, 'open', 50, '5.00'], [$this->ben, 'void', 300, '1.75'], [$this->ben, 'paid', 70, null]] as [$customer, $status, $amount, $price]) {
            table('qg_invoices')->insert(['customer_id' => $customer, 'status' => $status, 'amount' => $amount, 'price' => $price]);
        }
    }

    private function perCustomer(): Query
    {
        return table('qg_invoices')
            ->join('qg_customers', 'qg_customers.id', '=', 'qg_invoices.customer_id')
            ->select('qg_customers.name AS customer')
            ->selectSum('qg_invoices.amount', 'revenue')
            ->selectCount('*', 'invoices')
            ->groupBy('qg_customers.name');
    }

    public function testTotalsPerGroup(): void
    {
        $rows = table('qg_invoices')
            ->select('customer_id')
            ->selectCount('*', 'invoices')
            ->selectSum('amount', 'revenue')
            ->selectAvg('amount', 'average')
            ->selectMin('amount', 'smallest')
            ->selectMax('amount', 'largest')
            ->groupBy('customer_id')
            ->orderBy('customer_id')
            ->get();

        $this->assertSame(['customer_id', 'invoices', 'revenue', 'average', 'smallest', 'largest'], array_keys($rows[0]));
        $this->assertEquals([$this->ana, $this->ben], array_column($rows, 'customer_id'));
        $this->assertSame([2, 3], array_column($rows, 'invoices'), 'COUNT is an int everywhere');
        $this->assertSame([300, 420], array_column($rows, 'revenue'), 'SUM of whole numbers is an int everywhere');
        $this->assertSame([150.0, 140.0], array_column($rows, 'average'), 'AVG is a float everywhere');
        $this->assertEquals([100, 50], array_column($rows, 'smallest'));
        $this->assertEquals([200, 300], array_column($rows, 'largest'));
    }

    public function testGroupByAJoinedColumnAndOrderByATotal(): void
    {
        $rows = $this->perCustomer()->orderBy('revenue', 'DESC')->get();

        $this->assertSame([['customer' => 'Ben', 'revenue' => 420, 'invoices' => 3], ['customer' => 'Ana', 'revenue' => 300, 'invoices' => 2]], $rows);
    }

    public function testCountStarCountsRowsCountColumnSkipsNulls(): void
    {
        $rows = table('qg_customers')
            ->leftJoin('qg_invoices', 'qg_invoices.customer_id', '=', 'qg_customers.id')
            ->select('qg_customers.city')
            ->selectCount('*', 'joined_rows')
            ->selectCount('qg_invoices.id', 'invoices')
            ->groupBy('qg_customers.city')
            ->orderBy('qg_customers.city')
            ->get();

        $this->assertSame([
            ['city' => 'Cebu', 'joined_rows' => 3, 'invoices' => 3],
            ['city' => 'Manila', 'joined_rows' => 3, 'invoices' => 2],   // Cara's row has no invoice
        ], $rows);
    }

    public function testNullsAreSkippedInTotals(): void
    {
        $rows = table('qg_invoices')
            ->select('customer_id')
            ->selectCount('price', 'priced')
            ->selectSum('price', 'total_price')
            ->selectAvg('price', 'average_price')
            ->groupBy('customer_id')
            ->orderBy('customer_id')
            ->get();

        $this->assertSame([2, 2], array_column($rows, 'priced'));
        $this->assertEqualsWithDelta(30.75, $rows[0]['total_price'], 0.0001);
        $this->assertEqualsWithDelta(6.75, $rows[1]['total_price'], 0.0001);
        $this->assertEqualsWithDelta(15.375, $rows[0]['average_price'], 0.0001);
        $this->assertEqualsWithDelta(3.375, $rows[1]['average_price'], 0.0001);
    }

    public function testHaving(): void
    {
        $names = fn (Query $q): array => array_column($q->orderBy('customer')->get(), 'customer');

        $this->assertSame(['Ben'], $names($this->perCustomer()->having('revenue', '>', 350)));
        $this->assertSame(['Ana', 'Ben'], $names($this->perCustomer()->having('invoices', '>=', 2)));
        $this->assertSame(['Ben'], $names($this->perCustomer()->having('invoices', '>=', 2)->having('revenue', '!=', 300)), 'several are joined with AND');
        $this->assertSame([], $names($this->perCustomer()->having('revenue', '<', 0)));

        // A where() value and a having() value, bound in the right order.
        $rows = $this->perCustomer()->where('qg_invoices.status', '!=', 'void')->having('revenue', '>=', 270)->get();
        $this->assertSame([['customer' => 'Ana', 'revenue' => 300, 'invoices' => 2]], $rows);

        // AVG in HAVING uses the same expression as in SELECT, so SQL Server compares 140.0, not 140.
        $avg = fn (float|string $limit) => table('qg_invoices')->select('customer_id')->selectAvg('amount', 'average')->groupBy('customer_id')->having('average', '>', $limit)->count();
        $this->assertSame(1, $avg(145.5));
        $this->assertSame(2, $avg(139.5));
        $this->assertSame(1, $avg('145.5'), 'a numeric string, as from a form');

        // Decimals and numeric strings against whole-number totals (SQLite compared text, other databases refused).
        $this->assertSame(['Ben'], $names($this->perCustomer()->having('revenue', '>', 350.5)));
        $this->assertSame(['Ben'], $names($this->perCustomer()->having('revenue', '>', '350')));
        $this->assertSame(['Ana', 'Ben'], $names($this->perCustomer()->having('invoices', '>', '1.5')));

        // MIN and MAX compare text and dates as given.
        $last = $this->perCustomer()->selectMax('qg_invoices.status', 'last_status')->having('last_status', '=', 'void');
        $this->assertSame(['Ben'], $names($last));
    }

    public function testCountAndPaginateCountGroups(): void
    {
        $this->assertSame(2, $this->perCustomer()->count());
        $this->assertSame(1, $this->perCustomer()->having('revenue', '>', 350)->count());
        $this->assertSame(1, $this->perCustomer()->where('qg_invoices.status', '=', 'void')->count());

        $page = $this->perCustomer()->orderBy('revenue', 'DESC')->paginate(1, 2);
        $this->assertSame(2, $page->total);
        $this->assertSame(2, $page->lastPage);
        $this->assertSame([['customer' => 'Ana', 'revenue' => 300, 'invoices' => 2]], $page->items);

        $this->assertSame(['customer' => 'Ben', 'revenue' => 420, 'invoices' => 3], $this->perCustomer()->orderBy('revenue', 'DESC')->first());
    }

    public function testTotalsWithoutGroupByGiveOneRow(): void
    {
        $this->assertSame(['n' => 5, 'total' => 720, 'average' => 144.0], table('qg_invoices')->selectCount('*', 'n')->selectSum('amount', 'total')->selectAvg('amount', 'average')->first());
        $this->assertSame(['n' => 0, 'total' => 0, 'average' => null], table('qg_invoices')->where('status', '=', 'none')->selectCount('*', 'n')->selectSum('amount', 'total')->selectAvg('amount', 'average')->first());
    }

    public function testGroupedQueriesMustBeUnambiguous(): void
    {
        $cases = [
            'column not grouped'      => fn () => table('qg_invoices')->select('customer_id', 'status')->groupBy('customer_id')->get(),
            'column without groupBy'  => fn () => table('qg_invoices')->select('status')->selectSum('amount', 'total')->get(),
            'star'                    => fn () => table('qg_invoices')->select('*')->groupBy('customer_id')->get(),
            'table star'              => fn () => table('qg_invoices')->select('qg_invoices.*')->selectSum('amount', 'total')->get(),
            'nothing selected'        => fn () => table('qg_invoices')->groupBy('status')->get(),
            'grouped differently'     => fn () => table('qg_invoices')->select('qg_invoices.status')->groupBy('status')->get(),
            'having without groupBy'  => fn () => table('qg_invoices')->selectSum('amount', 'total')->having('total', '>', 1)->get(),
            'count, having, no group' => fn () => table('qg_invoices')->selectSum('amount', 'total')->having('total', '>', 1)->count(),
            'sum() on groups'         => fn () => table('qg_invoices')->groupBy('status')->sum('amount'),
            'avg() with having'       => fn () => $this->perCustomer()->having('revenue', '>', 1)->avg('qg_invoices.amount'),
            'update grouped'          => fn () => table('qg_invoices')->where('id', '>', 0)->groupBy('status')->update(['status' => 'x']),
            'delete with totals'      => fn () => table('qg_invoices')->where('id', '>', 0)->selectSum('amount', 'total')->delete(),
            'groupBy in whereGroup'   => fn () => table('qg_invoices')->whereGroup(fn (Query $q) => $q->where('amount', '>', 1)->groupBy('status')),
            'having in whereGroup'    => fn () => table('qg_invoices')->whereGroup(fn (Query $q) => $q->where('amount', '>', 1)->selectSum('amount', 'x')),
        ];

        foreach ($cases as $case => $run) {
            try {
                $run();
                $this->fail("{$case} must be refused");
            } catch (LogicException $e) {
                $this->assertSame(LogicException::class, $e::class, "{$case}: " . $e->getMessage());
            }
        }

        $this->assertSame(5, table('qg_invoices')->count(), 'nothing was changed');
        $this->assertSame(0, table('qg_invoices')->where('status', '=', 'x')->count());
    }

    public function testNamesAndValuesAreValidated(): void
    {
        $cases = [
            'unknown having name'  => fn () => table('qg_invoices')->select('status')->groupBy('status')->having('total', '>', 1),
            'having on a column'   => fn () => table('qg_invoices')->select('status')->selectSum('amount', 'total')->groupBy('status')->having('amount', '>', 1),
            'having operator'      => fn () => $this->perCustomer()->having('revenue', 'LIKE', '1%'),
            'having injection'     => fn () => $this->perCustomer()->having('revenue', '> 0 OR 1=1 --', 1),
            'having null'          => fn () => $this->perCustomer()->having('revenue', '=', null),
            'having array'         => fn () => $this->perCustomer()->having('revenue', '=', [1]),
            'having text on a sum' => fn () => $this->perCustomer()->having('revenue', '>', '1 OR 1=1'),
            'having bool'          => fn () => $this->perCustomer()->having('invoices', '>', true),
            'having infinity'      => fn () => $this->perCustomer()->having('revenue', '<', INF),
            'same name twice'      => fn () => table('qg_invoices')->selectSum('amount', 'x')->selectCount('*', 'x'),
            'name of a column'     => fn () => table('qg_invoices')->select('status AS x')->selectSum('amount', 'x'),
            'two id columns'       => fn () => table('qg_invoices')->join('qg_customers', 'qg_customers.id', '=', 'qg_invoices.customer_id')->select('qg_invoices.id', 'qg_customers.id'),
            'case-insensitive'     => fn () => table('qg_invoices')->select('status')->selectCount('*', 'STATUS'),
            'bad name'             => fn () => table('qg_invoices')->selectSum('amount', 'total amount'),
            'name injection'       => fn () => table('qg_invoices')->selectSum('amount', 'x FROM y; --'),
            'sum of star'          => fn () => table('qg_invoices')->selectSum('*', 'x'),
            'bad column'           => fn () => table('qg_invoices')->selectMax('amount); DROP TABLE x; --', 'x'),
            'empty groupBy'        => fn () => table('qg_invoices')->groupBy(),
            'bad groupBy'          => fn () => table('qg_invoices')->groupBy('status, amount'),
        ];

        foreach ($cases as $case => $run) {
            try {
                $run();
                $this->fail("{$case} must be refused");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
