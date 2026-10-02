<?php
declare(strict_types=1);

namespace Whitesmoke\Database;

use InvalidArgumentException;

/** One page of rows from Query::paginate(), with the numbers and links around it. */
final class Page
{
    public readonly int $lastPage;

    /** @param list<array> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
        $this->lastPage = max(1, (int) ceil($total / $perPage));
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->lastPage;
    }

    /** Number of the first row on this page, counting from 1; 0 when there are no rows. */
    public function from(): int
    {
        return $this->items === [] ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    /** Number of the last row on this page; 0 when there are no rows. */
    public function to(): int
    {
        return $this->items === [] ? 0 : $this->from() + count($this->items) - 1;
    }

    /**
     * URL of page $number: $path plus $query, with "page" set (left out for page 1).
     * $path must be a local path such as /reports.
     */
    public function url(int $number, string $path, array $query = []): string
    {
        if (!preg_match('~^/(?![/\\\\])[^\s?#]*\z~', $path)) {
            throw new InvalidArgumentException('Pagination path must be a local path such as /reports');
        }

        unset($query['page']);
        if ($number > 1) {
            $query['page'] = min($number, $this->lastPage);
        }

        return $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    /**
     * Previous / numbered / next links as escaped HTML, or "" when there is only one page.
     * Shows the first and last page and two pages either side of the current one.
     */
    public function links(string $path, array $query = []): string
    {
        if ($this->lastPage === 1) {
            return '';
        }

        $link = fn (int $n, string $label, string $rel = ''): string =>
            '<a href="' . e($this->url($n, $path, $query)) . '"' . ($rel === '' ? '' : " rel=\"{$rel}\"") . ">{$label}</a>";

        $html = $this->hasPrevious() ? $link($this->page - 1, 'Previous', 'prev') : '<span aria-disabled="true">Previous</span>';

        $shown = array_unique([1, ...range(max(1, $this->page - 2), min($this->lastPage, $this->page + 2)), $this->lastPage]);
        sort($shown);

        $before = 0;
        foreach ($shown as $n) {
            if ($n - $before > 1) {
                $html .= ' <span class="gap">…</span>';
            }
            $html .= ' ' . ($n === $this->page ? "<span aria-current=\"page\">{$n}</span>" : $link($n, (string) $n));
            $before = $n;
        }

        $html .= ' ' . ($this->hasNext() ? $link($this->page + 1, 'Next', 'next') : '<span aria-disabled="true">Next</span>');

        return '<nav class="pagination" aria-label="Pages">' . $html . '</nav>';
    }
}
