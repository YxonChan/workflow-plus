<?php

declare(strict_types=1);

namespace app\support;

use think\facade\Db;

class ScriptExternalScoringService
{
    private const CLIENT_KEY = 'hermes';
    private const ACCOUNT_CLIENT_KEY = 'account-agent';
    private const MAX_LIST_LIMIT = 50;
    private const MAX_DIMENSIONS = 20;

    private static bool $schemaReady = false;

    public static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        Db::execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS `script_external_clients` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `client_key` varchar(32) NOT NULL DEFAULT 'hermes',
  `name` varchar(120) NOT NULL DEFAULT 'Hermes Agent',
  `token_prefix` varchar(20) NOT NULL DEFAULT '',
  `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `status` varchar(24) NOT NULL DEFAULT 'active',
  `last_used_at` datetime DEFAULT NULL,
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_script_external_client_user_key` (`user_id`,`client_key`),
  KEY `idx_script_external_client_token` (`token_prefix`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='剧本外部评分客户端'
SQL);

        Db::execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS `script_ai_scores` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL DEFAULT 0,
  `external_client_id` bigint unsigned NOT NULL DEFAULT 0,
  `scorer_name` varchar(120) NOT NULL DEFAULT 'Hermes Agent',
  `score_version` varchar(64) NOT NULL DEFAULT 'v1',
  `request_id` varchar(100) DEFAULT NULL,
  `overall_score` decimal(5,2) NOT NULL DEFAULT 0.00,
  `dimensions_json` json DEFAULT NULL,
  `summary` text,
  `strengths` text,
  `weaknesses` text,
  `suggestions` text,
  `raw_payload_json` json DEFAULT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'submitted',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_script_score_version` (`project_id`,`external_client_id`,`score_version`),
  UNIQUE KEY `uniq_script_score_request` (`external_client_id`,`request_id`),
  KEY `idx_script_score_project_time` (`project_id`,`update_time`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='外部 Agent 剧本评分'
SQL);

        self::$schemaReady = true;
    }

    public static function accessStatus(int $userId): array
    {
        self::ensureSchema();
        $client = Db::name('script_external_clients')
            ->where('user_id', $userId)
            ->where('client_key', self::CLIENT_KEY)
            ->find();

        return [
            'configured' => is_array($client) && (string) ($client['token_hash'] ?? '') !== '',
            'active' => is_array($client) && (string) ($client['status'] ?? '') === 'active',
            'client_name' => is_array($client) ? (string) ($client['name'] ?? 'Hermes Agent') : 'Hermes Agent',
            'token_prefix' => is_array($client) ? (string) ($client['token_prefix'] ?? '') : '',
            'last_used_at' => is_array($client) ? ($client['last_used_at'] ?? null) : null,
            'update_time' => is_array($client) ? ($client['update_time'] ?? null) : null,
            'endpoints' => self::endpointMap(),
        ];
    }

    public static function accountClient(int $userId, string $displayName): array
    {
        self::ensureSchema();
        $displayName = mb_substr(trim($displayName), 0, 90);
        $name = $displayName !== '' ? 'Account Agent - ' . $displayName : 'Account Agent';
        $now = date('Y-m-d H:i:s');
        $client = Db::name('script_external_clients')
            ->where('user_id', $userId)
            ->where('client_key', self::ACCOUNT_CLIENT_KEY)
            ->find();

        if (is_array($client)) {
            Db::name('script_external_clients')->where('id', (int) $client['id'])->update([
                'name' => $name,
                'status' => 'active',
                'last_used_at' => $now,
                'update_time' => $now,
            ]);
            $client['name'] = $name;
            $client['status'] = 'active';
            $client['last_used_at'] = $now;
            $client['update_time'] = $now;
            return $client;
        }

        try {
            $clientId = (int) Db::name('script_external_clients')->insertGetId([
                'user_id' => $userId,
                'client_key' => self::ACCOUNT_CLIENT_KEY,
                'name' => $name,
                'token_prefix' => '',
                'token_hash' => '',
                'status' => 'active',
                'last_used_at' => $now,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            return [
                'id' => $clientId,
                'user_id' => $userId,
                'client_key' => self::ACCOUNT_CLIENT_KEY,
                'name' => $name,
                'status' => 'active',
            ];
        } catch (\Throwable $e) {
            $duplicate = str_contains($e->getMessage(), '1062')
                || str_contains($e->getMessage(), '23000')
                || str_contains($e->getMessage(), 'Duplicate entry');
            if (!$duplicate) {
                throw $e;
            }
        }

        $client = Db::name('script_external_clients')
            ->where('user_id', $userId)
            ->where('client_key', self::ACCOUNT_CLIENT_KEY)
            ->find();
        if (!is_array($client)) {
            throw new \RuntimeException('无法初始化账号 Agent 评分身份');
        }
        return $client;
    }

    public static function rotateAccessToken(int $userId, string $name = 'Hermes Agent'): array
    {
        self::ensureSchema();
        $name = mb_substr(trim($name), 0, 120);
        if ($name === '') {
            $name = 'Hermes Agent';
        }

        $token = 'mlh_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $prefix = substr($token, 0, 16);
        $hash = hash('sha256', $token);
        $now = date('Y-m-d H:i:s');

        $client = Db::transaction(function () use ($userId, $name, $prefix, $hash, $now): array {
            $existing = Db::name('script_external_clients')
                ->where('user_id', $userId)
                ->where('client_key', self::CLIENT_KEY)
                ->lock(true)
                ->find();

            if (is_array($existing)) {
                Db::name('script_external_clients')->where('id', (int) $existing['id'])->update([
                    'name' => $name,
                    'token_prefix' => $prefix,
                    'token_hash' => $hash,
                    'status' => 'active',
                    'update_time' => $now,
                ]);
                $clientId = (int) $existing['id'];
            } else {
                $clientId = (int) Db::name('script_external_clients')->insertGetId([
                    'user_id' => $userId,
                    'client_key' => self::CLIENT_KEY,
                    'name' => $name,
                    'token_prefix' => $prefix,
                    'token_hash' => $hash,
                    'status' => 'active',
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            }

            return ['id' => $clientId, 'name' => $name];
        });

        return [
            'client_id' => (int) $client['id'],
            'client_name' => (string) $client['name'],
            'token' => $token,
            'token_prefix' => $prefix,
            'endpoints' => self::endpointMap(),
        ];
    }

    public static function revokeAccess(int $userId): array
    {
        self::ensureSchema();
        Db::name('script_external_clients')
            ->where('user_id', $userId)
            ->where('client_key', self::CLIENT_KEY)
            ->update([
                'status' => 'revoked',
                'update_time' => date('Y-m-d H:i:s'),
            ]);

        return self::accessStatus($userId);
    }

    public static function authenticate(string $authorization): array
    {
        self::ensureSchema();
        $authorization = trim($authorization);
        if (!preg_match('/^Bearer\s+([^\s]+)$/i', $authorization, $matches)) {
            abort(401, '缺少有效的外部访问令牌');
        }

        $token = (string) $matches[1];
        if (strlen($token) < 24 || strlen($token) > 200) {
            abort(401, '外部访问令牌无效');
        }

        $prefix = substr($token, 0, 16);
        $hash = hash('sha256', $token);
        $rows = Db::name('script_external_clients')
            ->where('token_prefix', $prefix)
            ->where('status', 'active')
            ->select()
            ->toArray();

        foreach ($rows as $row) {
            if (hash_equals((string) ($row['token_hash'] ?? ''), $hash)) {
                Db::name('script_external_clients')->where('id', (int) $row['id'])->update([
                    'last_used_at' => date('Y-m-d H:i:s'),
                ]);
                return $row;
            }
        }

        abort(401, '外部访问令牌无效或已撤销');
    }

    public static function listProjects(array $client, array $payload): array
    {
        ScriptCreationService::ensureSchema();
        self::ensureSchema();

        $limit = max(1, min(self::MAX_LIST_LIMIT, (int) ($payload['limit'] ?? 20)));
        $cursor = max(0, (int) ($payload['cursor'] ?? 0));
        $query = Db::name('script_ai_projects')
            ->where('user_id', (int) $client['user_id'])
            ->where('status', 'completed')
            ->where('final_content', '<>', '');
        if ($cursor > 0) {
            $query->where('id', '<', $cursor);
        }

        $rows = $query
            ->field('id,title,genre,output_language,region_style,synopsis,requirements,status,run_no,final_content,finished_at,create_time,update_time')
            ->order('id', 'desc')
            ->limit($limit + 1)
            ->select()
            ->toArray();

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $projects = array_map(static function (array $row): array {
            $content = (string) ($row['final_content'] ?? '');
            unset($row['final_content']);
            $row['id'] = (int) $row['id'];
            $row['run_no'] = (int) $row['run_no'];
            $row['content_bytes'] = strlen($content);
            $row['content_sha256'] = hash('sha256', $content);
            $row['latest_score'] = self::latestScore((int) $row['id']);
            return $row;
        }, $rows);

        return [
            'projects' => $projects,
            'has_more' => $hasMore,
            'next_cursor' => $hasMore && $projects !== [] ? (int) end($projects)['id'] : null,
        ];
    }

    public static function projectDetail(array $client, int $projectId): array
    {
        ScriptCreationService::ensureSchema();
        self::ensureSchema();

        $project = Db::name('script_ai_projects')
            ->where('id', $projectId)
            ->where('user_id', (int) $client['user_id'])
            ->where('status', 'completed')
            ->where('final_content', '<>', '')
            ->field('id,title,genre,output_language,region_style,synopsis,requirements,status,run_no,final_content,finished_at,create_time,update_time')
            ->find();
        if (!is_array($project)) {
            abort(404, '可评分剧本不存在');
        }

        $project['id'] = (int) $project['id'];
        $project['run_no'] = (int) $project['run_no'];
        $project['content_bytes'] = strlen((string) $project['final_content']);
        $project['content_sha256'] = hash('sha256', (string) $project['final_content']);
        $project['latest_score'] = self::latestScore($projectId);
        return $project;
    }

    public static function submitScore(array $client, int $projectId, array $payload): array
    {
        ScriptCreationService::ensureSchema();
        self::ensureSchema();

        $overall = filter_var($payload['overall_score'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($overall === false || $overall < 0 || $overall > 100) {
            abort(422, 'overall_score 必须是 0 到 100 之间的数字');
        }

        $scoreVersion = trim((string) ($payload['score_version'] ?? 'v1'));
        if ($scoreVersion === '' || strlen($scoreVersion) > 64 || preg_match('/^[A-Za-z0-9._-]+$/', $scoreVersion) !== 1) {
            abort(422, 'score_version 仅允许字母、数字、点、下划线和连字符，最长 64 个字符');
        }

        $requestId = trim((string) ($payload['request_id'] ?? ''));
        if (strlen($requestId) > 100) {
            abort(422, 'request_id 最长 100 个字符');
        }

        $dimensions = self::normalizeDimensions($payload['dimensions'] ?? []);
        $now = date('Y-m-d H:i:s');
        $record = [
            'scorer_name' => mb_substr(trim((string) ($payload['scorer_name'] ?? $client['name'] ?? 'Hermes Agent')), 0, 120) ?: 'Hermes Agent',
            'score_version' => $scoreVersion,
            'request_id' => $requestId !== '' ? $requestId : null,
            'overall_score' => round((float) $overall, 2),
            'dimensions_json' => self::encodeJson($dimensions),
            'summary' => self::normalizeNarrative($payload['summary'] ?? '', 12000),
            'strengths' => self::normalizeNarrative($payload['strengths'] ?? '', 12000),
            'weaknesses' => self::normalizeNarrative($payload['weaknesses'] ?? '', 12000),
            'suggestions' => self::normalizeNarrative($payload['suggestions'] ?? '', 12000),
            'raw_payload_json' => self::encodeJson($payload),
            'status' => 'submitted',
            'update_time' => $now,
        ];

        $scoreId = Db::transaction(function () use ($client, $projectId, $scoreVersion, $requestId, $record, $now): int {
            $project = Db::name('script_ai_projects')
                ->where('id', $projectId)
                ->where('user_id', (int) $client['user_id'])
                ->where('status', 'completed')
                ->where('final_content', '<>', '')
                ->lock(true)
                ->find();
            if (!is_array($project)) {
                abort(404, '可评分剧本不存在');
            }

            if ($requestId !== '') {
                $requestScore = Db::name('script_ai_scores')
                    ->where('external_client_id', (int) $client['id'])
                    ->where('request_id', $requestId)
                    ->find();
                if (is_array($requestScore)
                    && ((int) $requestScore['project_id'] !== $projectId || (string) $requestScore['score_version'] !== $scoreVersion)) {
                    abort(409, 'request_id 已被其他评分请求使用');
                }
            }

            $existing = Db::name('script_ai_scores')
                ->where('project_id', $projectId)
                ->where('external_client_id', (int) $client['id'])
                ->where('score_version', $scoreVersion)
                ->find();
            if (is_array($existing)) {
                Db::name('script_ai_scores')->where('id', (int) $existing['id'])->update($record);
                return (int) $existing['id'];
            }

            return (int) Db::name('script_ai_scores')->insertGetId($record + [
                'project_id' => $projectId,
                'external_client_id' => (int) $client['id'],
                'create_time' => $now,
            ]);
        });

        $score = Db::name('script_ai_scores')->where('id', $scoreId)->find();
        return is_array($score) ? self::publicScore($score) : [];
    }

    public static function latestScore(int $projectId): ?array
    {
        self::ensureSchema();
        $row = Db::name('script_ai_scores')
            ->where('project_id', $projectId)
            ->where('status', 'submitted')
            ->order(['update_time' => 'desc', 'id' => 'desc'])
            ->find();

        return is_array($row) ? self::publicScore($row) : null;
    }

    public static function scoreHistory(int $projectId, int $limit = 20): array
    {
        self::ensureSchema();
        $rows = Db::name('script_ai_scores')
            ->where('project_id', $projectId)
            ->where('status', 'submitted')
            ->order(['update_time' => 'desc', 'id' => 'desc'])
            ->limit(max(1, min(50, $limit)))
            ->select()
            ->toArray();

        return array_map(static fn (array $row): array => self::publicScore($row), $rows);
    }

    private static function normalizeDimensions(mixed $value): array
    {
        if ($value === null || $value === []) {
            return [];
        }
        if (!is_array($value) || array_keys($value) === range(0, count($value) - 1)) {
            abort(422, 'dimensions 必须是以评分维度名称为键的对象');
        }
        if (count($value) > self::MAX_DIMENSIONS) {
            abort(422, 'dimensions 最多允许 20 个评分维度');
        }

        $result = [];
        foreach ($value as $name => $dimension) {
            $key = mb_substr(trim((string) $name), 0, 80);
            if ($key === '') {
                abort(422, '评分维度名称不能为空');
            }

            if (is_numeric($dimension)) {
                $score = (float) $dimension;
                if ($score < 0 || $score > 100) {
                    abort(422, "评分维度 {$key} 必须在 0 到 100 之间");
                }
                $result[$key] = round($score, 2);
                continue;
            }

            if (!is_array($dimension) || !isset($dimension['score']) || !is_numeric($dimension['score'])) {
                abort(422, "评分维度 {$key} 必须是数字，或包含 score 的对象");
            }
            $score = (float) $dimension['score'];
            if ($score < 0 || $score > 100) {
                abort(422, "评分维度 {$key}.score 必须在 0 到 100 之间");
            }
            $result[$key] = [
                'score' => round($score, 2),
                'comment' => mb_substr(trim((string) ($dimension['comment'] ?? '')), 0, 2000),
            ];
        }

        return $result;
    }

    private static function normalizeNarrative(mixed $value, int $limit): string
    {
        if (is_array($value)) {
            $items = [];
            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $items[] = '- ' . trim((string) $item);
                }
            }
            $value = implode("\n", $items);
        }
        return mb_substr(trim((string) $value), 0, $limit);
    }

    private static function publicScore(array $row): array
    {
        $dimensions = json_decode((string) ($row['dimensions_json'] ?? ''), true);
        return [
            'id' => (int) $row['id'],
            'project_id' => (int) $row['project_id'],
            'external_client_id' => (int) $row['external_client_id'],
            'scorer_name' => (string) $row['scorer_name'],
            'score_version' => (string) $row['score_version'],
            'request_id' => $row['request_id'] ?? null,
            'overall_score' => (float) $row['overall_score'],
            'dimensions' => is_array($dimensions) ? $dimensions : [],
            'summary' => (string) ($row['summary'] ?? ''),
            'strengths' => (string) ($row['strengths'] ?? ''),
            'weaknesses' => (string) ($row['weaknesses'] ?? ''),
            'suggestions' => (string) ($row['suggestions'] ?? ''),
            'status' => (string) $row['status'],
            'create_time' => $row['create_time'] ?? null,
            'update_time' => $row['update_time'] ?? null,
        ];
    }

    private static function encodeJson(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > 200000) {
            abort(422, '评分 JSON 数据无效或超过 200 KB');
        }
        return $json;
    }

    private static function endpointMap(): array
    {
        return [
            'list' => '/api/external/v1/scripts/list',
            'detail' => '/api/external/v1/scripts/detail',
            'score' => '/api/external/v1/scripts/score',
        ];
    }
}
