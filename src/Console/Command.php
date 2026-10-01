<?php
declare(strict_types=1);

namespace Whitesmoke\Console;

interface Command
{
    /** Command name, e.g. "make:controller". */
    public function name(): string;

    /** One-line description shown in the command list. */
    public function description(): string;

    /** Usage line shown by "help <command>", e.g. "make:controller <Name>". */
    public function usage(): string;

    /** Run the command. Return 0 on success, any other number on failure. */
    public function handle(Input $input, Output $output): int;
}
