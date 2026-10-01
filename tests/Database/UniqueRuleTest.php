<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Database;

use InvalidArgumentException;
use Whitesmoke\Database\Schema\Blueprint;

final class UniqueRuleTest extends DatabaseTestCase
{
    protected array $tables = ['v_users'];

    public function testUniqueRule(): void
    {
        $this->schema()->create('v_users', function (Blueprint $t): void {
            $t->id();
            $t->string('email', 254);
        });
        table('v_users')->insert(['email' => 'taken@x.test']);

        $this->assertSame(['email' => 'Email is already taken.'], validate(['email' => 'taken@x.test'], ['email' => 'unique:v_users,email'])->errors());
        $this->assertFalse(validate(['email' => 'free@x.test'], ['email' => 'unique:v_users,email'])->fails());
    }

    public function testUniqueRuleRejectsUnsafeTableNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        validate(['email' => 'a@b.c'], ['email' => 'unique:v_users;DROP TABLE x,email']);
    }
}
