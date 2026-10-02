<?php
declare(strict_types=1);

namespace Whitesmoke\Storage;

/** A stored upload. Save these fields in your own table; the file itself is found by $id. */
final class StoredFile
{
    public function __construct(
        public readonly string $id,        // 32 hex characters
        public readonly string $name,      // the visitor's file name, cleaned, for showing and downloads
        public readonly string $type,      // MIME type detected from the contents
        public readonly int $size,         // bytes
        public readonly string $extension, // lower case, one of the allowed types
    ) {}
}
