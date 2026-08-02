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
        private readonly ClientInterface $client,
        private readonly Repository $repository,
        private readonly string $path,
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
        $this->rollback();
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
        $path = $this->path . '/' . $file . '.php';

        if (!file_exists($path)) {
            throw new QueryException("Migration file not found: {$path}");
        }

        require_once $path;

        $class = preg_replace('/^\d+_/', '', $file);
        $class = str_replace('_', '', ucwords($class, '_'));

        if (!class_exists($class)) {
            throw new QueryException(
                "Migration class [{$class}] not found in file [{$path}]"
            );
        }

        $instance = new $class();
        $instance->setSchema(new Builder($this->client));

        return $instance;
    }
}