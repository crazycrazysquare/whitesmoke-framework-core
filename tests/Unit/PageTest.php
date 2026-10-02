<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Whitesmoke\Database\Page;

final class PageTest extends TestCase
{
    /** Page $page of $total rows, 10 per page, with the right number of item stubs. */
    private function page(int $page, int $total): Page
    {
        $count = max(0, min(10, $total - ($page - 1) * 10));

        return new Page(array_fill(0, $count, ['id' => 1]), $total, $page, 10);
    }

    public function testNumbers(): void
    {
        $p = $this->page(3, 95);

        $this->assertSame(10, $p->lastPage);
        $this->assertTrue($p->hasPrevious());
        $this->assertTrue($p->hasNext());
        $this->assertSame([21, 30], [$p->from(), $p->to()]);

        $last = $this->page(10, 95);
        $this->assertFalse($last->hasNext());
        $this->assertSame([91, 95], [$last->from(), $last->to()]);

        $this->assertFalse($this->page(1, 95)->hasPrevious());
    }

    public function testUrlsKeepOtherParametersAndEncodeThem(): void
    {
        $p = $this->page(3, 95);

        $this->assertSame('/reports?page=4', $p->url(4, '/reports'));
        $this->assertSame('/reports', $p->url(1, '/reports'), 'page 1 has no page parameter');
        $this->assertSame('/reports?status=open&q=a%26b%20c&page=2', $p->url(2, '/reports', ['status' => 'open', 'q' => 'a&b c', 'page' => '7']));
        $this->assertSame('/reports?page=10', $p->url(500, '/reports'), 'never past the last page');
    }

    public function testOnlyLocalPathsAreAccepted(): void
    {
        foreach (['//evil.example', '/\\evil.example', 'https://evil.example/', 'reports', '/a b', '/reports?x=1', "/x\n", ''] as $path) {
            try {
                $this->page(1, 95)->url(2, $path);
                $this->fail('Should refuse ' . json_encode($path));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testLinksShowAWindowAndEscape(): void
    {
        $html = $this->page(6, 200)->links('/reports', ['q' => '"><script>']);

        $this->assertStringStartsWith('<nav class="pagination" aria-label="Pages">', $html);
        $this->assertStringContainsString('<a href="/reports?q=%22%3E%3Cscript%3E&amp;page=5" rel="prev">Previous</a>', $html);
        $this->assertStringContainsString('<span aria-current="page">6</span>', $html);
        $this->assertStringContainsString('rel="next">Next</a>', $html);
        $this->assertStringNotContainsString('<script>', $html);

        preg_match_all('~>(\d+)</(?:a|span)>~', $html, $m);
        $this->assertSame(['1', '4', '5', '6', '7', '8', '20'], $m[1]);
        $this->assertSame(2, substr_count($html, '…'));
    }

    public function testLinksAtTheEdgesAndForOnePage(): void
    {
        $first = $this->page(1, 30)->links('/r');
        $this->assertStringContainsString('<span aria-disabled="true">Previous</span>', $first);
        $this->assertStringNotContainsString('…', $first);

        $this->assertStringContainsString('<span aria-disabled="true">Next</span>', $this->page(3, 30)->links('/r'));
        $this->assertSame('', $this->page(1, 10)->links('/r'));
        $this->assertSame('', $this->page(1, 0)->links('/r'));
    }
}
