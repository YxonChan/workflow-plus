<?php

// +----------------------------------------------------------------------
// | 应用路由（强制路由模式下须显式注册）
// +----------------------------------------------------------------------
use think\facade\Route;

// Old HTML pages redirect to Vue SPA.
// public/studio/ files are kept as rollback backup.
Route::get('/', fn () => redirect('/admin/series'));
Route::get('series', fn () => redirect('/admin/series'));
Route::get('workflow', fn () => redirect('/admin/workflow'));
Route::get('models', fn () => redirect('/admin/models'));
Route::get('assets', fn () => redirect('/admin/assets'));

Route::group('api', function () {
    Route::post('auth/login', 'AuthController/login');
    Route::post('auth/refresh', 'AuthController/refresh');
    Route::post('auth/me', 'AuthController/me');

    Route::post('credits/ledger', 'CreditController/ledger');

    Route::post('agent/script/upload', 'AgentTaskController/uploadScript');
    Route::post('agent/tasks/create', 'AgentTaskController/create');
    Route::post('agent/tasks/detail', 'AgentTaskController/detail');
    Route::post('agent/tasks/approve', 'AgentTaskController/approve');
    Route::post('agent/tasks/resume', 'AgentTaskController/resume');
    Route::post('agent/tasks/cancel', 'AgentTaskController/cancel');

    Route::post('model-configs/list', 'ModelConfigController/index');
    Route::post('model-configs/create', 'ModelConfigController/save');
    Route::post('model-configs/update', 'ModelConfigController/update');
    Route::post('model-configs/delete', 'ModelConfigController/delete');

    Route::post('admin/users/list', 'AdminUserController/index');
    Route::post('admin/users/create', 'AdminUserController/save');
    Route::post('admin/users/update', 'AdminUserController/update');
    Route::post('admin/users/reset-password', 'AdminUserController/resetPassword');
    Route::post('admin/users/topup-credit', 'AdminUserController/topUpCredit');
    Route::post('admin/users/credit-ledger', 'AdminUserController/creditLedger');
    Route::post('admin/credit-pricing/list', 'CreditPricingController/index');
    Route::post('admin/model-configs/list', 'ModelConfigController/adminIndex');
    Route::post('admin/model-configs/create', 'ModelConfigController/save');
    Route::post('admin/model-configs/update', 'ModelConfigController/update');
    Route::post('admin/model-configs/delete', 'ModelConfigController/delete');
    Route::post('admin/model-configs/probe', 'ModelConfigController/probe');
    Route::post('admin/ai-request-logs/list', 'AiRequestLogController/index');
    Route::post('admin/ai-request-logs/detail', 'AiRequestLogController/detail');
    Route::post('admin/operation-logs/list', 'AdminOperationLogController/index');
    Route::post('admin/operation-logs/detail', 'AdminOperationLogController/detail');
    Route::post('admin/stats/credits', 'AdminStatsController/credits');
    Route::post('admin/production/overview', 'AdminProductionController/overview');
    Route::post('admin/production/series', 'AdminProductionController/series');
    Route::post('admin/production/runs', 'AdminProductionController/runs');

    Route::post('workflows/list', 'WorkflowController/index');
    Route::post('workflows/detail', 'WorkflowController/read');
    Route::post('workflows/create', 'WorkflowController/save');
    Route::post('workflows/update', 'WorkflowController/update');
    Route::post('workflows/delete', 'WorkflowController/delete');
    Route::post('workflows/duplicate', 'WorkflowController/duplicate');

    Route::post('workflow-bundles/list', 'WorkflowBundleController/index');
    Route::post('workflow-bundles/create', 'WorkflowBundleController/save');
    Route::post('workflow-bundles/update', 'WorkflowBundleController/update');
    Route::post('workflow-bundles/delete', 'WorkflowBundleController/delete');
    Route::post('workflow-bundles/duplicate', 'WorkflowBundleController/duplicate');

    Route::post('custom-nodes/list', 'CustomNodeController/index');
    Route::post('custom-nodes/create', 'CustomNodeController/save');
    Route::post('custom-nodes/delete', 'CustomNodeController/delete');

    Route::post('prompt-templates/list', 'PromptTemplateController/index');
    Route::post('prompt-templates/create', 'PromptTemplateController/save');
    Route::post('prompt-templates/update', 'PromptTemplateController/update');
    Route::post('prompt-templates/delete', 'PromptTemplateController/delete');

    // Assets
    Route::post('assets/list', 'AssetController/index');
    Route::post('assets/create', 'AssetController/save');
    Route::post('assets/update', 'AssetController/update');
    Route::post('assets/delete', 'AssetController/delete');
    Route::post('assets/list-for-reuse', 'AssetController/listForReuse');
    Route::post('assets/copy-from', 'AssetController/copyFrom');
    Route::post('assets/generate-image', 'AssetController/generateImage');
    Route::post('assets/batch-generate-core-images', 'AssetController/batchGenerateCoreImages');
    Route::post('assets/cancel-image-jobs', 'AssetController/cancelImageJobs');
    Route::post('assets/image-job', 'AssetController/imageJob');
    Route::post('assets/select-image-version', 'AssetController/selectImageVersion');
    Route::post('assets/delete-image-version', 'AssetController/deleteImageVersion');
    Route::post('assets/delete-image', 'AssetController/deleteImage');
    Route::post('assets/detect-look-avatar', 'AssetController/detectLookAvatar');

    Route::post('voice-assets/list', 'VoiceAssetController/index');
    Route::post('voice-assets/upload', 'VoiceAssetController/upload');
    Route::post('voice-assets/delete', 'VoiceAssetController/delete');

    Route::post('asset-shares/targets', 'AssetShareController/targets');
    Route::post('asset-shares/for-asset', 'AssetShareController/forAsset');
    Route::post('asset-shares/update', 'AssetShareController/update');
    Route::post('asset-shares/for-series', 'AssetShareController/forSeries');
    Route::post('asset-shares/update-series', 'AssetShareController/updateSeries');

    Route::post('ai-request-logs/list', 'AiRequestLogController/index');
    Route::post('ai-request-logs/detail', 'AiRequestLogController/detail');

    Route::post('workers/status', 'WorkerStatusController/status');
    Route::post('workers/action', 'WorkerStatusController/action');
    Route::post('workers/agent-chat', 'WorkerAgentController/chat');
    Route::post('workers/visual-review', 'WorkerAgentController/visualReview');
    Route::post('workers/repair-preview', 'WorkerAgentController/repairPreview');
    Route::post('workers/repair-apply', 'WorkerAgentController/repairApply');

    Route::post('upload/image', 'UploadController/image');
    Route::post('upload/video', 'UploadController/video');
    Route::post('upload/novel', 'UploadController/novel');

    Route::post('call-sheets/generate', 'CallSheetController/generate');
    Route::post('call-sheets/pdf', 'CallSheetController/pdf');

    Route::post('workflow-runs/list', 'WorkflowRunController/index');
    Route::post('workflow-runs/detail', 'WorkflowRunController/detail');
    Route::post('workflow-runs/resume', 'WorkflowRunController/resume');
    Route::post('workflow-runs/cancel', 'WorkflowRunController/cancel');
    Route::get('workflow-runs/stream', 'WorkflowRunController/stream');

    // Series & Episodes
    Route::post('series/list', 'SeriesController/index');
    Route::post('series/create', 'SeriesController/save');
    Route::post('series/update', 'SeriesController/update');
    Route::post('series/delete', 'SeriesController/delete');
    Route::post('series/run-workflow', 'SeriesController/runSeriesWorkflow');
    Route::post('episodes/create', 'SeriesController/saveEpisode');
    Route::post('episodes/detail', 'SeriesController/readEpisode');
    Route::post('episodes/update', 'SeriesController/updateEpisode');
    Route::post('episodes/prepare-workflow', 'SeriesController/prepareEpisodeWorkflow');
    Route::post('episodes/run-auto', 'SeriesController/runEpisodeWorkflowAuto');
    Route::post('episodes/cancel-auto-run', 'SeriesController/cancelEpisodeWorkflowAuto');
    Route::post('episodes/run-node', 'SeriesController/runEpisodeWorkflowNode');
    Route::post('episodes/run-node-async', 'SeriesController/runEpisodeWorkflowNodeAsync');
    Route::post('episodes/cancel-node', 'SeriesController/cancelEpisodeWorkflowNode');
    Route::post('episodes/preview-video-prompts', 'SeriesController/previewEpisodeVideoPrompts');
    Route::post('episodes/rerun-image-shot', 'SeriesController/rerunEpisodeImageShot');
    Route::post('episodes/rerun-video-shot', 'SeriesController/rerunEpisodeVideoShot');
    Route::post('episodes/select-media-version', 'SeriesController/selectEpisodeMediaVersion');
    Route::post('episodes/cancel-video-jobs', 'SeriesController/cancelEpisodeVideoJobs');
    Route::post('episodes/cancel-video-shot', 'SeriesController/cancelEpisodeVideoShot');
    Route::post('episodes/update-node-content', 'SeriesController/updateEpisodeWorkflowNode');
    Route::post('episodes/regenerate-storyboard-shot', 'SeriesController/regenerateEpisodeStoryboardShot');
    Route::post('episodes/delete', 'SeriesController/deleteEpisode');

    // 灵感速创（对话式快速生成）
    Route::post('quick-create/messages/list', 'QuickCreateController/index');
    Route::post('quick-create/messages/send', 'QuickCreateController/send');
    Route::post('quick-create/messages/edit', 'QuickCreateController/edit');
    Route::post('quick-create/messages/status', 'QuickCreateController/status');
    Route::post('quick-create/face-verification', 'QuickCreateController/faceVerification');
    Route::post('quick-create/face-verification/status', 'QuickCreateController/faceVerificationStatus');
    Route::get('quick-create/face-verification/stream', 'QuickCreateController/faceVerificationStream');

    // AI 驱动剧本创作
    Route::post('script-creation/configs', 'ScriptCreationController/configs');
    Route::post('script-creation/configs/save', 'ScriptCreationController/saveConfigs');
    Route::post('script-creation/models', 'ScriptCreationController/models');
    Route::post('script-creation/projects/list', 'ScriptCreationController/index');
    Route::post('script-creation/projects/detail', 'ScriptCreationController/detail');
    Route::post('script-creation/projects/pdf', 'ScriptCreationController/pdf');
    Route::post('script-creation/projects/txt', 'ScriptCreationController/txt');
    Route::post('script-creation/projects/create', 'ScriptCreationController/create');
    Route::post('script-creation/projects/update', 'ScriptCreationController/update');
    Route::post('script-creation/projects/start', 'ScriptCreationController/start');
    Route::post('script-creation/projects/pause', 'ScriptCreationController/pause');
    Route::post('script-creation/projects/resume', 'ScriptCreationController/resume');
    Route::post('script-creation/projects/retry', 'ScriptCreationController/retry');
    Route::post('script-creation/projects/cancel', 'ScriptCreationController/cancel');
    Route::post('script-creation/external-access', 'ScriptCreationController/externalAccess');
    Route::post('script-creation/external-access/rotate', 'ScriptCreationController/rotateExternalAccess');
    Route::post('script-creation/external-access/revoke', 'ScriptCreationController/revokeExternalAccess');

    // Hermes Agent 外部评分（使用独立 Bearer Token，不使用站内用户 JWT）
    Route::post('external/v1/scripts/list', 'HermesScriptController/projects');
    Route::post('external/v1/scripts/detail', 'HermesScriptController/detail');
    Route::post('external/v1/scripts/score', 'HermesScriptController/score');
});
