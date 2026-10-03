<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Integration;

use Whitesmoke\Testing\AppTestCase;

/** The session cookie as a browser receives it when SESSION_SECURE is on (production). */
final class SecureSessionCookieTest extends AppTestCase
{
    protected static function basePath(): string
    {
        return dirname(__DIR__) . '/Fixtures/TestingApp';
    }

    protected static function environment(): array
    {
        return ['SESSION_SECURE' => 'true'] + parent::environment();
    }

    public function testTheSessionCookieHasTheHostPrefix(): void
    {
        $cookie = (string) $this->get('/')->assertOk()->header('Set-Cookie');

        // __Host- rules: Secure, Path=/ and no Domain, or browsers refuse the cookie.
        $this->assertMatchesRegularExpression('~^__Host-ws_app_test=[^;\s]+; path=/; secure; HttpOnly; SameSite=Lax\z~', $cookie);
        $this->assertStringNotContainsStringIgnoringCase('domain=', $cookie);
        $this->assertSame(['__Host-ws_app_test'], array_keys($this->cookies()));
    }

    public function testTheSessionContinuesWithThePrefixedCookie(): void
    {
        // The CSRF token is in the session, so the save only works if the cookie is read back.
        $this->post('/save', ['body' => 'over https'])->assertRedirect('/');
        $this->get('/')->assertSee('Saved: over https');
    }
}
