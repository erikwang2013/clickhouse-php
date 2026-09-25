<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */


namespace Erikwang2013\ClickHouse\ThinkPHP\command;

use Erikwang2013\ClickHouse\ClickHouse as ClickHouseClient;
use Erikwang2013\ClickHouse\Support\Pet;
use think\console\Command;
use think\console\Input;
use think\console\Output;

class ClickHouse extends Command
{
    protected function configure(): void
    {
        $this->setName('clickhouse:table-list')
            ->setDescription('List all tables in ClickHouse');
    }

    protected function execute(Input $input, Output $output): void
    {
        foreach (Pet::lines() as $line) {
            $output->writeln($line);
        }
        $output->writeln('');

        $tables = ClickHouseClient::schema()->getTables();
        $output->writeln('<info>ClickHouse Tables:</info>');
        foreach ($tables as $table) {
            $output->writeln('  ' . ($table['name'] ?? $table));
        }
    }
}