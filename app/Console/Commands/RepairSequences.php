<?php

namespace App\Console\Commands;

use App\Models;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use RuntimeException;

class RepairSequences extends Command
{
    protected $signature = 'abs:repair-sequences {--dry-run : 只检查所有自增序列，不修改数据库}';

    protected $description = '检查 API 数据库所有表的自增序列，修复落后于已有数据的序列';

    public function handle(): int
    {
        $connection = (new Models\User)->getConnection();
        if ($connection->getDriverName() !== 'pgsql') {
            $this->error('此命令仅用于 PostgreSQL API 数据库。');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->line('API 数据库：'.$connection->getDatabaseName());

        try {
            $sequences = $this->sequences($connection);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $rows = [];
        $repairs = 0;
        $failures = 0;
        foreach ($sequences as $sequence) {
            $field = $sequence->table_schema.'.'.$sequence->table_name.'.'.$sequence->column_name;
            try {
                $result = $this->repair($connection, $sequence, $dryRun);
                $repairs += (int) $result['needsRepair'];
                $status = $result['needsRepair'] ? ($dryRun ? '待修复' : '已修复') : '正常';
                $rows[] = [$field, $result['maximum'] ?? '空表', $result['currentNext'], $result['next'], $status];
            } catch (\Throwable $exception) {
                $failures++;
                $rows[] = [$field, '-', '-', '-', '失败'];
                $this->error($field.'：'.$exception->getMessage());
            }
        }

        $this->table(['自增字段', '已有最大值', '原下一个值', '目标下一个值', '结果'], $rows);
        if ($dryRun) {
            $this->line('检查完成：'.count($sequences).' 个序列，'.$repairs.' 个待修复，'.$failures.' 个失败。未修改数据库。');
            if ($repairs > 0) {
                $this->warn('执行 php artisan abs:repair-sequences 即可修复。');
            }
        } else {
            $this->line('处理完成：'.count($sequences).' 个序列，修复 '.$repairs.' 个，失败 '.$failures.' 个。');
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function sequences(Connection $connection): array
    {
        // PostgreSQL catalogs cover both serial and identity columns without a model/table list.
        return $connection->selectFromWriteConnection(<<<'SQL'
            SELECT tn.nspname AS table_schema, t.relname AS table_name, a.attname AS column_name,
                sn.nspname AS sequence_schema, s.relname AS sequence_name,
                q.seqincrement AS increment, q.seqmax AS maximum_value, q.seqcycle AS cycle
            FROM pg_catalog.pg_class s
            JOIN pg_catalog.pg_namespace sn ON sn.oid = s.relnamespace
            JOIN pg_catalog.pg_sequence q ON q.seqrelid = s.oid
            JOIN pg_catalog.pg_depend d ON d.classid = 'pg_catalog.pg_class'::regclass
                AND d.objid = s.oid AND d.objsubid = 0
                AND d.refclassid = 'pg_catalog.pg_class'::regclass AND d.deptype IN ('a', 'i')
            JOIN pg_catalog.pg_class t ON t.oid = d.refobjid AND t.relkind IN ('r', 'p')
            JOIN pg_catalog.pg_namespace tn ON tn.oid = t.relnamespace
            JOIN pg_catalog.pg_attribute a ON a.attrelid = t.oid
                AND a.attnum = d.refobjsubid AND NOT a.attisdropped
            WHERE s.relkind = 'S' AND tn.nspname !~ '^pg_' AND tn.nspname <> 'information_schema'
            ORDER BY tn.nspname, t.relname, a.attname
            SQL);
    }

    private function repair(Connection $connection, object $sequence, bool $dryRun): array
    {
        $increment = (int) $sequence->increment;
        if ($increment <= 0 || $sequence->cycle) {
            throw new RuntimeException('递减或循环序列需要单独处理，未修改。');
        }

        $table = $this->identifier($sequence->table_schema).'.'.$this->identifier($sequence->table_name);
        $sequenceTable = $this->identifier($sequence->sequence_schema).'.'.$this->identifier($sequence->sequence_name);

        return $connection->transaction(function () use ($connection, $sequence, $dryRun, $increment, $table, $sequenceTable) {
            if (! $dryRun) {
                $connection->statement("SET LOCAL lock_timeout = '10s'");
                // Hold only this table's write lock; release it when this repair commits.
                $connection->statement('LOCK TABLE '.$table.' IN SHARE ROW EXCLUSIVE MODE');
            }

            $state = $connection->selectOne('SELECT last_value, is_called FROM '.$sequenceTable);
            // Include soft-deleted rows, and preserve an empty table's configured starting value.
            $maximum = $connection->query()->fromRaw($table)->max($connection->raw($this->identifier($sequence->column_name)));
            $currentNext = (int) $state->last_value + ($state->is_called ? $increment : 0);
            $next = $currentNext;
            if ($maximum !== null && $maximum >= $currentNext) {
                $next += (intdiv((int) $maximum - $currentNext, $increment) + 1) * $increment;
            }
            if (! is_int($next) || $next > (int) $sequence->maximum_value) {
                throw new RuntimeException('自增序列已达到上限，无法继续生成唯一值，未修改。');
            }
            $needsRepair = $next > $currentNext;

            if (! $dryRun && $needsRepair) {
                // RESTART is transactional and never rewinds a sequence that is already ahead.
                $connection->statement('ALTER SEQUENCE '.$sequenceTable.' RESTART WITH '.$next);
            }

            return compact('maximum', 'currentNext', 'next', 'needsRepair');
        });
    }

    private function identifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }
}
