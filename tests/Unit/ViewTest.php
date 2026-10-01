<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Whitesmoke\View\View;

final class ViewTest extends TestCase
{
    private View $view;

    protected function setUp(): void
    {
        $this->view = new View(BASE_PATH . '/resources/views');
    }

    public function testRendersWithEscapedData(): void
    {
        $this->assertSame(
            "<p>Hello &lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;</p>\n",
            $this->view->render('hello', ['name' => '<script>alert("x")</script>'])
        );
    }

    public function testLayoutReceivesContentAndData(): void
    {
        $html = $this->view->render('hello', ['name' => 'Sara', 'title' => 'T&C'], 'layouts/app');

        $this->assertStringContainsString('<title>T&amp;C</title>', $html);
        $this->assertStringContainsString("<main><p>Hello Sara</p>\n</main>", $html);
    }

    public function testDataCannotOverwriteInternalVariables(): void
    {
        $html = $this->view->render('raw_vars', ['file' => '/etc/passwd']);

        $this->assertStringContainsString('raw_vars.php', $html, '$file still points at the view itself');
        $this->assertStringNotContainsString('/etc/passwd', $html);
    }

    public function testInvalidViewNamesRejected(): void
    {
        foreach (['../secret', 'a/../b', 'Hello', 'hello.php', 'a.b', '/etc/passwd', '', 'hello/'] as $name) {
            try {
                $this->view->render($name);
                $this->fail("View name '{$name}' should be rejected");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFailingViewDiscardsPartialOutput(): void
    {
        $level = ob_get_level();

        try {
            $this->view->render('broken');
            $this->fail('Exception expected');
        } catch (RuntimeException $e) {
            $this->assertSame('view failed', $e->getMessage());
        }

        $this->assertSame($level, ob_get_level(), 'output buffer must be closed');
    }

    public function testEscapeHelpers(): void
    {
        $this->assertSame('&lt;a href=&quot;x&quot; onclick=&#039;y&#039;&gt;', e('<a href="x" onclick=\'y\'>'));
        $this->assertSame('5', e(5));
        $this->assertSame('', e(null));
        $this->assertSame("\u{FFFD}", e("\xFF"), 'invalid UTF-8 is replaced, not passed through');

        $json = js(['x' => '</script><script>alert(1)</script>', 'q' => "'\"&"]);
        $this->assertStringNotContainsString('</script>', $json);
        $this->assertStringNotContainsString("'", $json);
        $this->assertSame(['x' => '</script><script>alert(1)</script>', 'q' => "'\"&"], json_decode($json, true));
    }
}
