<?php
declare(strict_types=1);

namespace app\support;

final class PromptSkillCatalog
{
    public static function builtins(): array
    {
        $skills = json_decode(file_get_contents(dirname(__DIR__) . '/resources/prompt_skills.json'), true, 512, JSON_THROW_ON_ERROR);
        return array_map(static fn (array $skill): array => $skill + [
            'status' => 'ready', 'is_builtin' => true, 'version' => '1.0', 'license' => 'MIT',
            'source_url' => 'https://github.com/coreyhaines31/marketingskills/tree/55ff469422c3592dac45bcad08dfb40221981c02/skills/' . $skill['source'],
            'evaluations' => [], 'create_time' => null,
        ], $skills);
    }
}
