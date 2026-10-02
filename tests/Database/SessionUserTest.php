<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Database;

use InvalidArgumentException;
use Whitesmoke\Auth\SessionUser;
use Whitesmoke\Database\Schema\Blueprint;
use Whitesmoke\Session\Session;

/**
 * Each "request" closes the PHP session and starts a new Session with the cookie a
 * browser would send, as in SessionTest. Needs PHPUnit's --stderr (composer test).
 */
final class SessionUserTest extends DatabaseTestCase
{
    protected array $tables = ['su_users'];

    private array $config;
    private int $ana = 0;

    protected function setUp(): void
    {
        if (headers_sent()) {
            $this->markTestSkipped('Run PHPUnit with --stderr (composer test) for session tests.');
        }

        parent::setUp();

        $this->config = require BASE_PATH . '/config/session.php';
        $this->closeSession();

        $this->schema()->create('su_users', function (Blueprint $t): void {
            $t->id();
            $t->string('email', 254)->unique();
            $t->string('password');
        });
        $this->ana = (int) table('su_users')->insert(['email' => 'ana@example.test', 'password' => password_hash('first-password', PASSWORD_DEFAULT)]);
    }

    protected function tearDown(): void
    {
        $this->closeSession();
        unset($_COOKIE[$this->config['name']]);
        parent::tearDown();
    }

    private function closeSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
    }

    private function request(?string $cookie = null): Session
    {
        $this->closeSession();

        if ($cookie === null) {
            unset($_COOKIE[$this->config['name']]);
            session_id(session_create_id());
        } else {
            $_COOKIE[$this->config['name']] = $cookie;
            session_id($cookie);
        }

        return new Session($this->config);
    }

    /** Log Ana in on a fresh session; returns its cookie. */
    private function login(): string
    {
        $session = $this->request();
        $user = table('su_users')->where('id', '=', $this->ana)->first();
        (new SessionUser($session, 'su_users'))->remember($user);
        $cookie = session_id();
        $this->closeSession();

        return $cookie;
    }

    private function check(string $cookie): ?array
    {
        return (new SessionUser($this->request($cookie), 'su_users'))->check();
    }

    public function testLoggedInUserIsRecognized(): void
    {
        $cookie = $this->login();

        $this->assertSame('ana@example.test', $this->check($cookie)['email'] ?? null);
        $this->assertSame('ana@example.test', $this->check($cookie)['email'] ?? null, 'and on the next request');
    }

    public function testPasswordChangeEndsEverySessionFromBefore(): void
    {
        $laptop = $this->login();
        $phone  = $this->login();

        table('su_users')->where('id', '=', $this->ana)->update(['password' => password_hash('new-password-1', PASSWORD_DEFAULT)]);

        $this->assertNull($this->check($laptop));
        $this->assertNull($this->check($phone));
        $this->assertNull($this->check($laptop), 'stays ended');

        $this->assertNotNull($this->check($this->login()), 'logging in again with the new password works');
    }

    public function testRememberingAgainKeepsThisSessionAfterAPasswordChange(): void
    {
        $other = $this->login();
        $here  = $this->login();

        table('su_users')->where('id', '=', $this->ana)->update(['password' => password_hash('new-password-1', PASSWORD_DEFAULT)]);

        // What "change password" does on the device where it happened:
        $session = $this->request($here);
        (new SessionUser($session, 'su_users'))->remember(table('su_users')->where('id', '=', $this->ana)->first());
        $kept = session_id();
        $this->closeSession();

        $this->assertNotSame($here, $kept, 'new session id');
        $this->assertNotNull($this->check($kept), 'this device stays logged in');
        $this->assertNull($this->check($other), 'the other device is logged out');
    }

    public function testEndedSessionIsEmptied(): void
    {
        $cookie = $this->login();
        table('su_users')->where('id', '=', $this->ana)->update(['password' => 'changed']);

        $session = $this->request($cookie);
        (new SessionUser($session, 'su_users'))->check();

        $this->assertNull($session->get('user_id'));
        $this->assertNotSame($cookie, session_id(), 'new session id');
    }

    public function testDeletedUserIsLoggedOut(): void
    {
        $cookie = $this->login();
        table('su_users')->where('id', '=', $this->ana)->delete();

        $this->assertNull($this->check($cookie));
    }

    public function testSessionsWithoutOrWithAWrongFingerprintAreEnded(): void
    {
        $session = $this->request();
        $session->put('user_id', $this->ana);        // logged in before this check existed
        $old = session_id();
        $this->closeSession();
        $this->assertNull($this->check($old));

        $session = $this->request();
        $session->put('user_id', $this->ana);
        $session->put('_auth', str_repeat('0', 64)); // forged
        $forged = session_id();
        $this->closeSession();
        $this->assertNull($this->check($forged));

        $session = $this->request();
        $session->put('user_id', (string) $this->ana); // not an int
        $session->put('_auth', hash('sha256', (string) table('su_users')->first()['password']));
        $string = session_id();
        $this->closeSession();
        $this->assertNull($this->check($string));
    }

    public function testGuestIsNullWithoutStartingASession(): void
    {
        $this->closeSession();
        unset($_COOKIE[$this->config['name']]);

        $this->assertNull((new SessionUser(new Session($this->config), 'su_users'))->check());
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
    }

    public function testBadInputIsRefused(): void
    {
        foreach ([fn () => new SessionUser(null, 'users; DROP'), fn () => (new SessionUser($this->request(), 'su_users'))->remember(['id' => 1])] as $bad) {
            try {
                $bad();
                $this->fail('Should refuse');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
