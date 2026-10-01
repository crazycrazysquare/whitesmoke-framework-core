<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Whitesmoke\Session\Session;

/**
 * Each "request" is simulated by closing the PHP session and starting a new
 * Session object with the cookie a browser would send. Run with --stderr
 * (composer test does) so PHPUnit's own output does not block session headers.
 */
final class SessionTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        if (headers_sent()) {
            $this->markTestSkipped('Run PHPUnit with --stderr (composer test) for session tests.');
        }

        $this->config = require BASE_PATH . '/config/session.php';
        $this->closeSession();
        unset($_COOKIE[$this->config['name']]);
    }

    protected function tearDown(): void
    {
        $this->closeSession();
        unset($_COOKIE[$this->config['name']]);
    }

    private function closeSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
    }

    /** Start a new simulated request, optionally with the session cookie. */
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

    public function testReadingWithoutCookieDoesNotStartSession(): void
    {
        $this->closeSession();
        unset($_COOKIE[$this->config['name']]);
        $session = new Session($this->config);

        $this->assertSame('default', $session->get('user_id', 'default'));
        $this->assertNull($session->getFlash('success'));
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
    }

    public function testDataPersistsAcrossRequests(): void
    {
        $s = $this->request();
        $s->put('user_id', 7);
        $id = session_id();

        $s = $this->request($id);
        $this->assertSame(7, $s->get('user_id'));

        $s->forget('user_id');
        $s = $this->request($id);
        $this->assertNull($s->get('user_id'));
    }

    public function testFlashSurvivesExactlyOneRequest(): void
    {
        $s = $this->request();
        $s->flash('success', 'Saved.');
        $this->assertNull($s->getFlash('success'), 'not visible in the request that set it');
        $id = session_id();

        $this->assertSame('Saved.', $this->request($id)->getFlash('success'));
        $this->assertNull($this->request($id)->getFlash('success'));
    }

    public function testUnknownSessionIdIsReplaced(): void
    {
        $s = $this->request('attackerchosenid1234567890');
        $s->put('x', 1);

        $this->assertNotSame('attackerchosenid1234567890', session_id(), 'strict mode rejects IDs the server did not create');
    }

    public function testRegenerateChangesIdAndTokenAndKeepsData(): void
    {
        $s = $this->request();
        $s->put('cart', 'kept');
        $oldId    = session_id();
        $oldToken = $s->token();

        $s->regenerate();

        $this->assertNotSame($oldId, session_id());
        $this->assertNotSame($oldToken, $s->token());
        $this->assertSame('kept', $s->get('cart'));

        $newId = session_id();
        $this->assertNull($this->request($oldId)->get('cart'), 'old ID no longer works');
        $this->assertSame('kept', $this->request($newId)->get('cart'));
    }

    public function testInvalidateClearsEverything(): void
    {
        $s = $this->request();
        $s->put('user_id', 7);
        $oldId = session_id();

        $s->invalidate();

        $this->assertNotSame($oldId, session_id());
        $this->assertNull($s->get('user_id'));
        $this->assertNull($this->request($oldId)->get('user_id'));
    }

    public function testIdleTimeoutClearsSession(): void
    {
        $s = $this->request();
        $s->put('user_id', 7);
        $_SESSION['_last'] = time() - $this->config['idle'] - 1;
        $id = session_id();

        $this->assertNull($this->request($id)->get('user_id'));
    }

    public function testAbsoluteTimeoutClearsSession(): void
    {
        $s = $this->request();
        $s->put('user_id', 7);
        $_SESSION['_created'] = time() - $this->config['absolute'] - 1;
        $id = session_id();

        $this->assertNull($this->request($id)->get('user_id'));
    }

    public function testTokenIsStableAndRandom(): void
    {
        $s = $this->request();
        $token = $s->token();

        $this->assertMatchesRegularExpression('~^[a-f0-9]{64}$~', $token);
        $this->assertSame($token, $s->token());
        $this->assertSame($token, $this->request(session_id())->token());
        $this->assertNotSame($token, $this->request()->token(), 'different sessions get different tokens');
    }
}
