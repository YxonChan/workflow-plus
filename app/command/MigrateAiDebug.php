<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 安全执行 AI 调试字段迁移（已存在的列会跳过）。
 */
class MigrateAiDebug extends Command
{
    protected function configure(): void
    {
        $this->setName('migrate:ai-debug')
            ->setDescription('Add workflow AI debug columns to ai_request_logs and workflow_run_nodes.');
    }

    protected function execute(Input $input, Output $output): int
    {
        $this->ensureTable('ai_request_logs');
        $this->addColumnIfMissing('ai_request_logs', 'workflow_run_id', '`workflow_run_id` bigint unsigned DEFAULT NULL COMMENT \'workflow_runs.id\' AFTER `source`');
        $this->addColumnIfMissing('ai_request_logs', 'workflow_run_node_id', '`workflow_run_node_id` bigint unsigned DEFAULT NULL COMMENT \'workflow_run_nodes.id\' AFTER `workflow_run_id`');
        $this->addColumnIfMissing('ai_request_logs', 'finish_reason', '`finish_reason` varchar(32) NOT NULL DEFAULT \'\' COMMENT \'stop|length\' AFTER `llm_model`');
        $this->addColumnIfMissing('ai_request_logs', 'max_tokens', '`max_tokens` int unsigned NOT NULL DEFAULT 0 COMMENT \'max_tokens\' AFTER `finish_reason`');
        $this->addColumnIfMissing('ai_request_logs', 'usage_json', '`usage_json` json DEFAULT NULL COMMENT \'token usage\' AFTER `max_tokens`');
        $this->addColumnIfMissing('ai_request_logs', 'assistant_content', '`assistant_content` longtext COMMENT \'assistant 全文\' AFTER `content_preview`');
        $this->addIndexIfMissing('ai_request_logs', 'idx_ai_logs_workflow_run', 'workflow_run_id');
        $this->addIndexIfMissing('ai_request_logs', 'idx_ai_logs_workflow_run_node', 'workflow_run_node_id');

        $this->ensureTable('workflow_run_nodes');
        $this->addColumnIfMissing('workflow_run_nodes', 'ai_request_log_id', '`ai_request_log_id` bigint unsigned DEFAULT NULL COMMENT \'ai_request_logs.id\' AFTER `raw_output`');
        $this->addColumnIfMissing('workflow_run_nodes', 'request_payload_json', '`request_payload_json` json DEFAULT NULL COMMENT \'请求快照\' AFTER `ai_request_log_id`');
        $this->addColumnIfMissing('workflow_run_nodes', 'ai_meta_json', '`ai_meta_json` json DEFAULT NULL COMMENT \'AI 元数据\' AFTER `request_payload_json`');
        $this->addIndexIfMissing('workflow_run_nodes', 'idx_workflow_run_nodes_ai_log', 'ai_request_log_id');

        $output->writeln('<info>AI debug migration finished.</info>');
        return 0;
    }

    private function ensureTable(string $table): void
    {
        $exists = Db::query("SHOW TABLES LIKE '{$table}'");
        if ($exists === []) {
            throw new \RuntimeException("表 {$table} 不存在，请先执行 database/*.sql 初始化库表");
        }
    }

    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        if ($this->columnExists($table, $column)) {
            $this->output->writeln("skip column {$table}.{$column}");
            return;
        }
        Db::execute("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
        $this->output->writeln("added column {$table}.{$column}");
    }

    private function addIndexIfMissing(string $table, string $indexName, string $column): void
    {
        if ($this->indexExists($table, $indexName)) {
            $this->output->writeln("skip index {$table}.{$indexName}");
            return;
        }
        Db::execute("ALTER TABLE `{$table}` ADD KEY `{$indexName}` (`{$column}`)");
        $this->output->writeln("added index {$table}.{$indexName}");
    }

    private function columnExists(string $table, string $column): bool
    {
        $db = (string) env('DB_NAME', '');
        $rows = Db::query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$db, $table, $column],
        );
        return $rows !== [];
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $db = (string) env('DB_NAME', '');
        $rows = Db::query(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$db, $table, $indexName],
        );
        return $rows !== [];
    }
}
