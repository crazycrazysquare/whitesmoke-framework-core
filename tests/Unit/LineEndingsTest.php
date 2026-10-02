<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Views output their own line endings, and some tests compare rendered
 * output exactly. .gitattributes keeps checkouts LF on Windows too.
 */
final class LineEndingsTest extends TestCase
{
    public function testSourceAndFixturesUseLfLineEndings(): void
    {
        $root = dirname(__DIR__, 2);
        $crlf = [];

        foreach (['src', 'tests'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->isFile() && str_contains((string) file_get_contents($file->getPathname()), "\r\n")) {
                    $crlf[] = substr($file->getPathname(), strlen($root) + 1);
                }
            }
        }

        $this->assertSame([], $crlf, 'CRLF line endings; check that .gitattributes (eol=lf) is present and re-checkout');
    }
}
