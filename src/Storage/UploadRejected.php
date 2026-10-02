<?php
declare(strict_types=1);

namespace Whitesmoke\Storage;

use RuntimeException;

/** An upload that was refused. The message is written for the visitor and safe to show. */
final class UploadRejected extends RuntimeException
{
}
