<?php
declare(strict_types=1);

namespace Whitesmoke\Mail;

use RuntimeException;

/** Sending failed: connection, TLS, authentication or a server refusal. Never contains the password. */
final class MailException extends RuntimeException
{
}
