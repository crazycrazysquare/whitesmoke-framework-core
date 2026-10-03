<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Database;

use InvalidArgumentException;
use LogicException;
use Whitesmoke\Database\Query;
use Whitesmoke\Database\Schema\Blueprint;

/** Joins, OR conditions, groups and aggregates, on every database. */
final class QueryJoinTest extends DatabaseTestCase
{
    protected array $tables = ['qj_invoices', 'qj_customers'];

    private int $ana;
    private int $ben;
    private int $cara;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema()->create('qj_customers', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 100);
            $t->string('city', 100)->nullable();
        });

        $this->schema()->create('qj_invoices', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('customer_id')->constrained('qj_customers');
            $t->string('status', 20);
            $t->integer('amount');
            $t->decimal('price', 10, 2);
        });

        $this->ana  = (int) table('qj_customers')->insert(['name' => 'Ana', 'city' => 'Manila']);
        $this->ben  = (int) table('qj_customers')->insert(['name' => 'Ben', 'city' => 'Cebu']);
        $this->cara = (int) table('qj_customers')->insert(['name' => 'Cara', 'city' => null]);

        foreach ([[$this->ana, 'open', 100, '10.25'], [$this->ana, 'paid', 200, '20.50'], [$this->ben, 'open', 50, '5.00'], [$this->ben, 'void', 300, '1.75']] as [$customer, $status, $amount, $price]) {
            table('qj_invoices')->insert(['customer_id' => $customer, 'status' => $status, 'amount' => $amount, 'price' => $price]);
        }
    }

    /** @return list<int> invoice amounts, which identify the invoices, in id order */
    private function amounts(Query $query): array
    {
        return array_map('intval', array_column($query->orderBy('qj_invoices.id')->get(), 'amount'));
    }

    public function testJoinSelectsColumnsFromBothTables(): void
    {
        $rows = table('qj_invoices')
            ->join('qj_customers', 'qj_customers.id', '=', 'qj_invoices.customer_id')
            ->select('qj_invoices.amount', 'qj_customers.name AS customer')
            ->where('qj_invoices.status', '=', 'open')
            ->orderBy('qj_invoices.id')
            ->get();

        $this->assertSame(['amount', 'customer'], array_keys($rows[0]));
        $this->assertSame(['Ana', 'Ben'], array_column($rows, 'customer'));
        $this->assertEquals([100, 50], array_column($rows, 'amount'));
    }

    public function testLeftJoinKeepsRowsWithoutAMatch(): void
    {
        $query = fn () => table('qj_customers')->leftJoin('qj_invoices', 'qj_invoices.customer_id', '=', 'qj_customers.id');

        $this->assertSame(5, $query()->count(), 'Ana 2, Ben 2, Cara 1 without invoices');
        $this->assertSame(4, table('qj_customers')->join('qj_invoices', 'qj_invoices.customer_id', '=', 'qj_customers.id')->count(), 'an inner join drops Cara');

        $rows = $query()->select('qj_customers.name', 'qj_invoices.id AS invoice')->where('qj_invoices.id', '=', null)->get();
        $this->assertSame([['name' => 'Cara', 'invoice' => null]], $rows);
    }

    public function testTableAliasAndAllColumnsOfOneTable(): void
    {
        $rows = table('qj_invoices')
            ->join('qj_customers AS c', 'c.id', '=', 'qj_invoices.customer_id')
            ->select('qj_invoices.*', 'c.name AS customer')
            ->where('c.name', '=', 'Ben')
            ->orderBy('qj_invoices.id')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame(['id', 'customer_id', 'status', 'amount', 'price', 'customer'], array_keys($rows[0]));
        $this->assertSame(['open', 'void'], array_column($rows, 'status'));

        // Pagination works on joined queries.
        $page = table('qj_invoices')->join('qj_customers AS c', 'c.id', '=', 'qj_invoices.customer_id')
            ->select('qj_invoices.amount', 'c.name')->orderBy('qj_invoices.amount', 'DESC')->paginate(3, 2);
        $this->assertSame(4, $page->total);
        $this->assertEquals([50], array_column($page->items, 'amount'));
    }

    public function testJoinPartsAreValidated(): void
    {
        $bad = [
            'table'           => fn () => table('qj_invoices')->join('qj_customers; DROP TABLE x', 'a.id', '=', 'b.id'),
            'alias'           => fn () => table('qj_invoices')->join('qj_customers AS c-1', 'c.id', '=', 'qj_invoices.customer_id'),
            'two aliases'     => fn () => table('qj_invoices')->join('qj_customers AS c AS d', 'c.id', '=', 'qj_invoices.customer_id'),
            'value as column' => fn () => table('qj_invoices')->join('qj_customers', 'qj_customers.id', '=', '1'),
            'quoted value'    => fn () => table('qj_invoices')->join('qj_customers', 'qj_customers.name', '=', "'Ana'"),
            'like operator'   => fn () => table('qj_invoices')->join('qj_customers', 'qj_customers.id', 'LIKE', 'qj_invoices.customer_id'),
            'select alias'    => fn () => table('qj_invoices')->select('amount AS a b'),
            'select star as'  => fn () => table('qj_invoices')->select('* AS everything'),
            'select a.*.b'    => fn () => table('qj_invoices')->select('qj_invoices.*.x'),
            'select newline'  => fn () => table('qj_invoices')->select("amount AS a\n"),
            'where star'      => fn () => table('qj_invoices')->where('*', '=', 1),
        ];

        foreach ($bad as $case => $build) {
            try {
                $build();
                $this->fail("{$case} must be refused");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testUpdateAndDeleteRefuseJoins(): void
    {
        $joined = fn () => table('qj_invoices')->join('qj_customers', 'qj_customers.id', '=', 'qj_invoices.customer_id')->where('qj_customers.name', '=', 'Ana');

        foreach (['update' => fn () => $joined()->update(['status' => 'x']), 'delete' => fn () => $joined()->delete()] as $action => $run) {
            try {
                $run();
                $this->fail("{$action} with a join must be refused");
            } catch (LogicException $e) {
                $this->assertStringContainsString('with a join', $e->getMessage());
            }
        }

        $this->assertSame(4, table('qj_invoices')->count(), 'nothing changed');
        $this->assertSame(0, table('qj_invoices')->where('status', '=', 'x')->count());
    }

    public function testOrWhere(): void
    {
        $this->assertSame([200, 300], $this->amounts(table('qj_invoices')->where('status', '=', 'paid')->orWhere('status', '=', 'void')));
        $this->assertSame([200, 50, 300], $this->amounts(table('qj_invoices')->whereIn('status', ['paid'])->orWhereIn('customer_id', [$this->ben])));
        $this->assertSame([200, 50, 300], $this->amounts(table('qj_invoices')->where('amount', '<', 60)->orWhere('amount', '>', 150)->orWhere('status', '=', 'paid')));
        $this->assertSame([100], $this->amounts(table('qj_invoices')->orWhere('amount', '=', 100)), 'a lone orWhere is a plain condition');
    }

    public function testMixingAndWithOrIsRefused(): void
    {
        $cases = [
            'and then or'      => fn () => table('qj_invoices')->where('customer_id', '=', $this->ana)->where('status', '=', 'open')->orWhere('amount', '>', 150),
            'or then and'      => fn () => table('qj_invoices')->where('status', '=', 'open')->orWhere('status', '=', 'paid')->where('customer_id', '=', $this->ana),
            'or then whereIn'  => fn () => table('qj_invoices')->where('status', '=', 'open')->orWhere('status', '=', 'paid')->whereIn('customer_id', [$this->ana]),
            'inside a group'   => fn () => table('qj_invoices')->whereGroup(fn (Query $q) => $q->where('status', '=', 'open')->orWhere('status', '=', 'paid')->where('amount', '>', 1)),
            'group after or'   => fn () => table('qj_invoices')->where('status', '=', 'open')->orWhere('status', '=', 'paid')->whereGroup(fn (Query $q) => $q->where('amount', '>', 1)),
        ];

        foreach ($cases as $case => $build) {
            try {
                $build();
                $this->fail("{$case} must be refused");
            } catch (LogicException $e) {
                $this->assertStringContainsString('ambiguous', $e->getMessage(), $case);
            }
        }
    }

    public function testWhereGroup(): void
    {
        // Ana's invoices that are open or over 150: the customer condition applies to both.
        $this->assertSame([100, 200], $this->amounts(table('qj_invoices')
            ->where('customer_id', '=', $this->ana)
            ->whereGroup(fn (Query $q) => $q->where('status', '=', 'open')->orWhere('amount', '>', 150))));

        $this->assertSame([50, 300], $this->amounts(table('qj_invoices')
            ->where('customer_id', '=', $this->ben)
            ->whereGroup(fn (Query $q) => $q->where('status', '=', 'open')->orWhere('amount', '>', 150))));

        // Nested: void, or (Ana and at least 200).
        $this->assertSame([200, 300], $this->amounts(table('qj_invoices')
            ->where('status', '=', 'void')
            ->orWhereGroup(fn (Query $q) => $q->where('customer_id', '=', $this->ana)->where('amount', '>=', 200))));

        // Bindings stay in order through a group in update(): SET value, group values, then the rest.
        $changed = table('qj_invoices')
            ->whereGroup(fn (Query $q) => $q->where('status', '=', 'open')->orWhere('status', '=', 'void'))
            ->where('customer_id', '=', $this->ben)
            ->update(['status' => 'checked']);
        $this->assertSame(2, $changed);
        $this->assertSame([50, 300], $this->amounts(table('qj_invoices')->where('status', '=', 'checked')));
        $this->assertSame(1, table('qj_invoices')->where('status', '=', 'open')->count(), "Ana's open invoice untouched");

        $this->assertSame(1, table('qj_invoices')->whereGroup(fn (Query $q) => $q->whereIn('status', [])->orWhere('amount', '=', 200))->count());
    }

    public function testGroupsHoldOnlyConditions(): void
    {
        $cases = [
            'empty'   => fn (Query $q) => $q,
            'orderBy' => fn (Query $q) => $q->where('amount', '>', 1)->orderBy('amount'),
            'select'  => fn (Query $q) => $q->where('amount', '>', 1)->select('amount'),
            'join'    => fn (Query $q) => $q->where('amount', '>', 1)->join('qj_customers', 'qj_customers.id', '=', 'qj_invoices.customer_id'),
            'limit'   => fn (Query $q) => $q->where('amount', '>', 1)->limit(1),
        ];

        foreach ($cases as $case => $group) {
            try {
                table('qj_invoices')->whereGroup($group);
                $this->fail("{$case} must be refused");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testValuesInOrConditionsAndGroupsAreBound(): void
    {
        $attack = "x' OR '1'='1";

        $this->assertSame(0, table('qj_invoices')->where('status', '=', $attack)->orWhere('status', '=', $attack)->count());
        $this->assertSame(0, table('qj_invoices')->whereGroup(fn (Query $q) => $q->where('status', '=', $attack)->orWhereIn('status', [$attack]))->count());
        $this->assertSame(4, table('qj_invoices')->count(), 'table intact');
    }

    public function testAggregates(): void
    {
        $this->assertSame(650, table('qj_invoices')->sum('amount'));
        $this->assertSame(150, table('qj_invoices')->where('status', '=', 'open')->sum('amount'));
        $this->assertSame(0, table('qj_invoices')->where('status', '=', 'none')->sum('amount'), 'no rows: 0');
        $this->assertEqualsWithDelta(37.5, table('qj_invoices')->sum('price'), 0.0001);

        $this->assertSame(162.5, table('qj_invoices')->avg('amount'), 'not rounded to a whole number (SQL Server would)');
        $this->assertSame(150.0, table('qj_invoices')->where('customer_id', '=', $this->ana)->avg('amount'));
        $this->assertNull(table('qj_invoices')->where('status', '=', 'none')->avg('amount'));

        $this->assertEquals(50, table('qj_invoices')->min('amount'));
        $this->assertEquals(300, table('qj_invoices')->max('amount'));
        $this->assertSame('Ana', table('qj_customers')->min('name'));
        $this->assertNull(table('qj_invoices')->where('status', '=', 'none')->max('amount'));

        // With a join, a group and NULLs.
        $this->assertSame(300, table('qj_invoices')->join('qj_customers', 'qj_customers.id', '=', 'qj_invoices.customer_id')->where('qj_customers.name', '=', 'Ana')->sum('qj_invoices.amount'));
        $this->assertSame(400, table('qj_invoices')->whereGroup(fn (Query $q) => $q->where('status', '=', 'open')->orWhere('status', '=', 'void'))->where('amount', '>', 60)->sum('amount'));
        $this->assertSame('Ben', table('qj_customers')->where('city', '!=', null)->max('name'), 'Cara has no city');
        $this->assertNull(table('qj_customers')->leftJoin('qj_invoices', 'qj_invoices.customer_id', '=', 'qj_customers.id')->where('qj_customers.name', '=', 'Cara')->max('qj_invoices.amount'));
    }

    public function testAggregatesRefuseLimitsAndBadColumns(): void
    {
        $cases = [
            'limit'  => [LogicException::class, fn () => table('qj_invoices')->limit(2)->sum('amount')],
            'offset' => [LogicException::class, fn () => table('qj_invoices')->limit(2)->offset(1)->avg('amount')],
            'column' => [InvalidArgumentException::class, fn () => table('qj_invoices')->sum('amount) FROM x; --')],
            'star'   => [InvalidArgumentException::class, fn () => table('qj_invoices')->max('*')],
        ];

        foreach ($cases as $case => [$exception, $run]) {
            try {
                $run();
                $this->fail("{$case} must be refused");
            } catch (LogicException $e) {
                $this->assertSame($exception, $e::class, $case);
            }
        }
    }
}
