<?php

declare(strict_types=1);

namespace app\support;

use think\facade\Db;

final class PromoVideoSegmentConfig
{
    public const MIN = 1;
    public const MAX = 4;
    public const DEFAULT = 3;
    public const SECONDS_PER_SEGMENT = 15;

    private static bool $schemaReady = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        $rows = Db::query("SHOW COLUMNS FROM `episodes` LIKE 'promo_segment_count'");
        if (!is_array($rows) || $rows === []) {
            Db::execute("ALTER TABLE `episodes` ADD COLUMN `promo_segment_count` tinyint unsigned DEFAULT NULL COMMENT '宣传片每集视频段数 1-4' AFTER `plot_input`");
        }
        self::$schemaReady = true;
    }

    public static function optional(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $count = filter_var($value, FILTER_VALIDATE_INT);
        if ($count === false || $count < self::MIN || $count > self::MAX) {
            throw new \InvalidArgumentException('宣传片视频段数必须是 1-4 的整数');
        }

        return (int) $count;
    }

    public static function fromPayload(array $payload): int
    {
        return self::optional($payload['promo_segment_count'] ?? null) ?? self::DEFAULT;
    }

    /**
     * 优先用本集保存的段数，其次 workflow_run.payload，都没有才回落到默认 3。
     */
    public static function resolve(?int $episodeCount, array $runPayload = []): int
    {
        return self::optional($episodeCount) ?? self::fromPayload($runPayload);
    }

    public static function totalSeconds(int $count): int
    {
        return $count * self::SECONDS_PER_SEGMENT;
    }

    public static function applyToInstruction(string $instruction, int $count): string
    {
        $seconds = self::totalSeconds($count);
        return <<<PROMPT
RUN-SPECIFIC SEGMENT COUNT — HIGHEST PRIORITY
promo_segment_count = {$count}
Output exactly {$count} video node(s), each 15 seconds, for an approximate total of {$seconds} seconds. Do not use the default count and do not output any extra video node.

{$instruction}
PROMPT;
    }

    public static function countStoryboardNodes(string $text): int
    {
        preg_match_all(
            '/(?:【\s*)?(?:视频节点|Video\s+Node)\s*0*([1-9]\d*)\s*(?=[|｜】])/iu',
            $text,
            $matches
        );

        $indexes = array_values(array_unique(array_map('intval', $matches[1] ?? [])));
        return count($indexes);
    }

    public static function trimStoryboardToCount(string $text, int $count): string
    {
        $count = max(self::MIN, min(self::MAX, $count));
        if (self::countStoryboardNodes($text) <= $count) {
            return $text;
        }

        $pattern = '/(?:^|\R)\s*(【\s*(?:Video\s+Node|视频节点)\s*\d{1,3}\s*｜[\s\S]*?)(?=\R\s*【\s*(?:Video\s+Node|视频节点)\s*\d{1,3}\s*｜|\z)/iu';
        preg_match_all($pattern, "\n" . $text, $matches);
        $blocks = $matches[1] ?? [];
        if ($blocks === []) {
            return $text;
        }

        return trim(implode("\n\n", array_slice($blocks, 0, $count)));
    }
}
