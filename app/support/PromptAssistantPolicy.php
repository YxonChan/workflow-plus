<?php

declare(strict_types=1);

namespace app\support;

use InvalidArgumentException;

class PromptAssistantPolicy
{
    public const ACTIONS = ['ask_user', 'generate_prompt', 'create_skill'];
    public const BRIEF_FIELDS = ['subject', 'goal', 'audience', 'channel', 'selling_points', 'scene', 'action', 'constraints', 'asset_requirements'];

    public static function text(mixed $value, int $max, bool $required = false): string
    {
        if (!is_string($value) || mb_strlen($value) > $max) {
            throw new InvalidArgumentException('字段类型或长度不正确');
        }
        $text = trim($value);
        if ($required && $text === '') {
            throw new InvalidArgumentException('必填内容不能为空');
        }
        return $text;
    }

    public static function settings(array $value): array
    {
        self::keys($value, ['mode', 'text_model_id', 'vision_model_id', 'target_model_id', 'visual_style', 'style_preset_id', 'aspect_ratio', 'duration', 'duration_confirmed', 'asset_ids', 'reference_ids']);
        $mode = $value['mode'] ?? 'image';
        $style = $value['visual_style'] ?? 'realistic';
        $ratio = $value['aspect_ratio'] ?? '1:1';
        if (!in_array($mode, ['image', 'video'], true) || !in_array($style, ['realistic', 'anime', '3d'], true)
            || !in_array($ratio, ['adaptive', '1:1', '9:16', '16:9', '4:3', '3:4', '21:9'], true)) {
            throw new InvalidArgumentException('媒体类型、画风或比例不正确');
        }
        $duration = self::id($value['duration'] ?? 5);
        if ($duration < 1 || $duration > 180) {
            throw new InvalidArgumentException('总时长必须在 1–180 秒之间');
        }
        if (isset($value['duration_confirmed']) && !is_bool($value['duration_confirmed'])) {
            throw new InvalidArgumentException('时长确认状态不正确');
        }
        $assets = $value['asset_ids'] ?? [];
        if (!is_array($assets) || array_values($assets) !== $assets || count($assets) > 6) {
            throw new InvalidArgumentException('最多引用六个素材');
        }
        $references = $value['reference_ids'] ?? [];
        if (!is_array($references) || array_values($references) !== $references || count($references) + count($assets) > 6) {
            throw new InvalidArgumentException('最多引用六张参考图或素材');
        }
        return [
            'mode' => $mode, 'text_model_id' => self::id($value['text_model_id'] ?? 0),
            'vision_model_id' => self::id($value['vision_model_id'] ?? 0),
            'target_model_id' => self::id($value['target_model_id'] ?? 0),
            'visual_style' => $style, 'style_preset_id' => self::id($value['style_preset_id'] ?? 0),
            'aspect_ratio' => $ratio, 'duration' => $duration, 'duration_confirmed' => $value['duration_confirmed'] ?? false,
            'asset_ids' => array_values(array_unique(array_map([self::class, 'id'], $assets))),
            'reference_ids' => array_values(array_unique(array_map([self::class, 'id'], $references))),
        ];
    }

    public static function id(mixed $value): int
    {
        if ((!is_int($value) && !(is_string($value) && ctype_digit($value))) || (int) $value < 0) {
            throw new InvalidArgumentException('ID 或数值不正确');
        }
        return (int) $value;
    }

    public static function keys(array $value, array $allowed): void
    {
        if (array_diff(array_keys($value), $allowed) !== []) {
            throw new InvalidArgumentException('请求包含白名单之外的字段');
        }
    }

    public static function plan(array $value, array $catalog, string $mode): array
    {
        self::keys($value, ['action', 'reply', 'brief', 'questions', 'skill_ids', 'reason', 'candidate', 'specifications']);
        if (!in_array($value['action'] ?? '', self::ACTIONS, true)) {
            throw new InvalidArgumentException('AI 决策不在允许范围内');
        }
        $brief = $value['brief'] ?? [];
        if (!is_array($brief)) {
            throw new InvalidArgumentException('需求摘要格式不正确');
        }
        self::keys($brief, self::BRIEF_FIELDS);
        foreach ($brief as $key => $text) {
            $brief[$key] = self::text($text, 1600);
        }
        $questions = $value['questions'] ?? [];
        if (!is_array($questions) || array_values($questions) !== $questions || count($questions) > 3) {
            throw new InvalidArgumentException('每轮最多询问三个问题');
        }
        $questions = array_map(static function ($question): array {
            if (!is_array($question)) {
                throw new InvalidArgumentException('问题格式不正确');
            }
            self::keys($question, ['text', 'options']);
            return ['text' => self::text($question['text'] ?? '', 400, true), 'options' => self::strings($question['options'] ?? [], 6, 120)];
        }, $questions);
        $ids = self::strings($value['skill_ids'] ?? [], 3, 80);
        foreach ($ids as $id) {
            $matches = array_filter($catalog, static fn (array $skill): bool => $skill['id'] === $id
                && in_array($mode, $skill['modes'], true) && $skill['status'] === 'ready');
            if ($matches === []) {
                throw new InvalidArgumentException('AI 选择了不可访问或不适用的 Skill');
            }
        }
        if ($value['action'] !== 'ask_user' && (trim($brief['subject'] ?? '') === '' || trim($brief['goal'] ?? '') === '')) {
            throw new InvalidArgumentException('生成前需要明确主体和目标');
        }
        if ($value['action'] === 'generate_prompt' && $ids === []) {
            throw new InvalidArgumentException('生成前需要选择适用的 Skill');
        }
        $candidate = $value['action'] === 'create_skill' ? self::candidate($value['candidate'] ?? [], $mode) : null;
        $specifications = $value['specifications'] ?? [];
        if (!is_array($specifications)) throw new InvalidArgumentException('交付规格格式不正确');
        self::keys($specifications, ['duration', 'evidence']);
        if ($specifications !== []) {
            $seconds = self::id($specifications['duration'] ?? 0);
            if ($mode !== 'video' || $seconds < 1 || $seconds > 180) throw new InvalidArgumentException('视频时长必须在1至180秒之间');
            $specifications = ['duration' => $seconds, 'evidence' => self::text($specifications['evidence'] ?? '', 400, true)];
        }
        return [
            'action' => $value['action'], 'reply' => self::text($value['reply'] ?? '', 1800, true), 'brief' => $brief,
            'questions' => $questions, 'skill_ids' => $ids, 'reason' => self::text($value['reason'] ?? '', 800), 'candidate' => $candidate,
            'specifications' => $specifications,
        ];
    }

    public static function confirmDuration(array $settings, array $specifications, array $history): array
    {
        if ($specifications === []) return $settings;
        $messages = array_values(array_filter($history, static fn (array $message): bool => $message['role'] === 'user'));
        // An existing duration can only be changed by the latest user message, never an old answer.
        if ($settings['duration_confirmed'] && $specifications['duration'] !== $settings['duration']) {
            $messages = array_slice($messages, -1);
        }
        foreach ($messages as $message) {
            if (preg_match('/(?<!\d)' . preg_quote($specifications['evidence'], '/') . '(?!\d)/u', $message['text'])) {
                return array_replace($settings, ['duration' => $specifications['duration'], 'duration_confirmed' => true]);
            }
        }
        throw new InvalidArgumentException('时长必须有用户原话作为依据，不能把系统默认值或助手回复当成用户确认');
    }

    public static function candidate(mixed $value, string $mode): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('候选 Skill 格式不正确');
        }
        self::keys($value, ['name', 'description', 'instructions', 'test_cases']);
        $name = self::text($value['name'] ?? '', 64, true);
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $name)) {
            throw new InvalidArgumentException('Skill 名称必须为小写字母、数字和短横线');
        }
        $instructions = self::text($value['instructions'] ?? '', 12000, true);
        if (mb_strlen($instructions) < 120) {
            throw new InvalidArgumentException('候选 Skill 方法内容过短');
        }
        $cases = self::strings($value['test_cases'] ?? [], 3, 1000);
        if (count($cases) < 2) {
            throw new InvalidArgumentException('候选 Skill 至少需要两个验证案例');
        }
        return ['name' => $name, 'description' => self::text($value['description'] ?? '', 1024, true),
            'instructions' => $instructions, 'modes' => [$mode], 'test_cases' => $cases];
    }

    public static function strings(mixed $value, int $maxCount, int $maxLength): array
    {
        if (!is_array($value) || array_values($value) !== $value || count($value) > $maxCount) {
            throw new InvalidArgumentException('列表格式或数量不正确');
        }
        return array_map(static fn ($text): string => self::text($text, $maxLength, true), $value);
    }

    public static function questions(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 3) {
            throw new InvalidArgumentException('每轮最多询问三个问题');
        }
        return array_map(static function ($question): array {
            if (!is_array($question)) {
                throw new InvalidArgumentException('问题格式不正确');
            }
            self::keys($question, ['text', 'options']);
            return ['text' => self::text($question['text'] ?? '', 400, true),
                'options' => self::strings($question['options'] ?? [], 6, 120)];
        }, $value);
    }

    public static function result(array $value, array $settings, array $assetIds): array
    {
        self::keys($value, ['title', 'prompt_body', 'shots', 'assumptions', 'asset_requirements', 'reference_usage']);
        $body = self::text($value['prompt_body'] ?? '', 16000, true);
        $shots = $value['shots'] ?? [];
        if (!is_array($shots) || array_values($shots) !== $shots || count($shots) > 12 || ($settings['mode'] === 'image' && $shots !== [])) {
            throw new InvalidArgumentException('分镜数量或媒体类型不正确');
        }
        foreach ($shots as $index => $shot) {
            if (!is_array($shot)) {
                throw new InvalidArgumentException('分镜格式不正确');
            }
            self::keys($shot, ['title', 'duration', 'prompt_body']);
            $duration = self::id($shot['duration'] ?? 0);
            if ($duration < 1 || $duration > $settings['duration']) {
                throw new InvalidArgumentException('分镜时长不正确');
            }
            $shots[$index] = ['title' => self::text($shot['title'] ?? '', 120, true), 'duration' => $duration,
                'prompt_body' => self::text($shot['prompt_body'] ?? '', 5000, true)];
        }
        if ($shots !== [] && array_sum(array_column($shots, 'duration')) !== $settings['duration']) {
            throw new InvalidArgumentException('分镜总时长与任务时长不一致');
        }
        $result = ['title' => self::text($value['title'] ?? '', 120, true), 'prompt_body' => $body, 'shots' => $shots,
            'assumptions' => self::strings($value['assumptions'] ?? [], 10, 500),
            'asset_requirements' => self::strings($value['asset_requirements'] ?? [], 10, 500),
            'settings' => $settings, 'asset_ids' => $assetIds];
        if (array_key_exists('reference_usage', $value)) $result['reference_usage'] = $value['reference_usage'];
        return $result;
    }

    public static function markdown(array $skill): string
    {
        $yamlString = static fn (string $text): string => json_encode($text, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return "---\nname: " . $yamlString($skill['name']) . "\ndescription: " . $yamlString($skill['description'])
            . "\n---\n\n" . $skill['instructions'] . "\n";
    }
}
