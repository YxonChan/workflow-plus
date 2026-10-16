<?php

declare(strict_types=1);

namespace app\support;

use app\model\StylePreset;

class StylePresetDefaults
{
    public static function parameterKeys(): array
    {
        return array_column(StyleParameterRules::catalog()['groups'], 'key');
    }

    public static function official(): array
    {
        return [
            self::row('short_drama', '短剧写实', '偏竖屏短剧、人物关系和表演感。', ['realistic'], 10,
                '短剧写实，强调人物关系、清晰表演、现实生活场景和适合短剧平台的直接叙事。',
                '数字电影机拍摄的当代短剧质感，肤色自然，少用夸张调色。整体色调以生活中的暖中性色为主，室内钨丝灯和窗外日光并存。对比度中等，暗部保留细节，高光不溢出。光线以窗边自然光、室内实用光和柔和补光为主，人物面部清楚可读。颗粒很轻。氛围直接、贴近生活、情绪清楚。镜头以 35mm 和 50mm 为主，偶尔用 85mm 拍对视，运镜平稳，方便竖屏看清表情。',
                ['medium' => ['ARRI Alexa Digital'], 'palette' => ['Golden Hour Warm'], 'contrast' => ['Medium Contrast Standard'], 'lighting' => ['Window Light Side', 'Tungsten Warm Indoor'], 'grain' => ['Fine Grain 35mm'], 'mood' => ['intimate', 'direct'], 'lens' => ['35mm Reportage', '50mm Standard Normal', '85mm Portrait Compression'], 'era' => ['2020s Streaming Series Look']]),
            self::row('cinematic', '电影感写实', '更像影视剧剧照，适合短剧首帧。', ['realistic'], 20,
                '电影感写实，使用影视剧剧照质感、自然表演、真实镜头景深和克制调色。',
                '35mm 电影摄影质感，景深真实，主体和背景分离清楚。整体色调克制，按场景情绪选择暖调或冷调，不随意加饱和。对比度用电影 S 曲线，黑位有层次，高光柔和滚落。光线必须来自场景里的窗、灯或火，不打平的棚拍光。细颗粒，保留皮肤和布料纹理。氛围沉稳、电影感。镜头以 35mm、50mm、85mm 交替，运镜有动机。',
                ['medium' => ['35mm film'], 'palette' => ['Teal & Orange'], 'contrast' => ['Filmic S-Curve'], 'lighting' => ['Window Light Side'], 'grain' => ['Fine Grain 35mm'], 'mood' => ['cinematic'], 'lens' => ['35mm Reportage', '50mm Standard Normal', '85mm Portrait Compression'], 'era' => ['2020s Streaming Series Look']]),
            self::row('documentary', '纪实摄影', '自然光、生活流、少修饰。', ['realistic'], 30,
                '纪实摄影，自然光、生活流、轻修饰、现场感强。',
                '纪实摄影质感，像跟拍下来的生活现场。色调接近现场本来的颜色，不额外美化。对比度偏低到中等，保留环境细节。光线只用现场的自然光和实用光，不补明显的轮廓光。轻微颗粒，接受一点不完美。氛围真实、随意、现场感强。镜头偏 28mm 和 35mm，带轻微手持。',
                ['medium' => ['35mm film'], 'palette' => ['Documentary Natural'], 'contrast' => ['Low Contrast Soft'], 'lighting' => ['Soft Diffused Daylight'], 'grain' => ['Fine Grain 35mm'], 'mood' => ['observational'], 'lens' => ['28mm Documentary', 'Handheld Documentary'], 'era' => ['Documentary Natural']]),
            self::row('commercial', '商业广告片', '干净、高级、产品广告质感。', ['realistic'], 40,
                '商业广告片，画面干净高级，布光精致，材质和人物状态更精致。',
                '商业广告片质感，画面干净、材质清楚、人物状态精致。色调统一，主色明确，点缀色克制。对比度中高，高光干净，暗部不脏。光线精致，轮廓光和柔光箱感可以存在，但要像高级广告而不是证件照。颗粒几乎没有。氛围高级、清爽、有产品感。镜头干净稳定，浅景深突出主体。',
                ['medium' => ['ARRI Alexa Digital'], 'palette' => ['Clean Commercial'], 'contrast' => ['Medium Contrast Standard'], 'lighting' => ['Soft Beauty Light'], 'grain' => [], 'mood' => ['polished', 'premium'], 'lens' => ['50mm Standard Normal', '85mm Portrait Compression'], 'era' => ['2020s Streaming Series Look']]),
            self::row('noir', '暗调悬疑', '低调光、强反差、悬疑氛围。', ['realistic'], 50,
                '暗调悬疑，低调光、高反差、阴影层次、紧张神秘氛围。',
                '暗调悬疑质感。色调偏冷，阴影里带一点青或墨绿，光源处留暖色。对比度高，黑位下沉但阴影里仍有层次。光线是低调光，大面积阴影，少量方向性光源切出脸和轮廓。中等颗粒。氛围紧张、神秘、压抑。镜头偏长焦压缩和稳定机位，关键处用手持增加不安。',
                ['medium' => ['Kodak Vision3 500T 5219'], 'palette' => ['Cold Blue Night'], 'contrast' => ['High Contrast', 'Crushed Blacks'], 'lighting' => ['Low Key'], 'grain' => ['Medium Film Grain'], 'mood' => ['tense', 'mysterious'], 'lens' => ['85mm Portrait Compression'], 'era' => ['Noir']]),
            self::row('period_comedy_honey', '古装甜宠轻喜剧', '蜂蜜暖光、粉彩和胶片人像肤色，适合明亮古装、甜宠和美食戏。', ['realistic'], 60,
                '蜂蜜暖光、粉彩和胶片人像肤色，适合明亮古装、甜宠和美食戏。',
                '35mm 变形宽银幕胶片电影质感，Kodak Vision3 250D 5207，肤色柔和自然。主色调为 Honey Glow 蜂蜜暖调，辅以柔和朱红、暖橙、奶油黄、玉石绿与浅青色点缀，形成古装甜宠轻喜剧的明亮质感。中对比与电影 S 曲线，黑位轻微抬起，暗部柔和，高光自然过渡。布光柔和，具体日光、室内光或烛光随场景决定。细颗粒，轻微橙色光晕，柔化皮肤边缘。氛围轻快、甜蜜、温暖。光学风格统一为变形宽银幕，焦距、构图和运镜按分镜决定。',
                ['medium' => ['35mm Anamorphic', 'Kodak Vision3 250D 5207'], 'palette' => ['Honey Glow'], 'contrast' => ['Medium Contrast Standard', 'Filmic S-Curve', 'Lifted Blacks', 'Rolled-off Highlights'], 'lighting' => ['Soft Diffused Lighting'], 'grain' => ['Fine Grain 35mm', 'Halation Orange', 'Black Pro-Mist'], 'mood' => ['playful', 'romantic', 'warm'], 'lens' => ['Anamorphic 2x Squeeze'], 'era' => ['Wes Anderson aesthetic']]),
            self::row('rainy_city_night', '雨夜都市冷调', '冷蓝夜色里压着一点暖黄，适合都市夜戏、孤独和雨后街道。', ['realistic'], 70,
                '冷蓝夜色里压着一点暖黄，适合都市夜戏、孤独和雨后街道。',
                '胶片介质选用 Kodak Vision3 500T 5219，整体色调以冷调蓝夜搭配墨绿与深青，点缀微弱暖黄光斑。采用电影标准 S 曲线，黑位下沉，高光柔和过渡。光线以冷蓝月光为环境底光，局部使用方向性暖色点光源形成冷暖对比。画面带有中等 35mm 胶片颗粒，高光边缘有轻微红色光晕。氛围 eerie、tense、lonely、nocturnal。镜头采用 28mm 广角配合轻微手持，关键部位切换 85mm。',
                ['medium' => ['Kodak Vision3 500T 5219'], 'palette' => ['Cold Blue Night'], 'contrast' => ['Filmic S-Curve', 'Crushed Blacks'], 'lighting' => ['Moonlight Cool Blue'], 'grain' => ['Medium Film Grain'], 'mood' => ['eerie', 'lonely', 'nocturnal'], 'lens' => ['28mm Documentary', '85mm Portrait Compression'], 'era' => ['Documentary Natural']]),
            self::row('widescreen_epic', '宽银幕史诗电影', '8K 宽银幕、变形镜头和 70-80 年代好莱坞史诗气质。', ['realistic'], 80,
                '宽银幕史诗，变形镜头、大画幅和 70-80 年代好莱坞色彩。',
                '8K IMAX 大画幅电影质感。变形宽银幕镜头，椭圆焦外，浅景深，主体分离强，带 70-80 年代好莱坞史诗片的视觉语言。光线来自场景本身：自然光、窗光、街灯、火光或霓虹，只在合理时出现。色彩按故事世界走，主色、辅色、点缀色清楚，不突然换一套颜色。物理电影镜头，按情绪选择手持、三脚架、移动或升降。',
                ['medium' => ['65mm/IMAX film'], 'palette' => ['Teal & Orange'], 'contrast' => ['Filmic S-Curve'], 'lighting' => ['Motivated Cinematic'], 'grain' => ['Fine Grain 35mm'], 'mood' => ['epic', 'cinematic'], 'lens' => ['Anamorphic 2x Squeeze'], 'era' => ['70-80s Hollywood Epic']]),

            self::row('showa_anime', '昭和动漫风', '复古赛璐璐、怀旧色彩。', ['anime'], 110,
                '复古赛璐璐，线条略粗，平涂上色，手绘背景，带复古胶片颗粒感。',
                '低饱和怀旧色彩，暗部偏青褐，高光偏暖黄。对比度中等，色块清楚。光线像老电视动画的方向光，阴影边缘硬，窗光和室内灯区分明显。氛围怀旧、略微粗糙、有年代感。',
                '', ['palette' => ['Sepia Tone'], 'contrast' => ['Medium Contrast Standard'], 'lighting' => ['Hard Cel Shadow'], 'mood' => ['nostalgic']]),
            self::row('classic_american_cartoon', '40-50年代美式手绘卡通', '黄金时代影院动画、夸张表演、手绘背景。', ['anime'], 120,
                '1940-1950 年代影院手绘卡通：清晰墨线、赛璐璐上色、夸张肢体和弹性形变、手绘背景、复古胶片颗粒感。借鉴黄金时代美国动画语言，但不要复刻任何既有角色、标志或受版权保护形象。',
                '温暖的舞台式配色，高对比，主光集中在人物身上，背景略暗。光线像剧场追光，边缘干净。氛围活泼、夸张、有表演感。',
                '', ['palette' => ['Golden Hour Warm'], 'contrast' => ['High Contrast'], 'lighting' => ['Stage Spotlight'], 'mood' => ['playful', 'theatrical']]),
            self::row('heisei_classic', '平成经典动漫风', '90s-00s 电视动画感，线稿清晰。', ['anime'], 130,
                '清晰赛璐璐线稿，分层上色，色块干净，角色设定稳定，有 90 年代到 2000 年代电视动画的完成度。',
                '90 年代到 2000 年代电视动画配色，饱和适中，天空和夜景有明显色相区分。对比度清楚，阴影是硬边色块。光线按日戏和夜戏分开，不做写实体积光。氛围稳定、叙事清楚。',
                '', ['palette' => ['90s Anime'], 'contrast' => ['Medium Contrast Standard'], 'lighting' => ['Hard Cel Shadow'], 'mood' => ['steady']]),
            self::row('moe', '萌系动漫风', '圆润可爱、明亮色彩、柔软线条。', ['anime'], 140,
                '圆润脸型，大眼睛，柔软线条，明亮可爱配色，表情细腻。',
                '高明度粉彩和奶油色，整体明亮。对比度偏低，阴影浅而软。光线柔和均匀，像晴天室内，高光小而干净。氛围可爱、轻松、柔软。',
                '', ['palette' => ['Pastel Cream'], 'contrast' => ['Low Contrast Soft'], 'lighting' => ['Soft Diffused Daylight'], 'mood' => ['cute', 'gentle']]),
            self::row('kyoto_animation', '京都动画风', '清爽青春、细腻表情、日常光影。', ['anime'], 150,
                '干净线条，柔和上色，细腻表情，日常生活场景里的清爽青春动画质感。',
                '柔和而偏高的饱和度，天空和绿意清楚，肤色干净。对比度适中，暗部不压死。光线是日常生活光：窗边、树荫、黄昏教室，阴影边缘略柔。氛围清爽、安静、有生活气。',
                '', ['palette' => ['Soft High Saturation'], 'contrast' => ['Medium Contrast Standard'], 'lighting' => ['Window Light Side', 'Dappled Light through Leaves'], 'mood' => ['youthful', 'gentle']]),
            self::row('shinkai', '新海诚风', '透明天空、逆光、细腻城市背景。', ['anime'], 160,
                '精细背景，干净线稿，空气感光晕，角色上色细腻但不写实。',
                '高透明度天空，蓝和金的逆光，城市和云层色彩细腻。对比度中高，亮部有光晕，暗部仍透气。光线以强烈逆光、黄昏和雨后反射为主。氛围辽阔、清澈、带一点孤独。',
                '', ['palette' => ['Transparent Sky Blue'], 'contrast' => ['Medium Contrast Standard'], 'lighting' => ['Strong Backlight'], 'mood' => ['vast', 'lonely', 'clear']]),
            self::row('modern_mobile_game', '现代手游二次元风', '精致立绘、高饱和光效、数字绘。', ['anime'], 170,
                '精致角色设计，干净数字绘，高饱和光效，材质层次细，仍是二次元立绘而不是照片。',
                '高饱和配色，主色鲜明，光效作为点缀。对比度偏高，亮部有发光，暗部干净。光线带数字绘的轮廓光和局部高光，不出现真实镜头景深。氛围精致、华丽、游戏立绘感。',
                '', ['palette' => ['High Saturation'], 'contrast' => ['High Contrast'], 'lighting' => ['Digital Rim Light'], 'mood' => ['polished', 'vivid']]),
            self::row('guoman', '国漫风', '东方审美、数字动画渲染、层次丰富。', ['anime'], 180,
                '东方审美的角色造型，数字动画上色，线条清楚，服装和背景层次丰富。',
                '东方色彩体系，青、朱、墨、金可以作主色，按场景统一。对比度中高，层次多但不脏。光线有方向，夜戏和室内用暖色实用光，日戏通透。氛围有东方叙事感。',
                '', ['palette' => ['Ink and Cinnabar'], 'contrast' => ['Medium Contrast Standard'], 'lighting' => ['Directional Story Light'], 'mood' => ['eastern', 'layered']]),
            self::row('cel_shaded', '赛璐璐动画风', '明确色块、硬边阴影、线稿清晰。', ['anime'], 190,
                '明确色块，硬边阴影，线稿清晰，干净平涂，不要柔涂和照片质感。',
                '色相分明，每个面只用有限的几级颜色。对比度清楚，阴影边界硬。光线用色块表示，不画真实光斑和镜头光晕。氛围干净、图形化。',
                '', ['palette' => ['Flat Cel Color'], 'contrast' => ['High Contrast'], 'lighting' => ['Hard Cel Shadow'], 'mood' => ['graphic', 'clean']]),

            self::row('animated_feature', '动画电影3D', '角色友好、电影动画质感。', ['3d'], 210,
                '动画电影的三维角色，友好的头身比例，柔和材质，表情精致，不是真人，也不是游戏写实过场。',
                '柔和电影动画配色，肤色和服装干净。对比度中等，高光圆润。光线是动画电影灯光，体积光可以有，但材质保持风格化。氛围友好、精致、适合角色表演。',
                '', ['palette' => ['Soft Animated Feature'], 'contrast' => ['Medium Contrast Standard'], 'lighting' => ['Animated Feature Lighting'], 'mood' => ['friendly', 'polished']]),
            self::row('unreal', '虚幻引擎写实', '高精模型、真实材质、游戏过场质感。', ['3d'], 220,
                '高精三维模型，真实材质，体积光，游戏过场级渲染。这是三维写实，不是手机实拍照片，也不是二维动画。',
                '偏真实的色彩，按场景冷暖分开，材质反射清楚。对比度中高，暗部有环境光。光线用体积光、实用光和清晰的方向光。氛围像高端游戏过场。',
                '', ['palette' => ['Physically Based'], 'contrast' => ['High Contrast'], 'lighting' => ['Volumetric Light'], 'mood' => ['cinematic', 'precise']]),
            self::row('stylized_3d', '风格化3D', '夸张造型、简化材质、清晰轮廓。', ['3d'], 230,
                '夸张造型，简化材质，清晰轮廓，鲜明色块，三维但不追求真实皮肤。',
                '鲜明色块，主色少而清楚。对比度高，轮廓可读。光线简单，用大块明暗分开形体，少反射。氛围图形化、利落。',
                '', ['palette' => ['Bold Limited Palette'], 'contrast' => ['High Contrast'], 'lighting' => ['Simple Form Light'], 'mood' => ['graphic', 'bold']]),
            self::row('clay', '黏土动画', '手工材质、定格动画感。', ['3d'], 240,
                '手工黏土塑形，轻微指纹纹理，定格动画的顿挫感，材质可见。',
                '柔和的棚拍配色，颜色像真实黏土颜料。对比度偏低。光线是柔和棚拍光，阴影浅，能看清指纹和接缝。氛围手工、温暖、略微笨拙。',
                '', ['palette' => ['Clay Pigment'], 'contrast' => ['Low Contrast Soft'], 'lighting' => ['Soft Studio Light'], 'mood' => ['handmade', 'warm']]),
            self::row('low_poly', '低多边形', '几何切面、简洁明快。', ['3d'], 250,
                '低多边形几何切面，形体简洁，色块清晰，轻量游戏美术，不要写实材质。',
                '明快的平涂色块，每个面一种颜色。对比度清楚。光线用面的明暗表示，没有真实高光和景深。氛围简洁、轻快。',
                '', ['palette' => ['Flat Facet Color'], 'contrast' => ['Medium Contrast Standard'], 'lighting' => ['Facet Shade'], 'mood' => ['simple', 'bright']]),
        ];
    }

    public static function syncOfficial(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $changed = false;
        foreach (self::official() as $item) {
            $existing = StylePreset::where('user_id', $userId)
                ->where('is_system', 1)
                ->where('code', $item['code'])
                ->find();
            $next = [
                'name' => $item['name'],
                'summary' => $item['summary'],
                'visual_styles' => $item['visual_styles'],
                'parameters' => $item['parameters'],
                'technique_prompt' => $item['technique_prompt'],
                'prompt' => $item['prompt'],
                'lighting_prompt' => $item['lighting_prompt'],
                'sort' => $item['sort'],
            ];
            if ($existing instanceof StylePreset) {
                $current = [
                    'name' => (string) $existing->getAttr('name'),
                    'summary' => (string) $existing->getAttr('summary'),
                    'visual_styles' => SeriesStylePrompt::normalizeVisualStyles($existing->getAttr('visual_styles')),
                    'parameters' => $existing->getAttr('parameters') ?: [],
                    'technique_prompt' => (string) $existing->getAttr('technique_prompt'),
                    'prompt' => (string) $existing->getAttr('prompt'),
                    'lighting_prompt' => (string) $existing->getAttr('lighting_prompt'),
                    'sort' => (int) $existing->getAttr('sort'),
                ];
                if (json_encode($current, JSON_UNESCAPED_UNICODE) !== json_encode($next, JSON_UNESCAPED_UNICODE)) {
                    $existing->save($next);
                    $changed = true;
                }
                continue;
            }

            StylePreset::create($next + [
                'user_id' => $userId,
                'code' => $item['code'],
                'is_system' => 1,
            ]);
            $changed = true;
        }

        return $changed;
    }

    public static function findOfficialByCode(int $userId, string $code): ?StylePreset
    {
        $code = trim($code);
        if ($userId <= 0 || $code === '') {
            return null;
        }
        self::syncOfficial($userId);
        $preset = StylePreset::where('user_id', $userId)
            ->where('is_system', 1)
            ->where('code', $code)
            ->find();

        return $preset instanceof StylePreset ? $preset : null;
    }

    /**
     * @param list<string> $visualStyles
     * @param array<string, list<string>>|string $parametersOrLighting
     * @param array<string, list<string>> $parameters
     * @return array<string, mixed>
     */
    private static function row(
        string $code,
        string $name,
        string $summary,
        array $visualStyles,
        int $sort,
        string $techniquePrompt,
        string $prompt,
        array|string $parametersOrLighting = [],
        array $parameters = [],
    ): array {
        $lightingPrompt = '';
        if (is_string($parametersOrLighting)) {
            $lightingPrompt = $parametersOrLighting;
        } else {
            $parameters = $parametersOrLighting;
        }
        if (!array_intersect($visualStyles, ['anime', '3d'])) {
            $techniquePrompt = '';
        }
        $normalized = StyleParameterRules::normalize($parameters);
        $rendering = match ($code) {
            'showa_anime', 'classic_american_cartoon' => 'Retro Hand-drawn Animation',
            'heisei_classic', 'cel_shaded' => 'Cel Animation',
            'moe', 'kyoto_animation', 'shinkai', 'modern_mobile_game', 'guoman' => 'Soft Painted Anime',
            'animated_feature' => 'Animated Feature 3D',
            'unreal' => 'Realistic CGI',
            'stylized_3d' => 'Stylized 3D',
            'clay' => 'Clay Stop Motion',
            'low_poly' => 'Low Poly 3D',
            default => null,
        };
        if ($rendering !== null) {
            $normalized['rendering'] = [$rendering];
        }
        // Official descriptions retain contextual shot preferences; controls only expose global parameters.
        $groups = array_column(StyleParameterRules::catalog()['groups'], null, 'key');
        foreach ($normalized as $key => $values) {
            $group = $groups[$key] ?? null;
            if ($group === null) {
                unset($normalized[$key]);
                continue;
            }
            $allowed = StyleParameterRules::applies($group, $visualStyles[0])
                ? array_column(StyleParameterRules::options($group, $visualStyles[0]), 'value') : [];
            $normalized[$key] = array_values(array_intersect($values, $allowed));
        }
        if (!in_array('Film', $normalized['medium'], true)) {
            $normalized['film_stock'] = [];
        }

        return [
            'code' => $code,
            'name' => $name,
            'summary' => $summary,
            'visual_styles' => $visualStyles,
            'parameters' => $normalized,
            'technique_prompt' => $techniquePrompt,
            'prompt' => $prompt,
            'lighting_prompt' => $lightingPrompt,
            'sort' => $sort,
        ];
    }
}
