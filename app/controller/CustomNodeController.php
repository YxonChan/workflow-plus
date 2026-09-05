<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use app\model\CustomNode;
use app\support\RedisCache;

class CustomNodeController extends BaseController
{
    /**
     * 查询工作流节点库。
     * 进入页面时先补齐固定节点，再返回按固定节点优先排序的节点列表。
     */
    public function index()
    {
        return successCode($this->doList());
    }

    /**
     * 新建自定义节点。
     * 从请求体读取 label、icon、kind、desc、scope；固定节点只能由系统初始化。
     */
    public function save()
    {
        $payload = $this->request->param();
        if (empty($payload['label'])) {
            return errorCode([], '节点名称不能为空', 422);
        }

        return successCode($this->doCreate($payload), 'success', 201);
    }

    /**
     * 删除自定义节点。
     * 从 JSON 请求体读取 id；固定节点不允许删除，只允许删除用户创建的节点。
     */
    public function delete()
    {
        $payload = $this->request->param();
        $id = $this->requireId($payload, '节点 id 不能为空');
        $this->doDelete($id);

        return successCode();
    }

    /**
     * 从请求体读取必填 id。
     * 所有接口参数统一走 JSON body，不再从 URL path 接收 id。
     */
    private function requireId(array $payload, string $message): int
    {
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            abort(422, $message);
        }

        return $id;
    }

    /**
     * 执行节点列表查询。
     * 先初始化/迁移系统固定节点，再返回所有节点。
     */
    private function doList(): array
    {
        $this->seedFixedNodes();

        $version = RedisCache::version('custom_nodes');
        $userId = $this->currentUserId();
        return RedisCache::remember("custom_nodes:list:{$this->currentUserCacheSuffix()}:v{$version}", 120, function () use ($userId): array {
            return CustomNode::where('user_id', $userId)
                ->order(['is_fixed' => 'desc', 'id' => 'asc'])
                ->select()
                ->toArray();
        });
    }

    /**
     * 执行自定义节点创建。
     * 写入 custom_nodes，并把 scope 规范化为 episode 或 series。
     */
    private function doCreate(array $payload): array
    {
        $node = new CustomNode();
        $node->save([
            'user_id' => $this->currentUserId(),
            'label' => trim($payload['label']),
            'icon' => $payload['icon'] ?? 'Menu',
            'kind' => $payload['kind'] ?? 'text',
            'desc' => trim($payload['desc'] ?? ''),
            'is_fixed' => 0,
            'scope' => $this->normalizeScope((string) ($payload['scope'] ?? 'episode')),
        ]);
        $this->clearCustomNodeCache();

        return $node->toArray();
    }

    /**
     * 执行节点删除。
     * 删除前检查 is_fixed，避免系统节点被误删。
     */
    private function doDelete(int $id): void
    {
        $node = CustomNode::where('id', $id)
            ->where('user_id', $this->currentUserId())
            ->find();
        if ($node && $node->getAttr('is_fixed')) {
            throw new \Exception('固定节点不支持删除');
        }
        if ($node) {
            $node->delete();
        }
        $this->clearCustomNodeCache();
    }

    /**
     * 初始化系统固定节点。
     * 包含剧集工作流节点和剧本工作流节点；缺失时自动补齐。
     */
    private function seedFixedNodes(): void
    {
        $this->migrateLegacySeriesFixedNodes();
        $this->moveCallSheetFixedNodeToSeries();

        $fixedNodes = [
            // 剧集工作流节点
            ['label' => '剧情概要', 'kind' => 'input', 'icon' => 'EditPen', 'desc' => '剧集页填写概要/剧本后注入', 'scope' => 'episode'],
            ['label' => '剧情扩写', 'kind' => 'text', 'icon' => 'MagicStick', 'desc' => '将概要扩写为完整剧情', 'scope' => 'episode'],
            ['label' => '分镜处理', 'kind' => 'text', 'icon' => 'Scissor', 'desc' => '按剧情节奏拆分镜头，不限定固定秒数', 'scope' => 'episode'],
            ['label' => '帧图生成', 'kind' => 'image', 'icon' => 'Picture', 'desc' => '为每个镜头生成首帧画面', 'scope' => 'episode'],
            ['label' => '视频生成', 'kind' => 'video', 'icon' => 'VideoCamera', 'desc' => '基于分镜描述和资产参考生成视频片段', 'scope' => 'episode'],
            ['label' => '输出视频', 'kind' => 'output', 'icon' => 'Download', 'desc' => '渲染最终 MP4', 'scope' => 'episode'],
            // 剧本工作流节点
            ['label' => '小说导入', 'kind' => 'input', 'icon' => 'EditPen', 'desc' => '输入完整小说/剧本正文', 'scope' => 'series'],
            ['label' => '结构拆解', 'kind' => 'text', 'icon' => 'MagicStick', 'desc' => '提取主线/人物线/冲突线', 'scope' => 'series'],
            ['label' => '剧集规划', 'kind' => 'text', 'icon' => 'Scissor', 'desc' => '按 1-2 分钟拆分成多集', 'scope' => 'series'],
            ['label' => '资产提取', 'kind' => 'text', 'icon' => 'Picture', 'desc' => '提取人物/场景/物品资产', 'scope' => 'series'],
            ['label' => '通告单生成', 'kind' => 'text', 'icon' => 'Tickets', 'desc' => '根据整剧拆解与分集规划生成拍摄通告单 HTML', 'scope' => 'series'],
            ['label' => '写入结果', 'kind' => 'output', 'icon' => 'Download', 'desc' => '写入剧集管理；资产按集生产时增量补齐', 'scope' => 'series'],
        ];

        foreach ($fixedNodes as $node) {
            $exists = CustomNode::where('label', $node['label'])
                ->where('user_id', $this->currentUserId())
                ->where('scope', $node['scope'])
                ->where('is_fixed', 1)
                ->find();
            if (!$exists) {
                CustomNode::create(array_merge($node, ['user_id' => $this->currentUserId(), 'is_fixed' => 1]));
                $this->clearCustomNodeCache();
            } else {
                $exists->save([
                    'kind' => $node['kind'],
                    'icon' => $node['icon'],
                    'desc' => $node['desc'],
                ]);
                $this->clearCustomNodeCache();
            }
        }
    }

    /**
     * 早期把通告单放在剧集节点库，这里迁移到剧本节点库。
     */
    private function moveCallSheetFixedNodeToSeries(): void
    {
        $episodeNode = CustomNode::where('is_fixed', 1)
            ->where('user_id', $this->currentUserId())
            ->where('scope', 'episode')
            ->where('label', '通告单生成')
            ->find();
        if (!$episodeNode instanceof CustomNode) {
            return;
        }

        $seriesNode = CustomNode::where('is_fixed', 1)
            ->where('user_id', $this->currentUserId())
            ->where('scope', 'series')
            ->where('label', '通告单生成')
            ->find();
        if ($seriesNode instanceof CustomNode) {
            $episodeNode->delete();
        } else {
            $episodeNode->save([
                'scope' => 'series',
                'desc' => '根据整剧拆解与分集规划生成拍摄通告单 HTML',
            ]);
        }
        $this->clearCustomNodeCache();
    }

    /**
     * 迁移旧版剧本固定节点名称。
     * 把旧的整剧/剧集拆解命名升级为当前的小说导入、结构拆解、写入结果。
     */
    private function migrateLegacySeriesFixedNodes(): void
    {
        $renameMap = [
            '整剧原文输入' => ['label' => '小说导入', 'kind' => 'input', 'icon' => 'EditPen', 'desc' => '输入完整小说/剧本正文'],
            '剧集拆解' => ['label' => '结构拆解', 'kind' => 'text', 'icon' => 'MagicStick', 'desc' => '提取主线/人物线/冲突线'],
            '输出剧集与资产' => ['label' => '写入结果', 'kind' => 'output', 'icon' => 'Download', 'desc' => '写入剧集管理；资产按集生产时增量补齐'],
        ];

        foreach ($renameMap as $oldLabel => $next) {
            $legacy = CustomNode::where('is_fixed', 1)
                ->where('user_id', $this->currentUserId())
                ->where('scope', 'series')
                ->where('label', $oldLabel)
                ->find();
            if ($legacy) {
                $legacy->save($next);
                $this->clearCustomNodeCache();
            }
        }
    }

    /**
     * 规范化节点适用范围。
     * 只允许 series，其他值都按 episode 处理。
     */
    private function normalizeScope(string $scope): string
    {
        return $scope === 'series' ? 'series' : 'episode';
    }

    /**
     * 清理节点库缓存。
     * 自定义节点或系统固定节点迁移后调用，保证工作流页面拿到最新节点库。
     */
    private function clearCustomNodeCache(): void
    {
        RedisCache::bumpVersion('custom_nodes');
    }
}
