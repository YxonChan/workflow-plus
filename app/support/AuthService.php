<?php

declare(strict_types=1);

namespace app\support;

use app\model\User;
use think\facade\Db;
use think\Request;

class AuthService
{
    private const TOKEN_TTL = 604800;
    private const REFRESH_TOKEN_TTL = 2592000;
    private const DEFAULT_PASSWORD = 'Aa123456';

    /** 提升此版本号可在发版后强制各进程重新跑一次 schema 迁移。 */
    private const SCHEMA_VERSION = 6;

    private static bool $schemaReady = false;

    /**
     * 创建双用户登录需要的表和隔离字段；老数据统一归 user1。
     * 热路径（每个鉴权请求）只做进程内 / Redis 短路，避免每请求盲 UPDATE 引发死锁。
     */
    public static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }

        $versionKey = 'auth:schema_version';
        if ((int) RedisCache::get($versionKey, 0) === self::SCHEMA_VERSION) {
            self::$schemaReady = true;
            CreditService::ensureSchema();

            return;
        }

        $lockToken = RedisCache::acquireLock('auth:schema_migrate', 60);
        if ($lockToken === null && RedisCache::isAvailable()) {
            // 其他进程正在迁移：等待版本号就绪，避免并发 ALTER/UPDATE 互锁。
            for ($i = 0; $i < 25; $i++) {
                usleep(40_000);
                if ((int) RedisCache::get($versionKey, 0) === self::SCHEMA_VERSION) {
                    self::$schemaReady = true;
                    CreditService::ensureSchema();

                    return;
                }
            }
        }

        try {
            if ((int) RedisCache::get($versionKey, 0) === self::SCHEMA_VERSION) {
                self::$schemaReady = true;
                CreditService::ensureSchema();

                return;
            }

            self::migrateSchemaWithDeadlockRetry();
            RedisCache::set($versionKey, self::SCHEMA_VERSION, 86400 * 30);
            self::$schemaReady = true;
        } finally {
            if (is_string($lockToken) && $lockToken !== '') {
                RedisCache::releaseLock('auth:schema_migrate', $lockToken);
            }
        }
    }

    private static function migrateSchemaWithDeadlockRetry(): void
    {
        $attempt = 0;
        while (true) {
            try {
                self::migrateSchema();

                return;
            } catch (\Throwable $e) {
                $attempt++;
                if ($attempt >= 3 || !self::isDeadlockException($e)) {
                    throw $e;
                }
                usleep(40_000 * $attempt + random_int(0, 30_000));
            }
        }
    }

    public static function isDeadlockException(\Throwable $e): bool
    {
        $haystack = strtolower($e->getMessage() . ' ' . (string) $e->getCode());
        if (str_contains($haystack, 'deadlock') || str_contains($haystack, '40001') || str_contains($haystack, '1213')) {
            return true;
        }
        $previous = $e->getPrevious();
        if ($previous instanceof \Throwable) {
            return self::isDeadlockException($previous);
        }

        return false;
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function withDeadlockRetry(callable $callback, int $maxAttempts = 3): mixed
    {
        $attempt = 0;
        while (true) {
            try {
                return $callback();
            } catch (\Throwable $e) {
                $attempt++;
                if ($attempt >= $maxAttempts || !self::isDeadlockException($e)) {
                    throw $e;
                }
                usleep(40_000 * $attempt + random_int(0, 30_000));
            }
        }
    }

    private static function migrateSchema(): void
    {
        Db::execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL DEFAULT '',
  `display_name` varchar(120) NOT NULL DEFAULT '',
  `password_hash` varchar(255) NOT NULL DEFAULT '',
  `role` varchar(20) NOT NULL DEFAULT 'user' COMMENT 'admin|user',
  `preferred_locale` varchar(16) NOT NULL DEFAULT 'zh-CN' COMMENT 'zh-CN',
  `status` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1=enabled',
  `create_time` datetime DEFAULT NULL,
  `update_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统登录用户'
SQL);

        if (!self::columnExists('users', 'role')) {
            Db::execute("ALTER TABLE `users` ADD COLUMN `role` varchar(20) NOT NULL DEFAULT 'user' COMMENT 'admin|user' AFTER `password_hash`");
            Db::execute("UPDATE `users` SET `role` = 'user' WHERE `role` = ''");
        }
        if (!self::columnExists('users', 'preferred_locale')) {
            Db::execute("ALTER TABLE `users` ADD COLUMN `preferred_locale` varchar(16) NOT NULL DEFAULT 'zh-CN' COMMENT 'zh-CN' AFTER `role`");
        }
        $badLocaleCount = (int) (Db::query(
            "SELECT COUNT(*) AS c FROM `users` WHERE `preferred_locale` <> 'zh-CN' OR `preferred_locale` IS NULL OR `preferred_locale` = ''"
        )[0]['c'] ?? 0);
        if ($badLocaleCount > 0) {
            Db::execute("UPDATE `users` SET `preferred_locale` = 'zh-CN' WHERE `preferred_locale` <> 'zh-CN' OR `preferred_locale` IS NULL OR `preferred_locale` = ''");
        }

        CreditService::ensureSchema();

        self::seedDefaultUsers();

        foreach (self::tenantTables() as $table) {
            if (!self::tableExists($table)) {
                continue;
            }
            $addedUserId = false;
            if (!self::columnExists($table, 'user_id')) {
                Db::execute("ALTER TABLE `{$table}` ADD COLUMN `user_id` int unsigned NOT NULL DEFAULT 1 COMMENT 'users.id' AFTER `id`");
                Db::execute("ALTER TABLE `{$table}` ADD INDEX `idx_{$table}_user_id` (`user_id`)");
                $addedUserId = true;
            }
            if ($addedUserId || self::tableHasZeroUserIds($table)) {
                Db::execute("UPDATE `{$table}` SET `user_id` = 1 WHERE `user_id` IS NULL OR `user_id` = 0");
            }
        }

        if (self::tableExists('episodes') && self::tableExists('series')) {
            $mismatch = (int) Db::query(
                'SELECT COUNT(*) AS c FROM `episodes` e INNER JOIN `series` s ON s.id = e.series_id WHERE e.user_id <> s.user_id'
            )[0]['c'] ?? 0;
            if ($mismatch > 0) {
                Db::execute(
                    'UPDATE `episodes` e INNER JOIN `series` s ON s.id = e.series_id SET e.user_id = s.user_id WHERE e.user_id <> s.user_id'
                );
            }
        }

        self::ensureModelConfigSchema();
        self::ensureAiRequestLogSchema();
        self::ensureSeriesStyleSchema();
    }

    private static function tableHasZeroUserIds(string $table): bool
    {
        try {
            $row = Db::query("SELECT COUNT(*) AS c FROM `{$table}` WHERE `user_id` IS NULL OR `user_id` = 0 LIMIT 1");
            return (int) ($row[0]['c'] ?? 0) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function login(string $username, string $password): ?array
    {
        self::ensureSchema();

        $user = User::where('username', $username)->where('status', 1)->find();
        if (!$user instanceof User || !password_verify($password, (string) $user->getAttr('password_hash'))) {
            return null;
        }

        return self::issueSession($user);
    }

    public static function refresh(string $refreshToken): ?array
    {
        self::ensureSchema();

        $payload = self::verifyToken($refreshToken, 'refresh');
        if ($payload === null) {
            return null;
        }

        $user = User::where('id', (int) ($payload['uid'] ?? 0))->where('status', 1)->find();
        if (!$user instanceof User) {
            return null;
        }

        return self::issueSession($user);
    }

    public static function userFromRequest(Request $request): ?User
    {
        return self::withDeadlockRetry(function () use ($request): ?User {
            self::ensureSchema();

            $token = self::readToken($request);
            if ($token === '') {
                return null;
            }

            $payload = self::verifyToken($token, 'access');
            if ($payload === null) {
                return null;
            }

            $user = User::where('id', (int) ($payload['uid'] ?? 0))->where('status', 1)->find();

            return $user instanceof User ? $user : null;
        });
    }

    public static function userIdFromRequest(Request $request): ?int
    {
        $token = self::readToken($request);
        if ($token === '') {
            return null;
        }

        $payload = self::verifyToken($token, 'access');
        if ($payload === null) {
            return null;
        }

        $userId = (int) ($payload['uid'] ?? 0);
        return $userId > 0 ? $userId : null;
    }

    public static function serializeUser(User $user): array
    {
        CreditService::ensureSchema();

        return [
            'id' => (int) $user->getAttr('id'),
            'username' => (string) $user->getAttr('username'),
            'display_name' => (string) $user->getAttr('display_name'),
            'role' => self::normalizeRole((string) $user->getAttr('role')),
            'preferred_locale' => LocaleContext::normalizeForStorage((string) $user->getAttr('preferred_locale')),
            'credit_balance' => round((float) ($user->getAttr('credit_balance') ?? 0), 2),
        ];
    }

    public static function updatePreferredLocale(User $user, string $locale): User
    {
        self::ensureSchema();

        $normalized = LocaleContext::normalize($locale);
        if ($normalized === '') {
            throw new \InvalidArgumentException('语言参数无效');
        }

        $user->save(['preferred_locale' => $normalized]);
        LocaleContext::putUserPreferredLocale((int) $user->getAttr('id'), $normalized);
        $fresh = User::find((int) $user->getAttr('id'));
        return $fresh instanceof User ? $fresh : $user;
    }

    private static function seedDefaultUsers(): void
    {
        $defaults = [
            3 => ['username' => 'admin', 'display_name' => '管理员', 'role' => 'admin'],
        ];

        foreach ($defaults as $id => $data) {
            $user = User::where('username', $data['username'])->find();
            if (!$user instanceof User && $id < 3) {
                $user = User::find($id);
            }
            if ($user instanceof User) {
                $updates = [];
                if ((string) $user->getAttr('password_hash') === '') {
                    $updates['password_hash'] = password_hash(self::DEFAULT_PASSWORD, PASSWORD_DEFAULT);
                }
                if ((string) $user->getAttr('username') === '') {
                    $updates['username'] = $data['username'];
                }
                if ((string) $user->getAttr('display_name') === '') {
                    $updates['display_name'] = $data['display_name'];
                }
                if ((string) $user->getAttr('role') === '' || in_array($data['username'], ['user1', 'user2', 'admin'], true)) {
                    $updates['role'] = $data['role'];
                }
                if (LocaleContext::normalize((string) $user->getAttr('preferred_locale')) === '') {
                    $updates['preferred_locale'] = LocaleContext::DEFAULT_LOCALE;
                }
                if ($updates !== []) {
                    $user->save($updates);
                }
                continue;
            }

            $create = [
                'username' => $data['username'],
                'display_name' => $data['display_name'],
                'password_hash' => password_hash(self::DEFAULT_PASSWORD, PASSWORD_DEFAULT),
                'role' => $data['role'],
                'preferred_locale' => LocaleContext::DEFAULT_LOCALE,
                'status' => 1,
            ];
            if ($id < 3) {
                $create['id'] = $id;
            }
            User::create($create);
        }
    }

    public static function normalizeRole(string $role): string
    {
        return $role === 'admin' ? 'admin' : 'user';
    }

    private static function ensureModelConfigSchema(): void
    {
        if (!self::tableExists('model_configs')) {
            return;
        }

        if (!self::columnExists('model_configs', 'user_id')) {
            Db::execute("ALTER TABLE `model_configs` ADD COLUMN `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT 'users.id when scope=user, 0 when global' AFTER `id`");
        }
        $addedScope = false;
        if (!self::columnExists('model_configs', 'scope')) {
            Db::execute("ALTER TABLE `model_configs` ADD COLUMN `scope` varchar(16) NOT NULL DEFAULT 'global' COMMENT 'global|user' AFTER `user_id`");
            $addedScope = true;
        }
        if (!self::columnExists('model_configs', 'enabled')) {
            Db::execute("ALTER TABLE `model_configs` ADD COLUMN `enabled` tinyint unsigned NOT NULL DEFAULT 1 COMMENT '1=enabled' AFTER `is_default`");
        }

        if ($addedScope) {
            Db::execute("UPDATE `model_configs` SET `scope` = 'global', `user_id` = 0");
        }
        $invalidScopeCount = (int) (Db::query(
            "SELECT COUNT(*) AS c FROM `model_configs` WHERE `scope` NOT IN ('global', 'user') OR `scope` IS NULL"
        )[0]['c'] ?? 0);
        if ($invalidScopeCount > 0) {
            Db::execute("UPDATE `model_configs` SET `scope` = 'global' WHERE `scope` NOT IN ('global', 'user') OR `scope` IS NULL");
        }
        $misScopedGlobalCount = (int) Db::name('model_configs')
            ->where('scope', 'global')
            ->where('user_id', '<>', 0)
            ->count();
        if ($misScopedGlobalCount > 0) {
            Db::execute("UPDATE `model_configs` SET `user_id` = 0 WHERE `scope` = 'global' AND `user_id` <> 0");
        }
        $nullEnabledCount = (int) (Db::query(
            "SELECT COUNT(*) AS c FROM `model_configs` WHERE `enabled` IS NULL"
        )[0]['c'] ?? 0);
        if ($nullEnabledCount > 0) {
            Db::execute("UPDATE `model_configs` SET `enabled` = 1 WHERE `enabled` IS NULL");
        }
        self::ensureDefaultImageModelConfig();
        self::ensureArkVideoResolutionOptions();
        self::ensureDefaultSeedanceVideoModel();
        self::addIndexIfMissing('model_configs', 'idx_model_configs_scope_user_type', ['scope', 'user_id', 'type', 'enabled', 'is_default', 'sort', 'id']);
        if ($addedScope || $misScopedGlobalCount > 0 || $invalidScopeCount > 0) {
            RedisCache::bumpVersion('model_configs');
        }
    }

    /**
     * 火山 Ark 视频模型补齐 resolution_options（480p/720p/1080p），不覆盖已有枚举。
     */
    private static function ensureArkVideoResolutionOptions(): void
    {
        if (!self::tableExists('model_configs')) {
            return;
        }

        $rows = Db::name('model_configs')
            ->where('type', 'video')
            ->where('enabled', 1)
            ->select()
            ->toArray();
        if ($rows === []) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $changed = false;
        foreach ($rows as $row) {
            $endpoint = strtolower((string) ($row['endpoint'] ?? ''));
            $options = json_decode((string) ($row['options'] ?? ''), true);
            if (!is_array($options)) {
                $options = [];
            }
            $provider = strtolower(trim((string) ($options['provider'] ?? '')));
            $isArk = in_array($provider, ['ark', 'volcengine_ark', 'volcano_ark'], true)
                || str_contains($endpoint, 'volces.com')
                || str_contains($endpoint, 'ark.cn-');
            if (!$isArk) {
                continue;
            }

            $update = [];
            $resolutionOptions = $options['resolution_options'] ?? null;
            $hasOptions = is_array($resolutionOptions) && $resolutionOptions !== [];
            if (!$hasOptions) {
                $options['resolution_options'] = ['480p', '720p', '1080p'];
                $update['options'] = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $resolution = trim((string) ($options['resolution'] ?? ''));
            if ($resolution === '') {
                $options['resolution'] = '480p';
                $update['options'] = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($update === []) {
                continue;
            }
            $update['update_time'] = $now;
            Db::name('model_configs')->where('id', (int) $row['id'])->update($update);
            $changed = true;
        }

        if ($changed) {
            RedisCache::bumpVersion('model_configs');
        }
    }

    /**
     * 用户侧默认视频通道：Seedance 标准版（ToAPIs）。官方 Ark、电信 Seedance 停用且用户不可见。
     */
    private static function ensureDefaultSeedanceVideoModel(): void
    {
        if (!self::tableExists('model_configs')) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $changed = false;
        $rows = Db::name('model_configs')->where('type', 'video')->select()->toArray();
        $seedanceId = 0;
        $toapisKey = trim((string) env('TOAPIS_API_KEY', ''));
        $hiddenIds = [];
        $globalCandidates = [];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $endpoint = strtolower((string) ($row['endpoint'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $modelId = strtolower(trim((string) ($row['model_id'] ?? '')));
            if (ToapisPrivateAvatarService::isHiddenUserVideo($row)) {
                $hiddenIds[] = $id;
                continue;
            }
            $isToapisSeedance = ToapisPrivateAvatarService::isToapisEndpoint($endpoint)
                && in_array($modelId, ['seedance-2', 'seedance-2-fast', 'doubao-seedance-2-0', 'doubao-seedance-2-0-fast'], true);
            if ($isToapisSeedance || $name === 'Seedance 标准版') {
                $isGlobal = strtolower(trim((string) ($row['scope'] ?? ''))) === 'global'
                    && (int) ($row['user_id'] ?? 0) === 0;
                if ($isGlobal) {
                    $globalCandidates[] = $row;
                }
                if ($toapisKey === '') {
                    $toapisKey = trim((string) ($row['api_key'] ?? ''));
                }
            }
            if ($toapisKey === '' && ToapisPrivateAvatarService::isToapisEndpoint($endpoint)) {
                $toapisKey = trim((string) ($row['api_key'] ?? ''));
            }
        }

        $pickSeedance = static function (array $candidates): int {
            foreach ($candidates as $row) {
                if (trim((string) ($row['name'] ?? '')) === 'Seedance 标准版') {
                    return (int) ($row['id'] ?? 0);
                }
            }
            foreach ($candidates as $row) {
                $modelId = strtolower(trim((string) ($row['model_id'] ?? '')));
                if ($modelId === 'seedance-2') {
                    return (int) ($row['id'] ?? 0);
                }
            }
            $first = $candidates[0] ?? null;

            return is_array($first) ? (int) ($first['id'] ?? 0) : 0;
        };
        $seedanceId = $pickSeedance($globalCandidates);

        if ($hiddenIds !== []) {
            $updated = Db::name('model_configs')
                ->whereIn('id', $hiddenIds)
                ->where(function ($query): void {
                    $query->where('enabled', 1)->whereOr('is_default', 1);
                })
                ->update(['enabled' => 0, 'is_default' => 0, 'update_time' => $now]);
            if ((int) $updated > 0) {
                $changed = true;
            }
        }

        $optionsJson = json_encode([
            'provider' => 'toapis',
            'resolution' => '480p',
            'resolution_options' => ['480p', '720p', '1080p'],
            'aspect_ratio' => '16:9',
            'aspect_ratio_options' => ['16:9', '9:16', '1:1', '4:3', '3:4', '21:9'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($seedanceId <= 0) {
            Db::name('model_configs')->where('type', 'video')->where('is_default', 1)->update([
                'is_default' => 0,
                'update_time' => $now,
            ]);
            $seedanceId = (int) Db::name('model_configs')->insertGetId([
                'user_id' => 0,
                'scope' => 'global',
                'type' => 'video',
                'name' => 'Seedance 标准版',
                'model_id' => 'seedance-2',
                'endpoint' => ToapisPrivateAvatarService::DEFAULT_BASE . '/v1/videos/generations',
                'api_key' => $toapisKey,
                'options' => $optionsJson,
                'is_default' => 1,
                'enabled' => 1,
                'sort' => 0,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            $changed = true;
        } else {
            $row = Db::name('model_configs')->where('id', $seedanceId)->find();
            if (is_array($row)) {
                $update = [];
                if (trim((string) ($row['name'] ?? '')) !== 'Seedance 标准版') {
                    $update['name'] = 'Seedance 标准版';
                }
                if (strtolower(trim((string) ($row['model_id'] ?? ''))) !== 'seedance-2') {
                    $update['model_id'] = 'seedance-2';
                }
                $endpoint = trim((string) ($row['endpoint'] ?? ''));
                if ($endpoint === '' || ToapisPrivateAvatarService::isLegacyToapisHost($endpoint)) {
                    $update['endpoint'] = ToapisPrivateAvatarService::DEFAULT_BASE . '/v1/videos/generations';
                }
                if ((int) ($row['enabled'] ?? 0) !== 1) {
                    $update['enabled'] = 1;
                }
                if ((int) ($row['is_default'] ?? 0) !== 1) {
                    $update['is_default'] = 1;
                }
                $currentKey = trim((string) ($row['api_key'] ?? ''));
                if ($toapisKey !== '' && $currentKey !== $toapisKey) {
                    $update['api_key'] = $toapisKey;
                }
                $currentOptions = json_decode((string) ($row['options'] ?? ''), true);
                if (!is_array($currentOptions)) {
                    $currentOptions = [];
                }
                $needOptions = false;
                if (strtolower(trim((string) ($currentOptions['provider'] ?? ''))) !== 'toapis') {
                    $currentOptions['provider'] = 'toapis';
                    $needOptions = true;
                }
                $resolutionOptions = $currentOptions['resolution_options'] ?? null;
                if (!is_array($resolutionOptions) || $resolutionOptions === []) {
                    $currentOptions['resolution_options'] = ['480p', '720p', '1080p'];
                    $needOptions = true;
                }
                if (trim((string) ($currentOptions['resolution'] ?? '')) === '') {
                    $currentOptions['resolution'] = '480p';
                    $needOptions = true;
                }
                if ($needOptions) {
                    $update['options'] = json_encode($currentOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                if ($update !== []) {
                    $update['update_time'] = $now;
                    Db::name('model_configs')->where('id', $seedanceId)->update($update);
                    $changed = true;
                }
            }
            Db::name('model_configs')
                ->where('type', 'video')
                ->where('is_default', 1)
                ->where('id', '<>', $seedanceId)
                ->update(['is_default' => 0, 'update_time' => $now]);
        }

        if ($changed) {
            RedisCache::bumpVersion('model_configs');
        }
    }

    private static function ensureDefaultImageModelConfig(): void
    {
        if (!self::tableExists('model_configs')) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $changed = false;

        // 清理历史 toapis gpt-image-2，统一改用腾讯云点播 OG/image2。
        $deleted = Db::name('model_configs')
            ->where('type', 'image')
            ->where(function ($query): void {
                $query->whereLike('endpoint', '%toapis.com%')
                    ->whereOr('endpoint', 'like', '%toapis.xyz%')
                    ->whereOr('model_id', 'gpt-image-2')
                    ->whereOr('model_id', 'gpt-image-2-official');
            })
            ->delete();
        if ((int) $deleted > 0) {
            $changed = true;
        }

        $existing = Db::name('model_configs')
            ->where('type', 'image')
            ->where('scope', 'global')
            ->where('user_id', 0)
            ->where(function ($query): void {
                $query->where('endpoint', ImageGenerationOptions::DEFAULT_ENDPOINT)
                    ->whereOr('model_id', ImageGenerationOptions::DEFAULT_MODEL_ID);
            })
            ->order(['is_default' => 'desc', 'id' => 'asc'])
            ->find();

        if (!is_array($existing) || (int) ($existing['id'] ?? 0) <= 0) {
            $candidates = Db::name('model_configs')
                ->where('type', 'image')
                ->where('scope', 'global')
                ->where('user_id', 0)
                ->where('enabled', 1)
                ->order(['is_default' => 'desc', 'id' => 'asc'])
                ->select()
                ->toArray();
            foreach ($candidates as $candidate) {
                $opts = json_decode((string) ($candidate['options'] ?? ''), true);
                $provider = is_array($opts) ? strtolower(trim((string) ($opts['provider'] ?? ''))) : '';
                if ($provider === ImageGenerationOptions::DEFAULT_PROVIDER) {
                    $existing = $candidate;
                    break;
                }
            }
        }

        if (!is_array($existing) || (int) ($existing['id'] ?? 0) <= 0) {
            Db::name('model_configs')
                ->where('type', 'image')
                ->where('is_default', 1)
                ->update(['is_default' => 0, 'update_time' => $now]);

            Db::name('model_configs')->insert([
                'user_id' => 0,
                'scope' => 'global',
                'type' => 'image',
                'name' => ImageGenerationOptions::DEFAULT_MODEL_NAME,
                'model_id' => ImageGenerationOptions::DEFAULT_MODEL_ID,
                'endpoint' => ImageGenerationOptions::DEFAULT_ENDPOINT,
                'api_key' => '',
                'options' => json_encode(
                    ImageGenerationOptions::defaultModelOptions(ImageGenerationOptions::DEFAULT_MODEL_ID),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                'is_default' => 1,
                'enabled' => 1,
                'sort' => 0,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            RedisCache::bumpVersion('model_configs');
            return;
        }

        $id = (int) $existing['id'];
        Db::name('model_configs')
            ->where('type', 'image')
            ->where('is_default', 1)
            ->where('id', '<>', $id)
            ->update(['is_default' => 0, 'update_time' => $now]);

        $update = [];
        if ((int) ($existing['enabled'] ?? 0) !== 1) {
            $update['enabled'] = 1;
        }
        if ((int) ($existing['is_default'] ?? 0) !== 1) {
            $update['is_default'] = 1;
        }
        if (trim((string) ($existing['name'] ?? '')) === '') {
            $update['name'] = ImageGenerationOptions::DEFAULT_MODEL_NAME;
        }
        if (trim((string) ($existing['model_id'] ?? '')) === '') {
            $update['model_id'] = ImageGenerationOptions::DEFAULT_MODEL_ID;
        }
        if (trim((string) ($existing['endpoint'] ?? '')) === '') {
            $update['endpoint'] = ImageGenerationOptions::DEFAULT_ENDPOINT;
        }

        // 不覆盖密钥与已有业务字段；仅补齐缺失的腾讯默认 options 键。
        $currentOptions = json_decode((string) ($existing['options'] ?? ''), true);
        if (!is_array($currentOptions)) {
            $currentOptions = [];
        }
        $defaults = ImageGenerationOptions::defaultModelOptions(ImageGenerationOptions::DEFAULT_MODEL_ID);
        $mergedOptions = $currentOptions;
        $optionsChanged = false;
        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $mergedOptions) || $mergedOptions[$key] === '' || $mergedOptions[$key] === null) {
                $mergedOptions[$key] = $value;
                $optionsChanged = true;
            }
        }
        // 轮询窗口过短会导致参考图假超时；低于默认时抬升到默认。
        $pollAttempts = (int) ($mergedOptions['poll_attempts'] ?? 0);
        $defaultPollAttempts = (int) ($defaults['poll_attempts'] ?? 120);
        if ($pollAttempts > 0 && $pollAttempts < $defaultPollAttempts) {
            $mergedOptions['poll_attempts'] = $defaultPollAttempts;
            $optionsChanged = true;
        }
        $pollInterval = (int) ($mergedOptions['poll_interval'] ?? 0);
        if ($pollInterval <= 0) {
            $mergedOptions['poll_interval'] = (int) ($defaults['poll_interval'] ?? 3);
            $optionsChanged = true;
        }
        if ($optionsChanged) {
            $update['options'] = json_encode($mergedOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($update !== []) {
            $update['update_time'] = $now;
            Db::name('model_configs')->where('id', $id)->update($update);
            $changed = true;
        }

        if ($changed) {
            RedisCache::bumpVersion('model_configs');
        }
    }

    private static function ensureAiRequestLogSchema(): void
    {
        if (!self::tableExists('ai_request_logs')) {
            return;
        }

        if (!self::columnExists('ai_request_logs', 'prompt_tokens')) {
            Db::execute("ALTER TABLE `ai_request_logs` ADD COLUMN `prompt_tokens` int unsigned NOT NULL DEFAULT 0 COMMENT 'usage.prompt_tokens' AFTER `usage_json`");
        }
        if (!self::columnExists('ai_request_logs', 'completion_tokens')) {
            Db::execute("ALTER TABLE `ai_request_logs` ADD COLUMN `completion_tokens` int unsigned NOT NULL DEFAULT 0 COMMENT 'usage.completion_tokens' AFTER `prompt_tokens`");
        }
        if (!self::columnExists('ai_request_logs', 'total_tokens')) {
            Db::execute("ALTER TABLE `ai_request_logs` ADD COLUMN `total_tokens` int unsigned NOT NULL DEFAULT 0 COMMENT 'usage.total_tokens' AFTER `completion_tokens`");
        }

        self::addIndexIfMissing('ai_request_logs', 'idx_ai_logs_user_time', ['user_id', 'create_time']);
        self::addIndexIfMissing('ai_request_logs', 'idx_ai_logs_user_model_time', ['user_id', 'model_config_id', 'create_time']);
    }

    private static function ensureSeriesStyleSchema(): void
    {
        if (!self::tableExists('series')) {
            return;
        }

        if (!self::columnExists('series', 'visual_style_variant')) {
            Db::execute("ALTER TABLE `series` ADD COLUMN `visual_style_variant` varchar(64) NOT NULL DEFAULT '' COMMENT '二级视觉风格' AFTER `visual_style`");
        }
    }

    private static function tenantTables(): array
    {
        return [
            'ai_request_logs',
            'asset_image_jobs',
            'asset_images',
            'assets',
            'custom_nodes',
            'episodes',
            'prompt_templates',
            'series',
            'shots',
            'shot_media_versions',
            'storyboard_revisions',
            'video_jobs',
            'workflow_run_nodes',
            'workflow_runs',
            'workflows',
        ];
    }

    private static function tableExists(string $table): bool
    {
        return Db::query("SHOW TABLES LIKE '{$table}'") !== [];
    }

    private static function columnExists(string $table, string $column): bool
    {
        return Db::query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'") !== [];
    }

    private static function addIndexIfMissing(string $table, string $indexName, array $columns): void
    {
        $rows = Db::query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$indexName}'");
        if ($rows !== []) {
            return;
        }

        $columnSql = implode(',', array_map(fn (string $column): string => "`{$column}`", $columns));
        Db::execute("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` ({$columnSql})");
    }

    private static function issueSession(User $user): array
    {
        $userId = (int) $user->getAttr('id');
        $username = (string) $user->getAttr('username');
        LocaleContext::putUserPreferredLocale($userId, (string) $user->getAttr('preferred_locale'));
        $now = time();
        $accessExpiresAt = $now + self::TOKEN_TTL;
        $refreshExpiresAt = $now + self::REFRESH_TOKEN_TTL;
        $accessToken = self::issueToken($userId, $username, 'access', $accessExpiresAt);

        return [
            'token' => $accessToken,
            'access_token' => $accessToken,
            'expires_at' => $accessExpiresAt,
            'expires_in' => self::TOKEN_TTL,
            'refresh_token' => self::issueToken($userId, $username, 'refresh', $refreshExpiresAt),
            'refresh_expires_at' => $refreshExpiresAt,
            'refresh_expires_in' => self::REFRESH_TOKEN_TTL,
            'user' => self::serializeUser($user),
        ];
    }

    private static function issueToken(int $userId, string $username, string $type, int $expiresAt): string
    {
        $payload = [
            'uid' => $userId,
            'username' => $username,
            'typ' => $type,
            'exp' => $expiresAt,
            'iat' => time(),
        ];
        $body = self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $body . '.' . self::sign($body);
    }

    private static function verifyToken(string $token, string $expectedType): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || !hash_equals(self::sign($parts[0]), $parts[1])) {
            return null;
        }

        $json = self::base64UrlDecode($parts[0]);
        $payload = json_decode($json, true);
        if (!is_array($payload) || (int) ($payload['exp'] ?? 0) < time()) {
            return null;
        }
        if ((string) ($payload['typ'] ?? 'access') !== $expectedType) {
            return null;
        }

        return $payload;
    }

    private static function readToken(Request $request): string
    {
        $header = (string) ($request->header('authorization') ?: $request->header('Authorization'));
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return trim($m[1]);
        }

        return trim((string) ($request->param('token') ?: $request->header('token')));
    }

    private static function sign(string $body): string
    {
        return hash_hmac('sha256', $body, self::secret());
    }

    private static function secret(): string
    {
        $secret = (string) env('AUTH_SECRET', '');
        return $secret !== '' ? $secret : 'malulu-ai-local-auth-secret';
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'));
    }
}
