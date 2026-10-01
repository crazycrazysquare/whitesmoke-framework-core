<?php
declare(strict_types=1);

namespace Whitesmoke\Database\Migrations;

use PDO;
use RuntimeException;
use Throwable;
use Whitesmoke\Database\Migration;
use Whitesmoke\Database\Query;
use Whitesmoke\Database\Schema\Blueprint;
use Whitesmoke\Database\Schema\Schema;

final class Migrator
{
    private const TABLE = 'migrations';
    private const NAME  = '~^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$~';

    private Schema $schema;

    public function __construct(private readonly PDO $pdo, private readonly string $path)
    {
        $this->schema = new Schema($pdo);
    }

    /** Run all pending migrations as one new batch. Returns names that ran. */
    public function migrate(?callable $log = null): array
    {
        $this->ensureTable();

        $ran     = $this->ran();
        $pending = array_diff_key($this->files(), $ran);

        if ($pending === []) {
            return [];
        }

        $batch = ($ran === [] ? 0 : max($ran)) + 1;
        $done  = [];

        foreach ($pending as $name => $file) {
            $log && $log("Migrating: {$name}");

            $this->run($file, $name, 'up', function () use ($name, $batch): void {
                (new Query($this->pdo, self::TABLE))->insert(['migration' => $name, 'batch' => $batch]);
            });

            $done[] = $name;
            $log && $log("Migrated:  {$name}");
        }

        return $done;
    }

    /** Undo the last $steps batches, newest first. Returns names rolled back. */
    public function rollback(int $steps = 1, ?callable $log = null): array
    {
        $this->ensureTable();

        $ran = $this->ran();
        if ($ran === []) {
            return [];
        }

        $batches = array_slice(array_unique(array_values($ran)), -max(1, $steps));
        $names   = array_keys(array_filter($ran, fn (int $b): bool => in_array($b, $batches, true)));
        rsort($names);

        $files = $this->files();
        $done  = [];

        foreach ($names as $name) {
            $file = $files[$name] ?? throw new RuntimeException("Migration file missing: {$name}.php");

            $log && $log("Rolling back: {$name}");

            $this->run($file, $name, 'down', function () use ($name): void {
                (new Query($this->pdo, self::TABLE))->where('migration', '=', $name)->delete();
            });

            $done[] = $name;
            $log && $log("Rolled back:  {$name}");
        }

        return $done;
    }

    /** @return array<int, array{name: string, batch: ?int}> */
    public function status(): array
    {
        $this->ensureTable();

        $ran = $this->ran();
        $all = array_unique([...array_keys($this->files()), ...array_keys($ran)]);
        sort($all);

        return array_map(fn (string $name): array => ['name' => $name, 'batch' => $ran[$name] ?? null], $all);
    }

    private function run(string $file, string $name, string $method, callable $record): void
    {
        $migration = (static fn (string $f): mixed => require $f)($file);

        if (!$migration instanceof Migration) {
            throw new RuntimeException("{$name}.php must return an object implementing " . Migration::class);
        }

        // MySQL commits DDL implicitly, so a transaction there gives a false sense of safety.
        $transactional = $this->schema->driver() !== 'mysql';

        if ($transactional) {
            $this->pdo->beginTransaction();
        }

        try {
            $migration->$method($this->schema);
            $record();

            if ($transactional) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $hint = $transactional ? '' : ' MySQL cannot undo schema changes, so check for tables or columns'
                . ' this migration created before the error and remove them before running it again.';

            throw new RuntimeException("{$name} failed: " . $e->getMessage() . $hint, 0, $e);
        }
    }

    private function ensureTable(): void
    {
        if ($this->schema->hasTable(self::TABLE)) {
            return;
        }

        $this->schema->create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('migration', 191)->unique();
            $table->integer('batch');
            $table->dateTime('ran_at')->useCurrent();
        });
    }

    /** @return array<string, int> name => batch */
    private function ran(): array
    {
        $rows = (new Query($this->pdo, self::TABLE))->select('migration', 'batch')->orderBy('id')->get();

        return array_column(array_map(fn (array $r): array => [$r['migration'], (int) $r['batch']], $rows), 1, 0);
    }

    /** @return array<string, string> name => path, sorted */
    private function files(): array
    {
        $files = [];

        foreach (glob($this->path . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            if (preg_match(self::NAME, $name)) {
                $files[$name] = $file;
            }
        }

        ksort($files);

        return $files;
    }
}
