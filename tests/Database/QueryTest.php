<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Database;

use InvalidArgumentException;
use LogicException;
use Whitesmoke\Database\Schema\Blueprint;

final class QueryTest extends DatabaseTestCase
{
    protected array $tables = ['q_posts', 'q_users'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema()->create('q_users', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 100);
            $t->string('email', 254)->nullable();
            $t->boolean('active')->default(true);
            $t->integer('score')->default(0);
        });

        $this->schema()->create('q_posts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('q_users', onDelete: 'cascade');
            $t->string('title', 100);
        });

        foreach ([['Aisha', 'a@x.test', 30], ['Juan', null, 10], ['Omar', 'o@x.test', 20], ['Maria', 'm@x.test', 40]] as [$name, $email, $score]) {
            table('q_users')->insert(['name' => $name, 'email' => $email, 'score' => $score]);
        }
    }

    public function testInsertReturnsIncreasingIntegerIds(): void
    {
        $a = table('q_users')->insert(['name' => 'X']);
        $b = table('q_users')->insert(['name' => 'Y']);

        $this->assertIsInt($a);
        $this->assertSame($a + 1, $b);
    }

    public function testFirstGetCountAndSelect(): void
    {
        $this->assertSame('Juan', table('q_users')->where('name', '=', 'Juan')->first()['name']);
        $this->assertNull(table('q_users')->where('name', '=', 'Nobody')->first());
        $this->assertCount(4, table('q_users')->get());
        $this->assertSame(4, table('q_users')->count());

        $row = table('q_users')->select('id', 'name')->where('name', '=', 'Omar')->first();
        $this->assertSame(['id', 'name'], array_keys($row));
    }

    public function testOperatorsNullAndWhereIn(): void
    {
        $names = fn ($q) => array_column($q->orderBy('name')->get(), 'name');

        $this->assertSame(['Aisha', 'Maria'], $names(table('q_users')->where('score', '>=', 30)));
        $this->assertSame(['Juan'], $names(table('q_users')->where('score', '<', 20)));
        $this->assertSame(['Aisha', 'Maria', 'Omar'], $names(table('q_users')->where('name', '!=', 'Juan')));
        $this->assertSame(['Maria'], $names(table('q_users')->where('name', 'LIKE', 'Mar%')));
        $this->assertSame(['Juan'], $names(table('q_users')->where('email', '=', null)));
        $this->assertSame(['Aisha', 'Maria', 'Omar'], $names(table('q_users')->where('email', '!=', null)));
        $this->assertSame(['Juan', 'Omar'], $names(table('q_users')->whereIn('name', ['Omar', 'Juan', 'Nobody'])));
        $this->assertSame([], table('q_users')->whereIn('name', [])->get());
        $this->assertSame(['Aisha'], $names(table('q_users')->where('score', '>', 10)->where('score', '<', 40)->where('email', 'LIKE', 'a%')));
    }

    public function testOrderLimitOffset(): void
    {
        $page = fn (int $p) => array_column(table('q_users')->orderBy('score', 'DESC')->limit(2)->offset(($p - 1) * 2)->get(), 'name');

        $this->assertSame(['Maria', 'Aisha'], $page(1));
        $this->assertSame(['Omar', 'Juan'], $page(2));
        $this->assertSame([], $page(3));
    }

    public function testBooleanBinding(): void
    {
        table('q_users')->where('name', '=', 'Juan')->update(['active' => false]);

        $this->assertSame(['Juan'], array_column(table('q_users')->where('active', '=', false)->get(), 'name'));
        $this->assertSame(3, table('q_users')->where('active', '=', true)->count());
    }

    public function testUpdateAndDeleteReturnAffectedRows(): void
    {
        $this->assertSame(2, table('q_users')->where('score', '<', 25)->update(['score' => 0]));
        $this->assertSame(2, table('q_users')->where('score', '=', 0)->count());
        $this->assertSame(1, table('q_users')->where('name', '=', 'Omar')->delete());
        $this->assertSame(3, table('q_users')->count());
    }

    public function testUpdateAndDeleteWithoutWhereRefused(): void
    {
        foreach ([fn () => table('q_users')->update(['score' => 1]), fn () => table('q_users')->delete()] as $op) {
            try {
                $op();
                $this->fail('Expected LogicException');
            } catch (LogicException) {
                $this->assertSame(4, table('q_users')->count());
            }
        }
    }

    public function testValuesAreNeverInterpretedAsSql(): void
    {
        $evil = "x' OR '1'='1";
        table('q_users')->insert(['name' => $evil]);

        $this->assertSame($evil, table('q_users')->where('name', '=', $evil)->first()['name']);
        $this->assertSame(0, table('q_users')->where('name', '=', "' OR '1'='1")->count());
        $this->assertSame(5, table('q_users')->count());
    }

    public function testInvalidIdentifiersOperatorsAndDirectionsRejected(): void
    {
        $bad = [
            fn () => table('q_users; DROP TABLE q_users'),
            fn () => table('q_users')->where('name = 1 OR 1', '=', 'x'),
            fn () => table('q_users')->where('name', 'OR 1=1 --', 'x'),
            fn () => table('q_users')->orderBy('name', 'DESC; DROP'),
            fn () => table('q_users')->orderBy('score)--'),
            fn () => table('q_users')->select('name, password'),
            fn () => table('q_users')->where('score', '>', null),
            fn () => table('q_users')->limit(0),
            fn () => table('q_users')->offset(-1),
        ];

        foreach ($bad as $i => $op) {
            try {
                $op();
                $this->fail("Case {$i} should be rejected");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(4, table('q_users')->count(), 'table still intact');
    }

    public function testOffsetWithoutLimitRefused(): void
    {
        $this->expectException(LogicException::class);
        table('q_users')->offset(2)->get();
    }

    public function testForeignKeyCascade(): void
    {
        $id = table('q_users')->where('name', '=', 'Aisha')->first()['id'];
        table('q_posts')->insert(['user_id' => $id, 'title' => 'P1']);
        table('q_posts')->insert(['user_id' => $id, 'title' => 'P2']);

        table('q_users')->where('id', '=', $id)->delete();

        $this->assertSame(0, table('q_posts')->count());
    }
}
