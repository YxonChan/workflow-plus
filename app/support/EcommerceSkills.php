<?php
declare(strict_types=1);

namespace app\support;

final class EcommerceSkills
{
    private const START = "【电商创作规范】\n";
    private const END = "\n【创作规范结束】\n\n";

    public static function apply(array $request, string $mode, string $kind, string $pageKey): array
    {
        if ($kind !== 'creative') return $request;
        $goal = $request['creative_goal'] ?? '';
        $design = $mode === 'image' ? EcommerceImageDesign::validate($request, $pageKey) : null;
        if ($mode === 'video') {
            $ids = ['builtin:product-video'];
            if ($request['images'] !== []) $ids[] = 'builtin:product-image-to-video';
            if (($request['video_style'] ?? 'showcase') === 'ugc') $ids[] = 'builtin:product-ugc-video';
            $ids[] = 'builtin:storyboard-video';
        } else {
            $ids = ['builtin:product-image'];
            $editing = ($request['original']['mode'] ?? '') === 'edit';
            if (!$editing) {
                if (in_array($pageKey, ['H2', 'H4', 'D3'], true)) $ids[] = 'builtin:product-material-image';
                elseif (in_array($pageKey, ['H3', 'D5', 'D6'], true) || $goal === 'tryon') $ids[] = 'builtin:product-lifestyle-image';
                elseif (str_starts_with($pageKey, 'D') || in_array($goal, ['detail', 'poster'], true) || ($goal === 'main' && !($design['whiteBackground'] ?? false))) $ids[] = 'builtin:product-detail-layout';
                else $ids[] = 'builtin:product-studio-image';
                if ($design && in_array($goal, ['hero', 'detail', 'full'], true) && $pageKey !== 'H1') $ids[] = 'builtin:product-detail-layout';
            }
        }
        $catalog = array_column(PromptSkillCatalog::builtins(), null, 'id');
        $rules = $design && ($request['original']['mode'] ?? '') !== 'edit' ? EcommerceImageDesign::rules($design, $pageKey) : [];
        $snapshot = [];
        foreach (array_unique($ids) as $id) {
            $skill = $catalog[$id] ?? null;
            if (!$skill || !in_array($mode, $skill['modes'], true) || empty($skill['generation_rules'])) {
                throw new \RuntimeException('商品创作规则配置不完整');
            }
            foreach ($skill['generation_rules'] as $rule) $rules[] = $rule;
            $snapshot[] = ['id' => $id, 'version' => $skill['version']];
        }
        $prompt = $request['prompt'];
        // Re-quotes and explicit retries rebuild the reserved block, without accumulating instructions.
        if (str_starts_with($prompt, self::START)) {
            $end = strpos($prompt, self::END, strlen(self::START));
            if ($end !== false) $prompt = substr($prompt, $end + strlen(self::END));
        }
        $request['prompt'] = self::START . implode("\n", array_unique($rules)) . self::END . $prompt;
        if (mb_strlen($request['prompt']) > ($mode === 'video' ? 10000 : 30000)) {
            abort(422, '商品资料与创作规则合计过长，请精简额外要求后重试');
        }
        $request['skill_snapshot'] = $snapshot;
        return $request;
    }
}
