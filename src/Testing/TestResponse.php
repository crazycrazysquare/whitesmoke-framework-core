<?php
declare(strict_types=1);

namespace Whitesmoke\Testing;

use PHPUnit\Framework\Assert;

/** A response received by AppTestCase, with assertions. */
final class TestResponse
{
    /** @param array<string, string> $headers lower-case names; repeated headers joined with "\n" */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function assertStatus(int $status): self
    {
        Assert::assertSame($status, $this->status, "Expected status {$status}, got {$this->status}." . $this->hint());
        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    /** A 302 or 303 redirect, to $path if given (compared without the scheme and host). */
    public function assertRedirect(?string $path = null): self
    {
        Assert::assertContains($this->status, [301, 302, 303, 307, 308], "Expected a redirect, got {$this->status}." . $this->hint());

        if ($path !== null) {
            $location = (string) $this->header('Location');
            $target   = preg_replace('~^https?://[^/]+~i', '', $location);
            Assert::assertSame($path, $target, "Expected a redirect to {$path}, got {$location}.");
        }

        return $this;
    }

    /** $text appears in the page, as the visitor sees it (HTML entities decoded). */
    public function assertSee(string $text): self
    {
        Assert::assertStringContainsString($text, $this->visible(), "Expected to see \"{$text}\" on the page.");
        return $this;
    }

    public function assertDontSee(string $text): self
    {
        Assert::assertStringNotContainsString($text, $this->visible(), "Expected not to see \"{$text}\" on the page.");
        return $this;
    }

    /** $html appears in the raw response body, e.g. to check escaping. */
    public function assertSeeHtml(string $html): self
    {
        Assert::assertStringContainsString($html, $this->body);
        return $this;
    }

    public function assertHeader(string $name, ?string $value = null): self
    {
        Assert::assertNotNull($this->header($name), "Expected the header {$name}.");
        if ($value !== null) {
            Assert::assertSame($value, $this->header($name));
        }
        return $this;
    }

    private function visible(): string
    {
        return html_entity_decode(strip_tags($this->body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function hint(): string
    {
        $location = $this->header('Location');

        return $location !== null ? " Location: {$location}" : ' Body: ' . mb_substr(trim(strip_tags($this->body)), 0, 300);
    }
}
