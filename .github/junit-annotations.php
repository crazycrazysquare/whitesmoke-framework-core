<?php
declare(strict_types=1);

/*
 * Prints each failed or errored test from a PHPUnit JUnit report as a GitHub
 * Actions error annotation, so failures are readable on the run page and
 * through the public API without downloading the logs.
 *
 *   php .github/junit-annotations.php build/junit.xml
 */

$file = $argv[1] ?? '';

if (!is_file($file)) {
    echo "::error title=PHPUnit::No JUnit report at {$file}; PHPUnit probably did not start. See the log.\n";
    exit(0);
}

$escape = static fn (string $s): string => str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $s);
$title  = static fn (string $s): string => str_replace([':', ','], ['%3A', '%2C'], $escape($s));

$xml   = new SimpleXMLElement((string) file_get_contents($file));
$count = 0;

foreach ($xml->xpath('//testcase[failure or error]') ?: [] as $case) {
    $problem = count($case->failure) > 0 ? $case->failure : $case->error;
    $message = mb_substr(trim((string) $problem), 0, 3000);

    echo '::error title=', $title((string) $case['class'] . '::' . (string) $case['name']), '::', $escape($message), "\n";
    $count++;
}

echo "{$count} failing test(s) annotated.\n";
