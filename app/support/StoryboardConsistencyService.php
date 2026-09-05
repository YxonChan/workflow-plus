<?php

declare(strict_types=1);

namespace app\support;

use app\model\Asset;
use app\model\Episode;
use app\model\Series;
use app\model\Shot;

/**
 * 分镜与人物资产的文本级视觉一致性检查。
 *
 * 首版只比较资产描述/生图提示词与当前分镜文本中的明确属性，不推断实际图片内容。
 * 规则保持保守：没有明确写出的属性不报错，同一属性明确矛盾才返回 error。
 */
class StoryboardConsistencyService
{
    /** @var array<string, string> */
    private const FIELD_LABELS = [
        'hair_color' => '发色',
        'hair_length' => '头发长度',
        'hair_texture' => '发质',
        'eye_color' => '瞳色',
        'age_stage' => '年龄阶段',
        'eyewear' => '眼镜',
        'facial_hair' => '胡须',
    ];

    /**
     * @return array<string, mixed>
     */
    public function validate(int $userId, int $seriesId, int $episodeId = 0): array
    {
        $series = Series::where('id', $seriesId)->where('user_id', $userId)->find();
        if (!$series instanceof Series) {
            return ['error' => '剧本不存在'];
        }

        $assetRows = Asset::with(['images'])
            ->where('user_id', $userId)
            ->where('series_id', $seriesId)
            ->where('type', 'character')
            ->where('is_hidden', 0)
            ->order(['sort' => 'asc', 'id' => 'asc'])
            ->select();

        $characters = [];
        foreach ($assetRows as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }
            $sourceParts = [trim((string) $asset->getAttr('description'))];
            foreach ($asset->images ?? [] as $image) {
                $prompt = trim((string) $image->getAttr('image_prompt'));
                if ($prompt !== '') {
                    $sourceParts[] = $prompt;
                }
            }
            $characters[] = [
                'asset' => $asset,
                'traits' => $this->extractTraits(implode("\n", array_filter($sourceParts))),
            ];
        }

        $episodeQuery = Episode::where('user_id', $userId)
            ->where('series_id', $seriesId)
            ->order(['number' => 'asc', 'id' => 'asc']);
        if ($episodeId > 0) {
            $episodeQuery->where('id', $episodeId);
        }

        $issues = [];
        $checkedShots = 0;
        $checkedMentions = 0;
        $episodes = [];
        foreach ($episodeQuery->select() as $episode) {
            if (!$episode instanceof Episode) {
                continue;
            }
            $revisionId = (int) ($episode->getAttr('current_storyboard_revision_id') ?? 0);
            if ($revisionId <= 0) {
                $episodes[] = [
                    'episode_id' => (int) $episode->getAttr('id'),
                    'number' => (int) $episode->getAttr('number'),
                    'title' => (string) $episode->getAttr('title'),
                    'shot_count' => 0,
                    'note' => '还没有当前分镜版本',
                ];
                continue;
            }

            $shots = Shot::where('user_id', $userId)
                ->where('episode_id', (int) $episode->getAttr('id'))
                ->where('storyboard_revision_id', $revisionId)
                ->order(['index' => 'asc', 'id' => 'asc'])
                ->select();
            $episodeShotCount = 0;
            foreach ($shots as $shot) {
                if (!$shot instanceof Shot) {
                    continue;
                }
                $episodeShotCount++;
                $checkedShots++;
                $description = trim((string) $shot->getAttr('desc'));
                if ($description === '') {
                    continue;
                }

                foreach ($characters as $character) {
                    /** @var Asset $asset */
                    $asset = $character['asset'];
                    $assetName = trim((string) $asset->getAttr('name'));
                    if ($assetName === '' || !$this->mentionsSubject($description, $assetName)) {
                        continue;
                    }
                    $checkedMentions++;
                    $expectedTraits = is_array($character['traits']) ? $character['traits'] : [];
                    $actualTraits = $this->extractTraits($description, $assetName);
                    foreach ($this->compareTraits($expectedTraits, $actualTraits) as $difference) {
                        $fieldLabel = (string) ($difference['field_label'] ?? '外观属性');
                        $expected = (string) ($difference['expected'] ?? '');
                        $actual = (string) ($difference['actual'] ?? '');
                        $issues[] = [
                            'severity' => (string) ($difference['severity'] ?? 'error'),
                            'episode_id' => (int) $episode->getAttr('id'),
                            'episode_number' => (int) $episode->getAttr('number'),
                            'episode_title' => (string) $episode->getAttr('title'),
                            'storyboard_revision_id' => $revisionId,
                            'shot_id' => (int) $shot->getAttr('id'),
                            'shot_index' => (int) $shot->getAttr('index'),
                            'asset_id' => (int) $asset->getAttr('id'),
                            'asset_name' => $assetName,
                            ...$difference,
                            'suggestion' => '若人物资产设定为准，将第' . (int) $shot->getAttr('index') . "镜中「{$assetName}」的{$fieldLabel}从{$actual}改为{$expected}；若这是剧情中的临时造型变化，应先建立对应人物造型资产，再保留当前分镜描述。",
                            'suggested_instruction' => "仅修改人物「{$assetName}」的{$fieldLabel}描述：将{$actual}改为{$expected}，其他人物、动作、场景和镜头语言保持不变。",
                        ];
                    }
                }
            }

            $episodes[] = [
                'episode_id' => (int) $episode->getAttr('id'),
                'number' => (int) $episode->getAttr('number'),
                'title' => (string) $episode->getAttr('title'),
                'storyboard_revision_id' => $revisionId,
                'shot_count' => $episodeShotCount,
            ];
        }

        if ($episodeId > 0 && $episodes === []) {
            return ['error' => '剧集不存在或不属于该剧本'];
        }

        $errorCount = count(array_filter($issues, static fn (array $issue): bool => ($issue['severity'] ?? '') === 'error'));
        $warningCount = count($issues) - $errorCount;

        return [
            'series_id' => $seriesId,
            'series_title' => (string) $series->getAttr('title'),
            'mode' => 'text',
            'checked_characters' => count($characters),
            'checked_episodes' => count($episodes),
            'checked_shots' => $checkedShots,
            'checked_character_mentions' => $checkedMentions,
            'issue_count' => count($issues),
            'error_count' => $errorCount,
            'warning_count' => $warningCount,
            'issues' => array_slice($issues, 0, 20),
            'episodes' => $episodes,
            'checked_fields' => array_values(self::FIELD_LABELS),
            'limits' => '当前仅核对资产描述、资产生图提示词和分镜文本中明确写出的发色、头发长度、发质、瞳色、年龄阶段、眼镜和胡须；尚未识别实际图片像素，也不会对未明确写出的属性作推断。',
        ];
    }

    /**
     * 提取文本中明确出现的视觉属性。
     *
     * @return array<string, array{value: string, label: string, evidence: string}>
     */
    public function extractTraits(string $text, string $subject = ''): array
    {
        $scope = $subject !== '' ? $this->subjectScope($text, $subject) : $text;
        if (trim($scope) === '') {
            return [];
        }

        $definitions = [
            'hair_color' => [
                'black' => ['黑色', '/(?:黑(?:色)?(?:长|短|卷|直)?发|头发[^。！？\n]{0,12}(?:黑色|黑)|black(?:[-\s](?:haired|hair))|hair[^.!?\n]{0,18}black)/iu'],
                'red' => ['红色', '/(?:红(?:色)?(?:长|短|卷|直)?发|头发[^。！？\n]{0,12}(?:红色|红)|red(?:[-\s](?:haired|hair))|hair[^.!?\n]{0,18}red)/iu'],
                'brown' => ['棕色', '/(?:棕(?:色)?(?:长|短|卷|直)?发|褐(?:色)?(?:长|短|卷|直)?发|头发[^。！？\n]{0,12}(?:棕色|褐色)|brown(?:[-\s](?:haired|hair))|brunette|hair[^.!?\n]{0,18}brown)/iu'],
                'blonde' => ['金色', '/(?:金(?:色|黄)?(?:长|短|卷|直)?发|头发[^。！？\n]{0,12}(?:金色|金黄)|blond(?:e)?(?:[-\s](?:haired|hair))|golden[-\s]blond(?:e)?\s+hair|hair[^.!?\n]{0,18}(?:blond(?:e)?|golden))/iu'],
                'white' => ['白色', '/(?:白(?:色)?(?:长|短|卷|直)?发|头发[^。！？\n]{0,12}白色|white(?:[-\s](?:haired|hair))|hair[^.!?\n]{0,18}white)/iu'],
                'silver' => ['银色', '/(?:银(?:色|白)?(?:长|短|卷|直)?发|头发[^。！？\n]{0,12}(?:银色|银白)|silver(?:[-\s](?:haired|hair))|hair[^.!?\n]{0,18}silver)/iu'],
                'gray' => ['灰色', '/(?:灰(?:色)?(?:长|短|卷|直)?发|头发[^。！？\n]{0,12}灰色|gr[ae]y(?:[-\s](?:haired|hair))|hair[^.!?\n]{0,18}gr[ae]y)/iu'],
                'blue' => ['蓝色', '/(?:蓝(?:色)?(?:长|短|卷|直)?发|头发[^。！？\n]{0,12}蓝色|blue(?:[-\s](?:haired|hair))|hair[^.!?\n]{0,18}blue)/iu'],
                'purple' => ['紫色', '/(?:紫(?:色)?(?:长|短|卷|直)?发|头发[^。！？\n]{0,12}紫色|purple(?:[-\s](?:haired|hair))|hair[^.!?\n]{0,18}purple)/iu'],
            ],
            'hair_length' => [
                'long' => ['长发', '/(?:长发|长直发|长卷发|long(?:[-\s](?:haired|hair))|long[^.!?\n]{0,12}hair)/iu'],
                'short' => ['短发', '/(?:短发|短直发|短卷发|short(?:[-\s](?:haired|hair))|short[^.!?\n]{0,12}hair)/iu'],
            ],
            'hair_texture' => [
                'straight' => ['直发', '/(?:直(?:长|短)?发|一头直发|头发[^。！？\n]{0,10}(?:笔直|顺直)|straight(?:[-\s](?:haired|hair))|straight[^.!?\n]{0,12}hair)/iu'],
                'curly' => ['卷发', '/(?:卷(?:曲)?(?:长|短)?发|波浪(?:长|短)?发|头发[^。！？\n]{0,10}(?:卷曲|波浪)|curly(?:[-\s](?:haired|hair))|wavy(?:[-\s](?:haired|hair))|(?:curly|wavy)[^.!?\n]{0,12}hair)/iu'],
            ],
            'eye_color' => [
                'black' => ['黑色', '/(?:黑(?:色)?(?:眼睛|眼眸|瞳孔)|(?:眼睛|眼眸|瞳孔)[^。！？\n]{0,10}黑|black\s+eyes?|eyes?[^.!?\n]{0,14}black)/iu'],
                'brown' => ['棕色', '/(?:棕(?:色)?(?:眼睛|眼眸|瞳孔)|褐(?:色)?(?:眼睛|眼眸|瞳孔)|(?:眼睛|眼眸|瞳孔)[^。！？\n]{0,10}(?:棕|褐)|brown\s+eyes?|eyes?[^.!?\n]{0,14}brown)/iu'],
                'blue' => ['蓝色', '/(?:蓝(?:色)?(?:眼睛|眼眸|瞳孔)|(?:眼睛|眼眸|瞳孔)[^。！？\n]{0,10}蓝|blue\s+eyes?|eyes?[^.!?\n]{0,14}blue)/iu'],
                'green' => ['绿色', '/(?:绿(?:色)?(?:眼睛|眼眸|瞳孔)|(?:眼睛|眼眸|瞳孔)[^。！？\n]{0,10}绿|green\s+eyes?|eyes?[^.!?\n]{0,14}green)/iu'],
                'gray' => ['灰色', '/(?:灰(?:色)?(?:眼睛|眼眸|瞳孔)|(?:眼睛|眼眸|瞳孔)[^。！？\n]{0,10}灰|gr[ae]y\s+eyes?|eyes?[^.!?\n]{0,14}gr[ae]y)/iu'],
                'red' => ['红色', '/(?:红(?:色)?(?:眼睛|眼眸|瞳孔)|(?:眼睛|眼眸|瞳孔)[^。！？\n]{0,10}红|red\s+eyes?|eyes?[^.!?\n]{0,14}red)/iu'],
            ],
            'age_stage' => [
                'child' => ['儿童', '/(?:儿童|小男孩|小女孩|年幼男孩|年幼女孩|child|young\s+(?:boy|girl)|little\s+(?:boy|girl))/iu'],
                'teen' => ['青少年', '/(?:青少年|少年|少女|十几岁|teenager|teenage|adolescent)/iu'],
                'adult' => ['成年人', '/(?:成年人|成年男子|成年男人|成年女性|成年女人|adult\s+(?:man|woman|male|female)|young\s+(?:man|woman))/iu'],
                'elderly' => ['老年人', '/(?:老年人|老人|老者|年迈|elderly|old\s+(?:man|woman)|aged\s+(?:man|woman))/iu'],
            ],
            'eyewear' => [
                'glasses' => ['佩戴眼镜', '/(?:(?:戴|佩戴)着?[^。！？\n]{0,8}眼镜|(?:黑框|金丝|圆框|无框)眼镜|wearing\s+(?:eye)?glasses|with\s+(?:eye)?glasses|spectacles)/iu'],
                'no_glasses' => ['未戴眼镜', '/(?:未戴眼镜|不戴眼镜|没有戴眼镜|without\s+(?:eye)?glasses|no\s+(?:eye)?glasses)/iu'],
            ],
            'facial_hair' => [
                'beard' => ['有胡须', '/(?:络腮胡|八字胡|山羊胡|留着?[^。！？\n]{0,6}(?:胡须|胡子)|full\s+beard|bearded|moustache|mustache|goatee)/iu'],
                'clean_shaven' => ['无胡须', '/(?:无胡须|没有胡须|不留胡子|下巴光洁|clean[-\s]shaven|without\s+(?:a\s+)?beard|no\s+beard)/iu'],
            ],
        ];

        $traits = [];
        foreach ($definitions as $field => $values) {
            $best = null;
            $bestOffset = null;
            foreach ($values as $value => [$label, $pattern]) {
                if (preg_match($pattern, $scope, $match, PREG_OFFSET_CAPTURE) === 1) {
                    $offset = (int) ($match[0][1] ?? PHP_INT_MAX);
                    if ($bestOffset !== null && $offset >= $bestOffset) {
                        continue;
                    }
                    $bestOffset = $offset;
                    $best = [
                        'value' => $value,
                        'label' => $label,
                        'evidence' => trim((string) ($match[0][0] ?? '')),
                    ];
                }
            }
            if (is_array($best)) {
                $traits[$field] = $best;
            }
        }

        return $traits;
    }

    /**
     * @param array<string, array{value: string, label: string, evidence: string}> $expected
     * @param array<string, array{value: string, label: string, evidence: string}> $actual
     * @return array<int, array<string, string>>
     */
    public function compareTraits(array $expected, array $actual): array
    {
        $differences = [];
        foreach ($expected as $field => $expectedTrait) {
            $actualTrait = $actual[$field] ?? null;
            if (!is_array($actualTrait) || ($actualTrait['value'] ?? '') === ($expectedTrait['value'] ?? '')) {
                continue;
            }
            $differences[] = [
                'severity' => in_array($field, ['hair_texture', 'eyewear', 'facial_hair'], true) ? 'warning' : 'error',
                'field' => $field,
                'field_label' => self::FIELD_LABELS[$field] ?? $field,
                'expected' => (string) ($expectedTrait['label'] ?? $expectedTrait['value'] ?? ''),
                'actual' => (string) ($actualTrait['label'] ?? $actualTrait['value'] ?? ''),
                'asset_evidence' => (string) ($expectedTrait['evidence'] ?? ''),
                'storyboard_evidence' => (string) ($actualTrait['evidence'] ?? ''),
                'message' => (self::FIELD_LABELS[$field] ?? $field)
                    . '冲突：资产为' . (string) ($expectedTrait['label'] ?? '')
                    . '，分镜写为' . (string) ($actualTrait['label'] ?? ''),
            ];
        }

        return $differences;
    }

    private function mentionsSubject(string $text, string $subject): bool
    {
        return mb_stripos($text, $subject) !== false;
    }

    private function subjectScope(string $text, string $subject): string
    {
        if (!$this->mentionsSubject($text, $subject)) {
            return '';
        }

        $segments = [];
        $lines = preg_split('/\R/u', $text) ?: [$text];
        foreach ($lines as $line) {
            $line = (string) $line;
            $offset = 0;
            $subjectLength = max(1, mb_strlen($subject));
            while (($position = mb_stripos($line, $subject, $offset)) !== false) {
                // 只观察名称附近，避免同一长句里其他人物的属性串到当前人物。
                $segments[] = mb_substr($line, $position, 220);
                $offset = $position + $subjectLength;
            }
        }

        return implode("\n", array_values(array_unique(array_filter(array_map('trim', $segments)))));
    }
}
