<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\Migration;

use Erikwang2013\ClickHouse\Client\ClientInterface;
use Erikwang2013\ClickHouse\Exceptions\QueryException;
use Erikwang2013\ClickHouse\Schema\Builder;

class Migrator
{
    public function __construct(
        private ClientInterface $client,
        private Repository $repository,
        private string $path,
    ) {
    }

    public function install(): void
    {
        $this->repository->createRepository();
    }

    public function run(): array
    {
        $migrations = $this->loadMigrations();
        $ran = array_column($this->repository->getMigrations(), 'migration');
        $pending = array_diff($migrations, $ran);

        if (empty($pending)) {
            return [];
        }

        $batch = $this->repository->getLastBatch() + 1;
        $run = [];

        foreach ($pending as $file) {
            try {
                $migration = $this->resolve($file);
                $migration->up();
                $this->repository->log($file, $batch);
                $run[] = $file;
            } catch (\Throwable $e) {
                throw new QueryException(
                    sprintf('Migration [%s] failed: %s', $file, $e->getMessage()),
                    $file,
                    ['batch' => $batch, 'previously_run' => $run],
                    0,
                    $e,
                );
            }
        }

        return $run;
    }

    public function rollback(?int $steps = null): array
    {
        $batch = $this->repository->getLastBatch();

        if ($batch === 0) {
            return [];
        }
        $migrations = $this->repository->getMigrationsByBatch($batch);

        if ($steps !== null) {
            $migrations = array_slice($migrations, 0, $steps);
        }

        $rolledBack = [];
        foreach ($migrations as $row) {
            $file = $row['migration'];
            $migration = $this->resolve($file);
            $migration->down();
            $this->repository->delete($file);
            $rolledBack[] = $file;
        }

        return $rolledBack;
    }

    public function refresh(): void
    {
        if ($this->rollback() !== []) {
            // ALTER TABLE ... DELETE 是异步 mutation，需等其完成，否则 run() 仍会读到未删除的记录
            $this->repository->waitForMutations();
        }
        $this->run();
    }

    private function loadMigrations(): array
    {
        $files = glob($this->path . '/*.php');
        sort($files, SORT_STRING);
        return array_map(fn($f) => basename($f, '.php'), $files);
    }

    private function resolve(string $file): Migration
    {
        // rollback()/refresh() 的 $file 来自 migrations 表，是不可信输入：
        // 只接受纯文件名（不含路径分隔符与点），否则 require 会被用来加载目录外任意 PHP 文件。
        if (basename($file) !== $file || !preg_match('/^[A-Za-z0-9_]+$/', $file)) {
            throw new QueryException(
                "Invalid migration name [{$file}]: expected a bare filename matching [A-Za-z0-9_]+",
                $file,
            );
        }

        $path = $this->path . '/' . $file . '.php';

        if (!file_exists($path)) {
            throw new QueryException("Migration file not found: {$path}", $path);
        }

        require_once $path;

        // 时间戳前缀可能有多段，如 2026_05_27_000000_create_logs_table → CreateLogsTable
        $class = preg_replace('/^[\d_]+/', '', $file);
        $class = str_replace('_', '', ucwords($class, '_'));

        if (!class_exists($class)) {
            throw new QueryException(
                "Migration class [{$class}] not found in file [{$path}]",
                $path,
            );
        }

        $instance = new $class();
        $instance->setSchema(new Builder($this->client));

        return $instance;
    }
}