<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Database;

use InvalidArgumentException;
use LogicException;
use Whitesmoke\Database\Schema\Blueprint;

final class PaginationTest extends DatabaseTestCase
{
    protected array $tables = ['p_items'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema()->create('p_items', function (Blueprint $t): void {
            $t->id();
            $t->integer('n');
            $t->string('kind', 10);
        });

        for ($n = 1; $n <= 53; $n++) {
            table('p_items')->insert(['n' => $n, 'kind' => $n % 2 === 0 ? 'even' : 'odd']);
        }
    }

    private function numbers(array $rows): array
    {
        return array_map(fn (array $r): int => (int) $r['n'], $rows);
    }

    public function testPagesHoldTheRightRowsInOrder(): void
    {
        $first = table('p_items')->orderBy('n')->paginate(20, 1);
        $third = table('p_items')->orderBy('n')->paginate(20, 3);

        $this->assertSame(range(1, 20), $this->numbers($first->items));
        $this->assertSame(range(41, 53), $this->numbers($third->items));
        $this->assertSame([53, 3, 20, 3], [$first->total, $first->lastPage, $first->perPage, $third->page]);
        $this->assertSame([41, 53], [$third->from(), $third->to()]);
    }

    public function testEveryRowAppearsExactlyOnceAcrossPages(): void
    {
        $seen = [];
        for ($p = 1; $p <= 6; $p++) {
            $seen = [...$seen, ...$this->numbers(table('p_items')->orderBy('n', 'DESC')->paginate(10, $p)->items)];
        }

        $this->assertSame(range(53, 1), $seen);
    }

    public function testFiltersApplyToRowsAndTotal(): void
    {
        $page = table('p_items')->where('kind', '=', 'even')->orderBy('n')->paginate(10, 2);

        $this->assertSame(26, $page->total);
        $this->assertSame(range(22, 40, 2), $this->numbers($page->items));
        $this->assertSame(['n'], array_keys(table('p_items')->select('n')->orderBy('n')->paginate(5)->items[0]));
    }

    public function testOutOfRangePagesAreClamped(): void
    {
        $this->assertSame(1, table('p_items')->orderBy('n')->paginate(20, null)->page);
        $this->assertSame(1, table('p_items')->orderBy('n')->paginate(20, 0)->page);
        $this->assertSame(1, table('p_items')->orderBy('n')->paginate(20, -5)->page);

        $last = table('p_items')->orderBy('n')->paginate(20, 999999999);
        $this->assertSame(3, $last->page);
        $this->assertSame(range(41, 53), $this->numbers($last->items));
    }

    public function testEmptyResult(): void
    {
        $page = table('p_items')->where('kind', '=', 'none')->orderBy('n')->paginate(20, 4);

        $this->assertSame([], $page->items);
        $this->assertSame([0, 1, 1, 0, 0], [$page->total, $page->page, $page->lastPage, $page->from(), $page->to()]);
    }

    public function testMisuseFailsClosed(): void
    {
        $bad = [
            fn () => table('p_items')->paginate(20),                         // no order
            fn () => table('p_items')->orderBy('n')->paginate(0),
            fn () => table('p_items')->orderBy('n')->paginate(1001),
            fn () => table('p_items')->orderBy('n')->limit(5)->paginate(20),  // own limit
        ];

        foreach ($bad as $i => $call) {
            try {
                $call();
                $this->fail("Case {$i} must throw");
            } catch (InvalidArgumentException | LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
