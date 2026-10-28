<?php
declare(strict_types=1);

namespace app\support;

use InvalidArgumentException;

final class EcommerceImageDesign
{
    public static function catalog(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__) . '/resources/ecommerce_image_designs.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function validate(array $request, string $pageKey): ?array
    {
        $design = $request['original']['design'] ?? null;
        // Old completed results remain editable with their original frozen composition.
        if ($design === null) return null;
        if (!is_array($design) || !is_string($design['purpose'] ?? null) || !is_string($design['presetId'] ?? null)) {
            throw new InvalidArgumentException('图片用途与风格格式无效');
        }
        $catalog = self::catalog();
        $purpose = $design['purpose'];
        $style = $catalog['styles'][$design['presetId']] ?? null;
        if (!$style || !in_array($purpose, $style['purposes'], true) || $purpose !== $request['creative_goal']) {
            throw new InvalidArgumentException('图片风格与用途不匹配，请重新选择');
        }
        if (($style['enabled'] ?? true) === false && ($request['original']['mode'] ?? '') !== 'edit') {
            throw new InvalidArgumentException(($style['retired'] ?? false) ? '图片模板已下架，请重新选择' : '图片模板尚未就绪，请选择其他模板');
        }
        $definition = array_column($catalog['purposes'], null, 'id')[$purpose];
        // Poster copy is rendered by the application after the model returns the
        // clean product image. Validate the frozen snapshot here so malformed or
        // unexpectedly large user-provided claims cannot enter a queued job.
        self::validatePosterSnapshot($request['original']['poster'] ?? null);
        $campaign = $request['original']['campaign'] ?? null;
        $layout = $design['layout'] ?? '';
        if (!in_array($layout, ['', 'template'], true) || ($layout === 'template' && $purpose !== 'detail')) {
            throw new InvalidArgumentException('图片模板类型无效');
        }
        if ($layout === 'template') {
            if ($pageKey !== '' || $campaign !== null || ($request['original']['n'] ?? 1) !== 1 || isset($request['original']['poster'])) {
                throw new InvalidArgumentException('详情模板只生成一张完整图片，不能拆成套图或覆盖通用海报排版');
            }
            $size = (string) ($request['original']['size'] ?? '');
            if (!preg_match('/^(\d+)x(\d+)$/', $size, $dimensions) || (int) $dimensions[2] <= (int) $dimensions[1]) {
                throw new InvalidArgumentException('完整详情模板必须使用竖版画布');
            }
        } elseif ($definition['pageIds'] !== []) {
            if (!in_array($pageKey, $definition['pageIds'], true) || !is_array($campaign)
                || ($campaign['shotId'] ?? '') !== $pageKey || ($campaign['presetId'] ?? '') !== $design['presetId']) {
                throw new InvalidArgumentException('图片页面与所选用途不匹配');
            }
        } elseif ($pageKey !== '' || $campaign !== null) {
            throw new InvalidArgumentException('单张图片不能使用套图页面');
        }
        $reference = $request['original']['styleReference'] ?? '';
        if ($reference !== '' && $reference !== '/admin/ecommerce-box/styles/' . $design['presetId'] . '.jpg') {
            throw new InvalidArgumentException('版式参考与所选风格不匹配');
        }
        if ($layout === 'template' && ($reference === '' || !in_array($reference, $request['images'], true))) {
            throw new InvalidArgumentException('详情图生成缺少所选模板参考');
        }
        return ['purpose' => $purpose, 'presetId' => $design['presetId'], 'layout' => $layout, 'whiteBackground' => (bool) ($style['whiteBackground'] ?? false)];
    }

    private static function validatePosterSnapshot($poster): void
    {
        if ($poster === null) return;
        if (!is_array($poster)) throw new InvalidArgumentException('宣传海报文案格式无效');
        $text = static function ($value, int $limit, bool $required = false): void {
            if (!is_string($value) || mb_strlen($value) > $limit || ($required && trim($value) === '')) {
                throw new InvalidArgumentException('宣传海报文案格式无效');
            }
        };
        $text($poster['headline'] ?? '', 20);
        $text($poster['subtitle'] ?? '', 40);
        $points = $poster['sellingPoints'] ?? [];
        if (!is_array($points) || !array_is_list($points) || count($points) > 3) {
            throw new InvalidArgumentException('宣传海报卖点格式无效');
        }
        foreach ($points as $point) $text($point, 16, true);
        if (!isset($poster['facts'])) return; // Legacy snapshots predate structured facts.
        $facts = $poster['facts'];
        if (!is_array($facts) || array_is_list($facts)) {
            throw new InvalidArgumentException('宣传海报商品信息格式无效');
        }
        foreach ([
            'price' => 20, 'originalPrice' => 20, 'discount' => 16,
            'certification' => 24, 'sales' => 24, 'cta' => 16,
        ] as $key => $limit) {
            if (array_key_exists($key, $facts)) $text($facts[$key], $limit);
        }
    }

    public static function rules(array $design, string $pageKey): array
    {
        $rules = self::purposeRules($design, $pageKey);
        $style = self::catalog()['styles'][$design['presetId']] ?? [];
        // Only the server-maintained catalog can contribute template-specific methods.
        if (!(($design['purpose'] === 'main' && $design['whiteBackground']) || $pageKey === 'H1')) {
            foreach ($style['generationRules'] ?? [] as $rule) $rules[] = $rule;
        }
        return $rules;
    }

    private static function purposeRules(array $design, string $pageKey): array
    {
        if (($design['layout'] ?? '') === 'template') {
            return ['当前任务只输出一张完整商品详情图：所选模板的主视觉、细节、场景和文字模块在同一张竖版画布内排版，参考其整体结构、区块比例和视觉层级。不得拆成独立分页、套图任务或只生成其中一块商品照片；文字仅采用已经提供且允许使用的文案，缺少事实时用商品图像与留白完成对应区域。'];
        }
        if (($design['purpose'] === 'main' && $design['whiteBackground']) || $pageKey === 'H1') {
            return ['当前图片为纯白背景商品首图：只展示完整商品，不绘制人物、场景、营销文案、图标、色块或套图缩略图；此用途规则优先于参考模板。'];
        }
        return [match ($design['purpose']) {
            'main' => '当前用途为一张方形商品主图，以商品整体与一个清晰视觉重点完成当前模板，不生成套图总览或连续详情模块。',
            'tryon' => '当前用途为模特试穿成品，人物须自然穿戴目标商品。风格只调整摄影环境、光线与姿态，不将试穿改成独立商品白底照、详情拼版或人物设定图。',
            'poster' => '当前用途为独立商品宣传海报，以一个商品视觉焦点和清晰文案层级完成整张海报，不拼成多页套图。',
            default => '当前用途为套图中的独立一页，严格执行本页分工；全套统一配色、字级、光线与商品身份，但不同页面采用对应的主图、卖点、细节、规格或使用场景构图，不重复同一张白底商品照。',
        }];
    }
}
