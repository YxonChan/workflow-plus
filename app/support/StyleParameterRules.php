<?php

declare(strict_types=1);

namespace app\support;

use InvalidArgumentException;

class StyleParameterRules
{
    public static function catalog(): array
    {
        static $catalog;
        return $catalog ??= json_decode(
            file_get_contents(dirname(__DIR__, 2) . '/frontend/src/utils/styleParameterRules.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    public static function normalize(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        $groups = self::catalog()['groups'];
        $result = array_fill_keys(array_column($groups, 'key'), []);
        foreach (is_array($value) ? $value : [] as $key => $raw) {
            $items = is_string($raw) ? preg_split('/[,，、]/u', $raw) : $raw;
            foreach (is_array($items) ? $items : [] as $item) {
                if (!is_string($item) || trim($item) === '') {
                    continue;
                }
                $text = trim($item);
                if ($key === 'medium' && $text === '35mm Anamorphic') {
                    foreach (['medium' => 'Film', 'format' => '35mm film', 'lens' => 'Anamorphic 2x Squeeze'] as $target => $entry) {
                        self::append($result, $target, $entry);
                    }
                    continue;
                }
                $text = self::catalog()['aliases'][$text] ?? $text;
                $target = $key;
                $matches = array_filter($groups, static fn (array $group): bool => in_array($text, array_column($group['options'], 'value'), true));
                if ($matches !== []) {
                    $target = reset($matches)['key'];
                }
                foreach ($matches as $group) {
                    if ($group['key'] === $key) {
                        $target = $key;
                        break;
                    }
                }
                $target = self::catalog()['legacyTargets'][$key][$text] ?? $target;
                self::append($result, (string) $target, $text);
                if ($key === 'medium' && in_array($target, ['film_stock', 'format'], true)) {
                    self::append($result, 'medium', 'Film');
                }
            }
        }
        return $result;
    }

    private static function append(array &$parameters, string $key, string $value): void
    {
        $parameters[$key] ??= [];
        if (!in_array($value, $parameters[$key], true)) {
            $parameters[$key][] = $value;
        }
    }

    public static function applies(array $group, string $style): bool
    {
        return !isset($group['styles']) || in_array($style, $group['styles'], true);
    }

    public static function options(array $group, string $style): array
    {
        return array_values(array_filter($group['options'], static fn (array $option): bool => self::applies($option, $style)));
    }

    public static function validate(array $parameters, string $style): void
    {
        $groups = array_column(self::catalog()['groups'], null, 'key');
        foreach ($parameters as $key => $values) {
            if ($values === []) {
                continue;
            }
            $group = $groups[$key] ?? null;
            if ($group === null) {
                throw new InvalidArgumentException('存在旧版或自定义参数，请先处理：' . $key);
            }
            $label = $group['label'];
            if (!self::applies($group, $style)) {
                throw new InvalidArgumentException("{$label}不适用于当前画风");
            }
            if (count($values) > $group['max']) {
                throw new InvalidArgumentException("{$label}最多选择{$group['max']}项");
            }
            if (array_diff($values, array_column(self::options($group, $style), 'value')) !== []) {
                throw new InvalidArgumentException("{$label}包含不适用或旧版选项，请重新选择");
            }
            $requires = $group['requires'] ?? null;
            if ($requires !== null && !in_array($requires['value'], $parameters[$requires['key']] ?? [], true)) {
                throw new InvalidArgumentException("{$label}的前置选项未满足");
            }
        }
        foreach (self::catalog()['hardConflicts'] as [$left, $right]) {
            if (in_array($left['value'], $parameters[$left['key']] ?? [], true)
                && in_array($right['value'], $parameters[$right['key']] ?? [], true)) {
                throw new InvalidArgumentException($groups[$left['key']]['label'] . '与' . $groups[$right['key']]['label'] . '存在冲突');
            }
        }
    }

    public static function compose(array $parameters, string $style, string $scope = 'tone'): string
    {
        self::validate($parameters, $style);
        $parts = [];
        foreach (self::catalog()['groups'] as $group) {
            if (!self::applies($group, $style) || ($group['scope'] ?? 'tone') !== $scope) {
                continue;
            }
            $values = $parameters[$group['key']] ?? [];
            if ($values !== []) {
                $parts[] = $group['prefix'] . implode('、', $values);
            }
        }
        if ($parts !== [] && $scope === 'tone') {
            $parts[] = '具体光源、时段、焦距、构图与运镜按场景和分镜决定';
        }
        return $parts === [] ? '' : implode('。', $parts) . '。';
    }
}
