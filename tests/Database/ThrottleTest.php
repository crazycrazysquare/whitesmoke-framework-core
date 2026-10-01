<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Database;

use Whitesmoke\Database\Schema\Blueprint;
use Whitesmoke\Security\Throttle;

final class ThrottleTest extends DatabaseTestCase
{
    protected array $tables = ['throttle'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema()->create('throttle', function (Blueprint $t): void {
            $t->id();
            $t->string('throttle_key', 100)->unique();
            $t->integer('attempts')->default(0);
            $t->bigInteger('window_ends');
            $t->bigInteger('locked_until')->default(0);
        });
    }

    public function testLocksAtMaxAndClears(): void
    {
        $t = new Throttle();

        $this->assertSame(1, $t->hit('k', 3, 900));
        $this->assertSame(2, $t->hit('k', 3, 900));
        $this->assertFalse($t->tooMany('k'));

        $t->hit('k', 3, 900);
        $this->assertTrue($t->tooMany('k'));
        $this->assertGreaterThan(890, $t->availableIn('k'));
        $this->assertLessThanOrEqual(900, $t->availableIn('k'));

        $this->assertFalse($t->tooMany('other'), 'keys are independent');

        $t->clear('k');
        $this->assertFalse($t->tooMany('k'));
        $this->assertSame(0, $t->availableIn('k'));
    }

    public function testLockExpires(): void
    {
        $t = new Throttle();
        $t->hit('k', 1, 900);
        $this->assertTrue($t->tooMany('k'));

        table('throttle')->where('throttle_key', '=', 'k')->update(['locked_until' => time() - 1]);
        $this->assertFalse($t->tooMany('k'));
    }

    public function testWindowResetsCount(): void
    {
        $t = new Throttle();
        $t->hit('k', 5, 900);
        $t->hit('k', 5, 900);

        table('throttle')->where('throttle_key', '=', 'k')->update(['window_ends' => time() - 1]);

        $this->assertSame(1, $t->hit('k', 5, 900), 'old attempts outside the window are forgotten');
    }

    public function testCountKeepsGrowingWhileLocked(): void
    {
        $t = new Throttle();
        foreach ([1, 2, 3] as $expected) {
            $this->assertSame($expected, $t->hit('k', 3, 900));
        }

        $this->assertTrue($t->tooMany('k'));
        $this->assertSame(4, $t->hit('k', 3, 900), 'requests that raced past the lock check still see they are over the limit');
        $this->assertSame(5, $t->hit('k', 3, 900));
    }

    public function testLockIsNotExtendedByAttemptsDuringIt(): void
    {
        $t = new Throttle();
        $t->hit('k', 1, 900);
        table('throttle')->where('throttle_key', '=', 'k')->update(['locked_until' => time() + 100]);

        $t->hit('k', 1, 900);

        $this->assertLessThanOrEqual(100, $t->availableIn('k'));
    }

    public function testRejectsUnsafeTableName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Throttle('throttle; DROP TABLE users');
    }
}
