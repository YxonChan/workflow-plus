<?php

declare(strict_types=1);

namespace app\command;

use app\model\Episode;
use app\model\WorkflowRun;
use app\model\WorkflowRunNode;
use app\support\RedisCache;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 从已完成的剧本工作流节点 output 回填 episodes.plot_input（修复 plot 字段未映射的历史数据）。
 */
class BackfillEpisodePlots extends Command
{
    protected function configure(): void
    {
        $this->setName('series:backfill-plots')
            ->setDescription('Backfill empty episode plot_input from workflow_run_nodes output_json.')
            ->addOption('series', null, Option::VALUE_OPTIONAL, 'Only repair this series id');
    }

    protected function execute(Input $input, Output $output): int
    {
        $seriesId = (int) $input->getOption('series');
        $runQuery = WorkflowRun::where('status', 'success')->order('id', 'desc');
        if ($seriesId > 0) {
            $runQuery->where('series_id', $seriesId);
        }
        $runs = $runQuery->select();
        $updated = 0;

        foreach ($runs as $run) {
            $sid = (int) $run->getAttr('series_id');
            if ($sid <= 0) {
                continue;
            }

            $episodes = $this->loadEpisodesFromRunNodes((int) $run->getAttr('id'));
            if ($episodes === []) {
                continue;
            }

            foreach ($episodes as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $number = (int) ($item['number'] ?? 0);
                $plot = $this->resolvePlot($item);
                if ($number <= 0 || $plot === '') {
                    continue;
                }

                $episode = Episode::where('series_id', $sid)->where('number', $number)->find();
                if ($episode === null) {
                    continue;
                }
                $current = trim((string) ($episode->getAttr('plot_input') ?? ''));
                if ($current !== '') {
                    continue;
                }

                $episode->save(['plot_input' => $plot]);
                $updated++;
            }
        }

        if ($updated > 0) {
            RedisCache::bumpVersion('series');
        }
        $output->writeln("<info>Backfilled {$updated} episode plot_input row(s).</info>");

        return 0;
    }

    private function loadEpisodesFromRunNodes(int $runId): array
    {
        $nodes = WorkflowRunNode::where('run_id', $runId)
            ->where('status', 'success')
            ->where('kind', 'text')
            ->order('sort', 'desc')
            ->select();

        foreach ($nodes as $node) {
            $label = (string) $node->getAttr('label');
            $output = $node->getAttr('output_json');
            if (!is_array($output)) {
                continue;
            }
            if (!str_contains($label, '剧集') && !str_contains($label, '分集') && !isset($output['episodes'])) {
                continue;
            }
            $list = $output['episodes'] ?? [];
            if (is_array($list) && $list !== []) {
                return array_values(array_filter($list, static fn ($x) => is_array($x)));
            }
        }

        return [];
    }

    private function resolvePlot(array $item): string
    {
        $plot = trim((string) (
            $item['plot_input']
            ?? $item['plot']
            ?? $item['summary']
            ?? $item['description']
            ?? $item['synopsis']
            ?? $item['overview']
            ?? $item['hook']
            ?? $item['content']
            ?? ''
        ));
        if ($plot !== '') {
            return $plot;
        }

        $parts = [];
        foreach (['key_events', 'shot_sequence', 'emotional_beats', 'scenes'] as $field) {
            if (!isset($item[$field]) || !is_array($item[$field])) {
                continue;
            }
            foreach ($item[$field] as $entry) {
                $value = is_array($entry)
                    ? trim((string) ($entry['description'] ?? $entry['desc'] ?? $entry['summary'] ?? $entry['content'] ?? $entry['title'] ?? ''))
                    : trim((string) $entry);
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
        }

        return implode("\n", $parts);
    }
}
