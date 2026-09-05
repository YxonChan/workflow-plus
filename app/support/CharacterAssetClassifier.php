<?php

declare(strict_types=1);

namespace app\support;

use app\model\Asset;

class CharacterAssetClassifier
{
    /**
     * @param array<string, mixed>|Asset $asset
     */
    public static function isNonHumanoid(array|Asset $asset): bool
    {
        $tags = self::tags($asset);

        foreach (['humanoid', 'humanoid_character', 'character_look_allowed', 'anthropomorphic', '拟人', '类人', '人形'] as $tag) {
            if (in_array($tag, $tags, true)) {
                return false;
            }
        }

        foreach (['non_humanoid', 'non_humanoid_character', 'animal_character', 'creature_character', '兽形', '动物角色', '生物角色'] as $tag) {
            if (in_array($tag, $tags, true)) {
                return true;
            }
        }

        $haystack = self::haystack($asset);
        if ($haystack === '') {
            return false;
        }

        $creatureHits = self::containsAny($haystack, self::creatureKeywords());
        if ($creatureHits === 0) {
            return false;
        }

        if (self::containsAny($haystack, self::humanoidOverrideKeywords()) > 0) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed>|Asset $asset
     * @return array<int, string>
     */
    public static function supplementalTags(array|Asset $asset): array
    {
        if (!self::isNonHumanoid($asset)) {
            return [];
        }

        return ['non_humanoid_character'];
    }

    /**
     * @param array<string, mixed>|Asset $asset
     */
    public static function shouldAutoGenerateLooks(array|Asset $asset): bool
    {
        return !self::isNonHumanoid($asset);
    }

    /**
     * @param array<string, mixed>|Asset $asset
     */
    private static function haystack(array|Asset $asset): string
    {
        $name = $asset instanceof Asset ? (string) $asset->getAttr('name') : (string) ($asset['name'] ?? '');
        $description = $asset instanceof Asset ? (string) $asset->getAttr('description') : (string) ($asset['description'] ?? '');
        $imagePrompt = $asset instanceof Asset ? (string) ($asset->getAttr('image_prompt') ?? '') : (string) ($asset['image_prompt'] ?? '');
        $tags = implode(' ', self::tags($asset));

        return mb_strtolower(trim(implode(' ', array_filter([$name, $description, $imagePrompt, $tags]))));
    }

    /**
     * @param array<string, mixed>|Asset $asset
     * @return array<int, string>
     */
    private static function tags(array|Asset $asset): array
    {
        $raw = $asset instanceof Asset ? ($asset->getAttr('tags') ?: []) : ($asset['tags'] ?? []);
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($tag): string => mb_strtolower(trim((string) $tag)),
            $raw
        ), static fn (string $tag): bool => $tag !== ''));
    }

    /**
     * @param array<int, string> $keywords
     */
    private static function containsAny(string $haystack, array $keywords): int
    {
        $hits = 0;
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && str_contains($haystack, mb_strtolower($keyword))) {
                $hits++;
            }
        }

        return $hits;
    }

    /**
     * @return array<int, string>
     */
    private static function creatureKeywords(): array
    {
        return [
            '动物', '生物', '宠物', '怪物', '怪兽', '异兽', '魔兽', '灵兽', '妖兽', '坐骑',
            '四足', '兽形', '野兽', '猛兽', '飞禽', '走兽',
            '猫咪', '小猫', '黑猫', '白猫', '狸猫', '橘猫',
            '狗狗', '小狗', '猎犬', '牧羊犬', '柴犬', '金毛',
            '狐狸', '白狐', '狼', '雪狼', '灰狼', '狼犬',
            '老虎', '狮子', '豹子', '熊猫', '黑熊', '棕熊', '兔子', '梅花鹿',
            '乌鸦', '鹦鹉', '猫头鹰', '鹰', '孔雀', '鸟类',
            '海豚', '鲸鱼', '鲨鱼', '金鱼', '鲤鱼', '章鱼',
            '蟒蛇', '毒蛇', '蜥蜴', '鳄鱼', '恐龙',
            '龙族', '巨龙', '幼龙', '飞龙',
            '史莱姆', '哥布林', '精灵兽', '异形',
            'animal', 'creature', 'monster', 'beast', 'pet', 'wolf', 'fox', 'cat', 'dog', 'dragon', 'slime',
            'bird', 'owl', 'crow', 'eagle', 'bear', 'tiger', 'lion', 'deer', 'dolphin', 'shark',
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function humanoidOverrideKeywords(): array
    {
        return [
            '拟人', '类人', '人形', '半兽人', '兽人', '人鱼', '精灵少女', '精灵王子',
            'anthropomorphic', 'humanoid', 'human-like',
            '穿着', '制服', '礼服', '外套', '西装', '长裙', '盔甲',
        ];
    }
}
