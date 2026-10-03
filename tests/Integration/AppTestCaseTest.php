<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Integration;

use InvalidArgumentException;
use Whitesmoke\Testing\AppTestCase;

/** AppTestCase used exactly as an app's tests would use it, against tests/Fixtures/TestingApp. */
final class AppTestCaseTest extends AppTestCase
{
    protected static function basePath(): string
    {
        return dirname(__DIR__) . '/Fixtures/TestingApp';
    }

    public function testGetAPage(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('nothing yet')
            ->assertSee('Notes: 0')
            ->assertSee('Visits: 1')
            ->assertHeader('X-Frame-Options', 'DENY');

        $this->get('/')->assertSee('Visits: 2');
    }

    public function testTheAppWritesToItsOwnStorageFolder(): void
    {
        $this->get('/');
        $this->post('/save', ['body' => 'x']);

        $this->assertNotEmpty(glob(self::storagePath('sessions') . '/*') ?: [], 'sessions');
        $this->assertNotEmpty(glob(self::storagePath('cache/data') . '/*') ?: [], 'cache');
        $this->assertNotEmpty(glob(self::storagePath('logs') . '/mail-*.log') ?: [], 'mail log');
        $this->assertDirectoryDoesNotExist(self::basePath() . '/storage', 'the app folder stays untouched');
    }

    public function testPostWithCsrfSessionDatabaseAndMail(): void
    {
        $this->post('/save', ['body' => '<b>hi</b> & co'])->assertRedirect('/');

        $this->assertDatabaseHas('notes', ['body' => '<b>hi</b> & co']);
        $this->assertDatabaseMissing('notes', ['body' => 'something else']);

        $this->get('/')
            ->assertSee('Saved: <b>hi</b> & co')
            ->assertSeeHtml('Saved: &lt;b&gt;hi&lt;/b&gt; &amp; co')
            ->assertSee('Notes: 1');

        $mail = $this->sentMail();
        $this->assertCount(1, $mail);
        $this->assertSame(['ana@example.test', 'team@example.test'], $mail[0]['to']);
        $this->assertSame('Note saved', $mail[0]['subject']);
        $this->assertSame('You saved: <b>hi</b> & co', $mail[0]['text']);
    }

    public function testEveryTestStartsWithAnEmptyDatabaseAndMailbox(): void
    {
        $this->assertDatabaseMissing('notes', ['body' => '<b>hi</b> & co']);
        $this->assertSame([], $this->sentMail());
        $this->assertSame([], $this->cookies());
        $this->get('/')->assertSee('Notes: 0')->assertSee('Visits: 1');
    }

    public function testPostWithoutTheTokenIsRefused(): void
    {
        $this->request('POST', '/save', ['body' => 'x'])->assertStatus(403);
        $this->assertDatabaseMissing('notes', ['body' => 'x']);
    }

    public function testForgettingCookiesStartsANewSession(): void
    {
        $this->post('/save', ['body' => 'one']);
        $this->forgetCookies();

        $this->get('/')->assertSee('nothing yet')->assertSee('Notes: 1');
    }

    public function testOnlyLocalPathsCanBeRequested(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->get('http://evil.example/');
    }
}
