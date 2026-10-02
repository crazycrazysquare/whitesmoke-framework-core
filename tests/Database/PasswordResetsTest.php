<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Database;

use InvalidArgumentException;
use Whitesmoke\Auth\PasswordResets;
use Whitesmoke\Database\Schema\Blueprint;

final class PasswordResetsTest extends DatabaseTestCase
{
    protected array $tables = ['pr_resets', 'pr_users'];

    private int $ana = 0;
    private int $ben = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema()->create('pr_users', function (Blueprint $t): void {
            $t->id();
            $t->string('email', 254)->unique();
        });
        $this->schema()->create('pr_resets', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained('pr_users', onDelete: 'cascade');
            $t->string('token_hash', 64)->unique();
            $t->bigInteger('expires_at');
        });

        $this->ana = (int) table('pr_users')->insert(['email' => 'ana@example.test']);
        $this->ben = (int) table('pr_users')->insert(['email' => 'ben@example.test']);
    }

    private function resets(): PasswordResets
    {
        return new PasswordResets(3600, 'pr_resets');
    }

    public function testTokenWorksOnceAndOnlyItsHashIsStored(): void
    {
        $token = $this->resets()->create($this->ana);

        $this->assertMatchesRegularExpression('~^[0-9a-f]{64}$~', $token);
        $this->assertSame(hash('sha256', $token), table('pr_resets')->first()['token_hash']);
        $this->assertSame(0, table('pr_resets')->where('token_hash', '=', $token)->count(), 'the token itself is never stored');

        $this->assertSame($this->ana, $this->resets()->check($token));
        $this->assertSame($this->ana, $this->resets()->check($token), 'check() does not use it up');
        $this->assertSame($this->ana, $this->resets()->consume($token));
        $this->assertNull($this->resets()->consume($token), 'second use fails');
        $this->assertNull($this->resets()->check($token));
    }

    public function testExpiredTokenFails(): void
    {
        $token = $this->resets()->create($this->ana);
        table('pr_resets')->where('user_id', '=', $this->ana)->update(['expires_at' => time() - 1]);

        $this->assertNull($this->resets()->check($token));
        $this->assertNull($this->resets()->consume($token));
    }

    public function testNewTokenReplacesTheOldOneForThatUserOnly(): void
    {
        $first  = $this->resets()->create($this->ana);
        $benTok = $this->resets()->create($this->ben);
        $second = $this->resets()->create($this->ana);

        $this->assertNull($this->resets()->check($first));
        $this->assertSame($this->ana, $this->resets()->check($second));
        $this->assertSame($this->ben, $this->resets()->check($benTok));
    }

    public function testWrongOrMalformedTokensFail(): void
    {
        $token = $this->resets()->create($this->ana);

        foreach ([
            substr($token, 0, 63) . ($token[63] === 'a' ? 'b' : 'a'),
            strtoupper($token),
            substr($token, 0, 32),
            $token . '0',
            "' OR 1=1 --",
            '',
        ] as $bad) {
            $this->assertNull($this->resets()->check($bad), json_encode($bad));
            $this->assertNull($this->resets()->consume($bad), json_encode($bad));
        }

        $this->assertSame($this->ana, $this->resets()->consume($token), 'the real token still works');
    }

    public function testClearRemovesTheUsersTokens(): void
    {
        $token  = $this->resets()->create($this->ana);
        $benTok = $this->resets()->create($this->ben);

        $this->resets()->clear($this->ana);

        $this->assertNull($this->resets()->check($token));
        $this->assertSame($this->ben, $this->resets()->check($benTok));
    }

    public function testSettingsFailClosed(): void
    {
        foreach ([[0, 'pr_resets'], [59, 'pr_resets'], [86401, 'pr_resets'], [3600, 'resets; DROP TABLE x'], [3600, 'Resets']] as [$lifetime, $table]) {
            try {
                new PasswordResets($lifetime, $table);
                $this->fail("Should refuse {$lifetime} / {$table}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
