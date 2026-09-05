<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { useI18n } from 'vue-i18n'
import {
  listAdminGrouped,
  createModelConfig,
  updateModelConfig,
  deleteModelConfig,
  probeModelConfig,
} from '@/api/modelConfig'
import { listAdminUsers } from '@/api/adminUser'
import type { AdminUser, ModelConfig, ModelConfigGroup, ModelConfigPayload, ModelConfigProbeResult, ModelType } from '@/types'
import PageToolbar from '@/components/ui/PageToolbar.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { resolveIcon } from '@/utils/iconRegistry'

const { t } = useI18n()
// ── Type metadata ─────────────────────────────────────────────────────────────
const TYPE_META: Record<ModelType, { title: string; description: string; icon: string }> = {
  text:  { title: '文本模型', description: '剧本拆解 · 分镜文案', icon: 'Document' },
  image: { title: '图片模型', description: '关键帧生图 · 角色设定', icon: 'Picture' },
  video: { title: '视频模型', description: '图生视频 · 动作生成', icon: 'VideoPlay' },
  voice: { title: '语音模型', description: '角色配音 · 旁白生成', icon: 'Headset' },
}

// ── State ─────────────────────────────────────────────────────────────────────
const loading    = ref(false)
const saving     = ref(false)
const deleting   = ref(false)
const groups     = ref<ModelConfigGroup[]>([])
const adminUsers = ref<AdminUser[]>([])
const probingId  = ref<number | null>(null)
const dialogVisible = ref(false)
const isCreating = ref(false)
const selectedId = ref<number | null>(null)
const formRef    = ref<FormInstance>()

/** 图片尺寸：ToAPIs 比例尺寸 */
type ImageAspectPreset = '16:9' | '1:1'
/** 清晰度：标准 / 高清 / 超清（超清在 OpenAI 兼容接口上映射为 high） */
type ImageQualityPreset = 'standard' | 'high' | 'ultra'
type VideoProviderPreset = 'generic' | 'yinhe_async' | 'minimax_v2'

interface FormState {
  type: ModelType
  name: string
  model_id: string
  endpoint: string
  api_key: string
  /** 文本模型单次最大输出 token，写入 options.max_tokens */
  max_tokens: number
  /** 图片模型：画幅 */
  image_aspect: ImageAspectPreset
  /** 图片模型：清晰度 */
  image_quality: ImageQualityPreset
  /** 视频模型：供应商异步协议 */
  video_provider: VideoProviderPreset
  /** 视频模型：显式查询端点，{id} 会替换为任务 ID */
  video_result_endpoint: string
  video_poll_interval: number
  video_poll_attempts: number
  video_max_reference_images: number
  video_generate_audio: boolean
  video_watermark: boolean
  scope: 'global' | 'user'
  user_id: number | null
  is_default: boolean
  enabled: boolean
}

const form = reactive<FormState>({
  type:       'text',
  name:       '',
  model_id:   '',
  endpoint:   '',
  api_key:    '',
  max_tokens: 65536,
  image_aspect: '16:9',
  image_quality: 'standard',
  video_provider: 'generic',
  video_result_endpoint: '',
  video_poll_interval: 5,
  video_poll_attempts: 120,
  video_max_reference_images: 9,
  video_generate_audio: true,
  video_watermark: false,
  scope: 'global',
  user_id: null,
  is_default: false,
  enabled: true,
})

function imageSizeFromAspect(aspect: ImageAspectPreset): string {
  return aspect
}

function imageQualityToOption(preset: ImageQualityPreset): string {
  if (preset === 'standard') return 'medium'
  return 'high'
}

function parseImageAspectFromOptions(opt: Record<string, unknown>): ImageAspectPreset {
  const ar = String(opt.aspect_ratio ?? '').trim()
  if (ar === '1:1') return '1:1'
  const size = String(opt.size ?? '').toLowerCase()
  if (size === '1024x1024' || size === '1:1' || size === 'square') return '1:1'
  return '16:9'
}

function parseImageQualityFromOptions(opt: Record<string, unknown>): ImageQualityPreset {
  const q = String(opt.quality ?? '').toLowerCase()
  if (q === 'standard' || q === 'low' || q === 'medium') return 'standard'
  if (q === 'ultra' || q === '超清') return 'ultra'
  return 'high'
}

function imageQualityLabel(value: unknown): string {
  const labels: Record<string, string> = { low: '低清', medium: '标准', standard: '标准', high: '高清', ultra: '超清' }
  return t(labels[String(value || '').toLowerCase()] || '标准')
}

const rules: FormRules = {
  name:     [{ required: true, message: t('请输入显示名称'), trigger: 'blur' }],
  model_id: [{ required: true, message: t('请输入模型 ID'),  trigger: 'blur' }],
  endpoint: [
    { required: true, message: t('请输入接口地址'), trigger: 'blur' },
    { type: 'url',    message: t('请输入有效的 URL'), trigger: 'blur' },
  ],
}

// ── Derived ───────────────────────────────────────────────────────────────────
const activeModel = computed<ModelConfig | null>(() => {
  if (selectedId.value === null) return null
  for (const g of groups.value) {
    const m = g.models.find((m) => m.id === selectedId.value)
    if (m) return m
  }
  return null
})

const dialogTitle = computed(() => {
  if (isCreating.value) return t('新建{type}', { type: t(TYPE_META[form.type].title) })
  return t('编辑模型: {name}', { name: activeModel.value?.name || '' })
})

// ── Load ──────────────────────────────────────────────────────────────────────
async function loadModels() {
  loading.value = true
  try {
    groups.value = await listAdminGrouped()
  } finally {
    loading.value = false
  }
}

async function loadUsers() {
  adminUsers.value = (await listAdminUsers()).list
}

onMounted(async () => {
  await Promise.all([loadModels(), loadUsers()])
})

// ── Action helpers ────────────────────────────────────────────────────────────
function openCreate(type: ModelType) {
  isCreating.value = true
  selectedId.value = null
  form.type        = type
  form.name        = ''
  form.model_id    = ''
  form.endpoint    = ''
  form.api_key     = ''
  form.max_tokens  = type === 'text' ? 65536 : 4096
  form.image_aspect = '16:9'
  form.image_quality = 'standard'
  form.video_provider = type === 'video' ? 'yinhe_async' : 'generic'
  form.video_result_endpoint = type === 'video'
    ? 'https://api-aigc.fzyinghe.com/video/generation/tasks/{id}'
    : ''
  form.video_poll_interval = 5
  form.video_poll_attempts = 120
  form.video_max_reference_images = 9
  form.video_generate_audio = true
  form.video_watermark = false
  form.scope = 'global'
  form.user_id = null
  form.is_default = false
  form.enabled = true
  dialogVisible.value = true
  formRef.value?.resetFields()
}

function openEdit(model: ModelConfig) {
  isCreating.value = false
  selectedId.value = model.id
  form.type       = model.type
  form.name       = model.name
  form.model_id   = model.model_id
  form.endpoint   = model.endpoint
  form.api_key    = ''
  const opt = model.options ?? {}
  const mt = Number(opt.max_tokens ?? opt.max_completion_tokens ?? 0)
  form.max_tokens = model.type === 'text' ? (mt > 0 ? mt : 65536) : 4096
  if (model.type === 'image') {
    form.image_aspect = parseImageAspectFromOptions(opt)
    form.image_quality = parseImageQualityFromOptions(opt)
  }
  if (model.type === 'video') {
    const provider = String(opt.provider ?? '').toLowerCase()
    form.video_provider = provider === 'minimax_v2' || model.endpoint.includes('api.minimaxi.com/v2/video_generation')
      ? 'minimax_v2'
      : provider === 'yinhe_async' || model.endpoint.includes('api-aigc.fzyinghe.com/video/generation/tasks')
        ? 'yinhe_async'
        : 'generic'
    const configuredResultEndpoint = String(opt.result_endpoint ?? '').trim()
    form.video_result_endpoint = configuredResultEndpoint
      || (form.video_provider === 'yinhe_async'
        ? `${model.endpoint.replace(/\/$/, '')}/{id}`
        : form.video_provider === 'minimax_v2'
          ? 'https://api.minimaxi.com/v2/query/video_generation/{id}'
          : '')
    form.video_poll_interval = Math.max(2, Math.min(20, Number(opt.poll_interval ?? 5) || 5))
    form.video_poll_attempts = Math.max(1, Math.min(180, Number(opt.poll_attempts ?? 120) || 120))
    form.video_max_reference_images = Math.max(1, Math.min(9, Number(opt.max_reference_images ?? 9) || 9))
    form.video_generate_audio = opt.generate_audio !== false
    form.video_watermark = opt.watermark === true
  }
  form.scope = model.scope === 'user' ? 'user' : 'global'
  form.user_id = model.user_id || null
  form.is_default = Boolean(model.is_default)
  form.enabled = model.enabled !== 0
  dialogVisible.value = true
}

// ── Save ──────────────────────────────────────────────────────────────────────
async function saveModel() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return
  if (form.scope === 'user' && !form.user_id) {
    ElMessage.warning(t('请选择专属用户'))
    return
  }
  if (
    form.type === 'video'
    && ['yinhe_async', 'minimax_v2'].includes(form.video_provider)
    && form.video_result_endpoint.trim() !== ''
    && !form.video_result_endpoint.includes('{id}')
  ) {
    ElMessage.warning(t('异步任务查询地址必须包含 {id} 占位符', { id: '{id}' }))
    return
  }

  const baseOptions = isCreating.value ? {} : { ...(activeModel.value?.options ?? {}) }
  if (form.type === 'text') {
    baseOptions.max_tokens = Math.max(2048, Math.min(65536, Math.round(form.max_tokens) || 65536))
  }
  if (form.type === 'image') {
    baseOptions.size = imageSizeFromAspect(form.image_aspect)
    baseOptions.quality = imageQualityToOption(form.image_quality)
    baseOptions.n = 1
    baseOptions.aspect_ratio = form.image_aspect
    baseOptions.resolution = '1K'
    baseOptions.model_version = baseOptions.quality === 'medium' ? 'image2_medium' : (baseOptions.quality === 'low' ? 'image2_low' : 'image2_high')
  }
  if (form.type === 'video') {
    baseOptions.poll_interval = Math.max(2, Math.min(20, Math.round(form.video_poll_interval) || 5))
    baseOptions.poll_attempts = Math.max(1, Math.min(180, Math.round(form.video_poll_attempts) || 120))
    baseOptions.max_reference_images = Math.max(1, Math.min(9, Math.round(form.video_max_reference_images) || 9))
    baseOptions.generate_audio = form.video_generate_audio
    baseOptions.watermark = form.video_watermark
    if (form.video_result_endpoint.trim()) {
      baseOptions.result_endpoint = form.video_result_endpoint.trim()
    } else {
      delete baseOptions.result_endpoint
    }
    if (form.video_provider === 'yinhe_async') {
      baseOptions.provider = 'yinhe_async'
      baseOptions.include_model = true
      baseOptions.image_role = 'reference_image'
      baseOptions.allowed_params = ['duration', 'resolution', 'ratio', 'generate_audio', 'watermark']
      delete baseOptions.max_reference_videos
    } else if (form.video_provider === 'minimax_v2') {
      baseOptions.provider = 'minimax_v2'
      baseOptions.include_model = true
      baseOptions.image_role = 'reference_image'
      baseOptions.max_reference_images = 9
      baseOptions.max_reference_videos = 3
      baseOptions.result_endpoint = 'https://api.minimaxi.com/v2/query/video_generation/{id}'
      baseOptions.allowed_params = ['duration', 'resolution', 'ratio', 'watermark']
      delete baseOptions.generate_audio
    } else if (baseOptions.provider === 'yinhe_async' || baseOptions.provider === 'minimax_v2') {
      delete baseOptions.provider
      delete baseOptions.include_model
      delete baseOptions.image_role
      delete baseOptions.allowed_params
      delete baseOptions.max_reference_videos
    }
  }

  const payload: ModelConfigPayload = {
    type:     form.type,
    name:     form.name.trim(),
    model_id: form.model_id.trim(),
    endpoint: form.endpoint.trim(),
    api_key:  form.api_key.trim(),
    options:  baseOptions,
    scope: form.scope,
    user_id: form.scope === 'user' ? Number(form.user_id || 0) : 0,
    is_default: form.is_default ? 1 : 0,
    enabled: form.enabled ? 1 : 0,
  }

  saving.value = true
  try {
    if (isCreating.value) {
      await createModelConfig(payload)
      ElMessage.success(t('模型已创建'))
    } else if (selectedId.value !== null) {
      await updateModelConfig(selectedId.value, payload)
      ElMessage.success(t('模型已更新'))
    }
    dialogVisible.value = false
    await loadModels()
  } finally {
    saving.value = false
  }
}

// ── Delete ────────────────────────────────────────────────────────────────────
async function confirmDelete(model: ModelConfig) {
  await ElMessageBox.confirm(
    t('确认删除模型「{name}」？此操作不可恢复。', { name: model.name }),
    t('删除模型'),
    { type: 'warning', confirmButtonText: t('删除'), cancelButtonText: t('取消') },
  )

  deleting.value = true
  try {
    await deleteModelConfig(model.id)
    ElMessage.success(t('模型已删除'))
    await loadModels()
  } finally {
    deleting.value = false
  }
}

function escapeHtml(value: string): string {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;')
}

function probeModeLabel(result: ModelConfigProbeResult): string {
  return result.mode === 'live_request' ? t('真实轻量实检') : t('零成本预检')
}

function probeStatusLabel(result: ModelConfigProbeResult): string {
  if (result.ok) return t('通过')
  if (result.reachable) return t('可达但未通过判定')
  return t('失败')
}

function probeResultHtml(result: ModelConfigProbeResult): string {
  const rows = [
    [t('检测方式'), probeModeLabel(result)],
    [t('请求方式'), result.method],
    [t('判定结果'), probeStatusLabel(result)],
    [t('HTTP 状态'), result.http_status > 0 ? String(result.http_status) : '--'],
    [t('耗时'), `${result.duration_ms} ms`],
    [t('目标地址'), result.endpoint],
    [t('说明'), result.message],
    [t('细节'), result.detail || '--'],
  ]

  return `
    <div style="display:grid;gap:10px;font-size:13px;line-height:1.65;color:#1f2937;">
      ${rows.map(([label, value]) => `
        <div>
          <div style="font-weight:700;color:var(--on-dark);">${escapeHtml(label)}</div>
          <div style="margin-top:2px;word-break:break-all;color:#475569;">${escapeHtml(value)}</div>
        </div>
      `).join('')}
    </div>
  `
}

async function probeChannel(model: ModelConfig) {
  probingId.value = model.id
  try {
    const result = await probeModelConfig(model.id)
    await ElMessageBox.alert(probeResultHtml(result), t('通道检测 · {name}', { name: model.name }), {
      dangerouslyUseHTMLString: true,
      confirmButtonText: t('知道了'),
    })
  } finally {
    probingId.value = null
  }
}
</script>

<template>
  <div class="models-page">
    <PageToolbar
      kicker="MODEL ROUTER"
      :title="t('模型配置')"
      :subtitle="t('管理 AI 基础设施，支持 OpenAI 兼容接口与文本、图片、视频、语音模型。')"
    >
      <template #actions>
        <el-dropdown trigger="click" @command="openCreate">
          <el-button type="primary" class="btn-create">
            <el-icon><Plus /></el-icon>
            <span>{{ t('新建模型') }}</span>
            <el-icon class="el-icon--right"><ArrowDown /></el-icon>
          </el-button>
          <template #dropdown>
            <el-dropdown-menu class="create-dropdown">
              <el-dropdown-item
                v-for="(meta, type) in TYPE_META"
                :key="type"
                :command="type"
                class="create-dropdown-item"
                :class="'create-dropdown-item--' + type"
              >
                <div class="dropdown-item-content">
                  <div class="dropdown-item-icon">
                    <el-icon><component :is="resolveIcon(meta.icon)" /></el-icon>
                  </div>
                  <div class="dropdown-item-text">
                    <div class="dropdown-item-title">{{ t(meta.title) }}</div>
                    <div class="dropdown-item-desc">{{ t(meta.description) }}</div>
                  </div>
                </div>
              </el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </template>
    </PageToolbar>

    <div class="models-page__body scrollable">
      <div class="models-page__inner">
        <div v-if="loading && groups.length === 0" class="loading-state">
          <el-icon class="is-loading" :size="32"><Loading /></el-icon>
          <p>{{ t('正在加载模型...') }}</p>
        </div>

        <template v-else>
          <section v-for="group in groups" :key="group.type" class="models-section">
            <h3 class="models-section__title">
              <el-icon class="models-section__icon"><component :is="resolveIcon(TYPE_META[group.type].icon)" /></el-icon>
              <span>{{ t(group.title) }}</span>
              <StatusBadge tone="info">{{ t('{count} 个模型', { count: group.models.length }) }}</StatusBadge>
            </h3>

            <transition-group name="list" tag="div" class="card-grid">
              <article
                v-for="model in group.models"
                :key="model.id"
                class="model-card"
              >
                <div class="model-card__body">
                  <div class="model-card__header">
                    <div class="model-card__info">
                      <h4 class="model-card__name">{{ model.name }}</h4>
                      <p class="model-card__id">{{ model.model_id }}</p>
                    </div>
                    <div class="model-card__actions">
                      <button
                        class="action-btn action-btn--probe"
                        :title="probingId === model.id ? t('检测中') : t('检测通道')"
                        :disabled="probingId === model.id"
                        @click="probeChannel(model)"
                      >
                        <el-icon><component :is="resolveIcon(probingId === model.id ? 'Loading' : 'Connection')" /></el-icon>
                      </button>
                      <button class="action-btn action-btn--edit" :title="t('编辑')" @click="openEdit(model)">
                        <el-icon><Edit /></el-icon>
                      </button>
                      <button class="action-btn action-btn--delete" :title="t('删除')" @click="confirmDelete(model)">
                        <el-icon><Delete /></el-icon>
                      </button>
                    </div>
                  </div>

                  <div class="model-card__chips">
                    <span class="chip chip--system">
                      <el-icon><User /></el-icon>
                      <span>{{ model.scope === 'user' ? (model.user?.display_name || model.user?.username || t('用户 #{id}', { id: model.user_id })) : t('全局默认池') }}</span>
                    </span>
                    <span v-if="model.is_default" class="chip chip--series">
                      <el-icon><Star /></el-icon>
                      <span>{{ t('默认') }}</span>
                    </span>
                    <span v-if="model.enabled === 0" class="chip chip--danger">
                      <el-icon><CircleClose /></el-icon>
                      <span>{{ t('停用') }}</span>
                    </span>
                    <template v-if="group.type === 'text'">
                      <span class="chip chip--series">
                        <el-icon><Document /></el-icon>
                        <span>{{ Math.round((model.options?.max_tokens || 65536) / 1024) }}k Max Tokens</span>
                      </span>
                    </template>
                    <template v-else-if="group.type === 'image'">
                      <span class="chip chip--episode">
                        <el-icon><Picture /></el-icon>
                        <span>{{ t('{value} 画幅', { value: model.options?.aspect_ratio || '16:9' }) }}</span>
                      </span>
                      <span class="chip chip--episode">
                        <el-icon><Setting /></el-icon>
                        <span>{{ imageQualityLabel(model.options?.quality) }}</span>
                      </span>
                    </template>
                    <template v-else-if="group.type === 'video'">
                      <span class="chip chip--fan">
                        <el-icon><VideoPlay /></el-icon>
                        <span>{{ t('4s 动态生视频') }}</span>
                      </span>
                    </template>
                    <template v-else-if="group.type === 'voice'">
                      <span class="chip chip--system">
                        <el-icon><Headset /></el-icon>
                        <span>{{ t('24kHz 高保真旁白') }}</span>
                      </span>
                    </template>
                  </div>
                  
                  <div class="model-card__footer">
                    <span class="model-card__endpoint" :title="model.endpoint">
                      <span class="endpoint-text">{{ model.endpoint }}</span>
                    </span>
                  </div>
                </div>
              </article>

              <!-- Quick add card -->
              <button key="add-btn" class="add-card" @click="openCreate(group.type)">
                <div class="add-card__inner">
                  <el-icon :size="24"><Plus /></el-icon>
                  <span>{{ t('添加{title}', { title: t(group.title) }) }}</span>
                </div>
              </button>
            </transition-group>
          </section>
        </template>
      </div>
    </div>

    <!-- ── Edit/Create Dialog ────────────────────────────────── -->
    <el-dialog
      v-model="dialogVisible"
      :title="dialogTitle"
      width="640px"
      append-to-body
      class="model-dialog"
      :class="'model-dialog--' + form.type"
      destroy-on-close
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-position="top"
        class="dialog-form"
        @submit.prevent="saveModel"
      >
        <div class="form-section glass-form-card">
          <h4 class="form-section-title">
            <span class="title-indicator-line"></span>
            <span>{{ t('基础信息') }}</span>
          </h4>
          <el-form-item :label="t('配置范围')">
            <template #label>
              <div class="form-label-with-icon">
                <el-icon><User /></el-icon>
                <span>{{ t('配置范围') }}</span>
              </div>
            </template>
            <el-radio-group v-model="form.scope">
              <el-radio-button value="global">{{ t('全局默认') }}</el-radio-button>
              <el-radio-button value="user">{{ t('用户专属') }}</el-radio-button>
            </el-radio-group>
          </el-form-item>
          <el-form-item v-if="form.scope === 'user'" :label="t('专属用户')">
            <template #label>
              <div class="form-label-with-icon">
                <el-icon><UserFilled /></el-icon>
                <span>{{ t('专属用户') }}</span>
              </div>
            </template>
            <el-select v-model="form.user_id" :placeholder="t('选择用户')" style="width: 100%">
              <el-option
                v-for="user in adminUsers"
                :key="user.id"
                :label="user.display_name || user.username"
                :value="user.id"
              />
            </el-select>
          </el-form-item>
          <div class="field-grid">
            <el-form-item :label="t('启用')">
              <el-switch v-model="form.enabled" :active-text="t('启用')" :inactive-text="t('停用')" />
            </el-form-item>
            <el-form-item :label="t('设为默认')">
              <el-switch v-model="form.is_default" :active-text="t('默认')" :inactive-text="t('普通')" />
            </el-form-item>
          </div>
          <div class="field-grid">
            <el-form-item :label="t('显示名称')" prop="name">
              <template #label>
                <div class="form-label-with-icon">
                  <el-icon><Document /></el-icon>
                  <span>{{ t('显示名称') }}</span>
                </div>
              </template>
              <el-input v-model="form.name" placeholder="例如 deepseek-v3-pro" />
            </el-form-item>
            <el-form-item :label="t('模型 ID')" prop="model_id">
              <template #label>
                <div class="form-label-with-icon">
                  <el-icon><Cpu /></el-icon>
                  <span>{{ t('模型 ID') }}</span>
                </div>
              </template>
              <el-input v-model="form.model_id" placeholder="例如 deepseek-v3-pro" />
            </el-form-item>
          </div>
        </div>

        <div class="form-section glass-form-card">
          <h4 class="form-section-title">
            <span class="title-indicator-line"></span>
            <span>{{ t('接口信息') }}</span>
          </h4>
          <el-form-item :label="t('接口地址')" prop="endpoint">
            <template #label>
              <div class="form-label-with-icon">
                <el-icon><Link /></el-icon>
                <span>{{ t('接口地址') }}</span>
              </div>
            </template>
            <el-input v-model="form.endpoint" placeholder="https://api.example.com" />
          </el-form-item>
          <el-form-item label="API Key">
            <template #label>
              <div class="form-label-with-icon label-key-row">
                <div class="form-label-inner-title">
                  <el-icon><Key /></el-icon>
                  <span>API Key</span>
                </div>
                <span v-if="!isCreating && activeModel" class="masked-key-pill">
                  <el-icon><Lock /></el-icon>
                  <span>{{ activeModel.api_key_mask }}</span>
                </span>
              </div>
            </template>
            <el-input
              v-model="form.api_key"
              :placeholder="isCreating ? t('请输入 API Key') : t('留空表示不修改')"
              show-password
            />
          </el-form-item>
          <el-form-item v-if="form.type === 'text'" label="max_tokens（最大输出）">
            <template #label>
              <div class="form-label-with-icon">
                <el-icon><Setting /></el-icon>
                <span>max_tokens（最大输出）</span>
              </div>
            </template>
            <el-input-number
              v-model="form.max_tokens"
              :min="2048"
              :max="65536"
              :step="1024"
              controls-position="right"
              style="width: 100%"
            />
            <p class="field-hint">{{ t('剧本工作流会读取此值；后端上限 65536。提取剧集等节点建议 32768 及以上。') }}</p>
          </el-form-item>
          <template v-if="form.type === 'image'">
            <el-form-item :label="t('图片尺寸')">
              <template #label>
                <div class="form-label-with-icon">
                  <el-icon><Picture /></el-icon>
                  <span>{{ t('图片尺寸') }}</span>
                </div>
              </template>
              <el-radio-group v-model="form.image_aspect">
                <el-radio-button value="16:9">
                  <span class="radio-inner-content">
                    <el-icon><Monitor /></el-icon>
                    <span>{{ t('16:9 1K 横屏') }}</span>
                  </span>
                </el-radio-button>
                <el-radio-button value="1:1">
                  <span class="radio-inner-content">
                    <el-icon><PictureFilled /></el-icon>
                    <span>{{ t('1:1 1K 方图') }}</span>
                  </span>
                </el-radio-button>
              </el-radio-group>
              <p class="field-hint">
                {{ t('默认使用 image2，尺寸传 16:9 / 1:1，分辨率传 1K。') }}
              </p>
            </el-form-item>
            <el-form-item :label="t('清晰度')">
              <template #label>
                <div class="form-label-with-icon">
                  <el-icon><Lightning /></el-icon>
                  <span>{{ t('清晰度') }}</span>
                </div>
              </template>
              <el-radio-group v-model="form.image_quality">
                <el-radio-button value="standard">
                  <span class="radio-inner-content">
                    <el-icon><Compass /></el-icon>
                    <span>{{ t('标准') }}</span>
                  </span>
                </el-radio-button>
                <el-radio-button value="high">
                  <span class="radio-inner-content">
                    <el-icon><Star /></el-icon>
                    <span>{{ t('高清') }}</span>
                  </span>
                </el-radio-button>
                <el-radio-button value="ultra">
                  <span class="radio-inner-content">
                    <el-icon><GoldMedal /></el-icon>
                    <span>{{ t('超清') }}</span>
                  </span>
                </el-radio-button>
              </el-radio-group>
              <p class="field-hint">{{ t('默认「标准」medium 1K；高清/超清对应 high。') }}</p>
            </el-form-item>
          </template>
          <template v-if="form.type === 'video'">
            <el-form-item :label="t('视频接口协议')">
              <el-select v-model="form.video_provider" style="width: 100%">
                <el-option :label="t('MiniMax V2 多模态视频协议')" value="minimax_v2" />
                <el-option :label="t('银河异步视频协议')" value="yinhe_async" />
                <el-option :label="t('通用视频协议')" value="generic" />
              </el-select>
              <p class="field-hint">
                {{ form.video_provider === 'minimax_v2'
                  ? t('MiniMax V2 支持 768P、2K 直出以及图片和视频参考输入。')
                  : t('银河协议会发送 content 数组，并识别 taskId、resultUrl 与统一任务状态。') }}
              </p>
            </el-form-item>
            <el-form-item :label="t('任务查询地址')">
              <el-input
                v-model="form.video_result_endpoint"
                placeholder="https://api.example.com/video/generation/tasks/{id}"
              />
              <p class="field-hint"><code>{id}</code> {{ t('会在轮询时替换为创建接口返回的任务 ID。') }}</p>
            </el-form-item>
            <div class="field-grid">
              <el-form-item :label="t('轮询间隔（秒）')">
                <el-input-number
                  v-model="form.video_poll_interval"
                  :min="2"
                  :max="20"
                  controls-position="right"
                  style="width: 100%"
                />
              </el-form-item>
              <el-form-item :label="t('最大轮询次数')">
                <el-input-number
                  v-model="form.video_poll_attempts"
                  :min="1"
                  :max="180"
                  controls-position="right"
                  style="width: 100%"
                />
              </el-form-item>
            </div>
            <el-form-item :label="t('最大参考图数量')">
              <el-input-number
                v-model="form.video_max_reference_images"
                :min="1"
                :max="9"
                controls-position="right"
                style="width: 100%"
              />
            </el-form-item>
            <div class="field-grid">
              <el-form-item v-if="form.video_provider !== 'minimax_v2'" :label="t('生成音频')">
                <el-switch v-model="form.video_generate_audio" />
              </el-form-item>
              <el-form-item :label="t('添加水印')">
                <el-switch v-model="form.video_watermark" />
              </el-form-item>
            </div>
            <p class="field-hint">
              {{ t('银河协议自动携带稳定幂等键，Worker 重试时不会重复创建同一计费任务。') }}
            </p>
          </template>
        </div>
      </el-form>

      <template #footer>
        <div class="dialog-footer">
          <el-button @click="dialogVisible = false">{{ t('取消') }}</el-button>
          <el-button type="primary" class="btn-save" :loading="saving" @click="saveModel">{{ t('保存配置') }}</el-button>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
// Theme Color Coding
$color-text: #00c2ff;
$color-image: #00ff88;
$color-video: #ffb700;
$color-voice: #cc00ff;

.models-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: var(--canvas);
}

.btn-create {
  height: 36px !important;
  padding: 0 16px !important;
  font-weight: 600 !important;
  gap: 6px;
  
  span { margin: 0 2px; }
}

.models-page__body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: var(--space-xl) var(--space-lg);
}

.models-page__inner {
  max-width: 1100px;
  margin: 0 auto;
}

.loading-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: var(--space-section) 0;
  color: var(--muted);
  gap: var(--space-md);
}

.models-section {
  margin-bottom: var(--space-xxl);
}

.models-section__title {
  margin: 0 0 var(--space-md);
  font-size: var(--text-title-sm-size);
  font-weight: 700;
  color: var(--on-dark);
  display: flex;
  align-items: center;
  gap: var(--space-sm);
}

.models-section__icon {
  font-size: 18px;
  display: inline-flex;
  align-items: center;
  color: var(--muted);
}

.card-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
  align-items: stretch;
  gap: var(--space-md);
}

.model-card {
  display: flex;
  flex-direction: column;
  min-height: 200px;
  background: var(--surface-card);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  padding: var(--space-lg);
  transition: all var(--duration-fast) var(--ease-out);

  &:hover {
    background: var(--surface-card);
    box-shadow: 0 12px 28px rgba(16, 32, 51, 0.08);
    border-color: var(--hairline-strong);
  }
}

.model-card__body {
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  height: 100%;
  gap: 16px;
  flex: 1;
}

.model-card__header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}

.model-card__info {
  min-width: 0;
  flex: 1;
}

.model-card__name {
  margin: 0 0 4px;
  font-size: 15px;
  font-weight: 800;
  color: var(--on-dark);
  letter-spacing: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.model-card__id {
  margin: 0;
  font-family: var(--font-mono);
  font-size: 11px;
  color: var(--muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.model-card__actions {
  display: flex;
  gap: 6px;
  flex-shrink: 0;
}

.action-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 10px;
  border: 1px solid var(--hairline);
  background: var(--surface-soft);
  color: var(--muted);
  cursor: pointer;
  transition: all 0.25s ease;

  &:hover {
    color: var(--on-dark);
    background: var(--surface-card);
    border-color: var(--hairline-strong);
  }

  &--delete:hover {
    color: var(--accent-rose);
    border-color: var(--accent-rose);
  }

  &--probe:hover {
    color: var(--accent-cyan);
    border-color: var(--accent-cyan);
  }

  &:disabled {
    cursor: wait;
    opacity: 0.7;
  }
}

.model-card__chips {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
}

.chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 12px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--hairline);
  font-size: 11px;
  font-weight: 700;

  .el-icon {
    font-size: 12px;
  }
}

.chip--series {
  background: rgba(37, 99, 235, 0.08);
  border-color: rgba(37, 99, 235, 0.18);
  color: #1d4ed8;
}

.chip--episode {
  background: rgba(16, 185, 129, 0.09);
  border-color: rgba(16, 185, 129, 0.2);
  color: #047857;
}

.chip--fan {
  background: rgba(245, 158, 11, 0.14);
  border-color: rgba(245, 158, 11, 0.24);
  color: #b45309;
}

.chip--system {
  background: rgba(124, 58, 237, 0.08);
  border-color: rgba(124, 58, 237, 0.18);
  color: #6d28d9;
}

.chip--danger {
  background: rgba(244, 63, 94, 0.08);
  border-color: rgba(244, 63, 94, 0.2);
  color: #be123c;
}

.model-card__footer {
  padding-top: 14px;
  border-top: 1px solid var(--hairline);
}

.model-card__endpoint {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 12px;
  background: var(--surface-soft);
  border: 0.5px solid var(--hairline);
  border-radius: 10px;
  overflow: hidden;

  .endpoint-text {
    font-family: var(--font-mono);
    font-size: 11px;
    color: var(--muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
  }
}

.add-card {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
  min-height: 200px;
  background: var(--surface-raised) !important;
  border: 1px dashed var(--hairline-strong) !important;
  border-radius: var(--radius-md) !important;
  color: var(--muted) !important;
  cursor: pointer;
  transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
  padding: 24px;

  &__inner {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    
    .el-icon {
      font-size: 24px;
      transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    }

    span {
      font-size: 12px;
      font-weight: 700;
      letter-spacing: 0.5px;
      transition: all 0.4s ease;
    }
  }

  &:hover {
    border-color: var(--accent-cyan) !important;
    border-style: solid !important;
    color: var(--on-dark) !important;
    background: var(--surface-card) !important;
    box-shadow: 0 12px 28px rgba(16, 32, 51, 0.08) !important;

    .el-icon {
      color: var(--accent-cyan);
      transform: scale(1.15) rotate(90deg);
    }

    span {
      color: var(--accent-cyan);
    }
  }
}

// ── Dropdown ───────────────────────────────────────────────────────────────────
// 外层边框/阴影由全局 .el-dropdown__popper 统一提供；这里只调条目，避免双框。
:deep(.create-dropdown) {
  background: transparent !important;
  border: none !important;
  box-shadow: none !important;
  padding: 6px !important;
}

:deep(.create-dropdown-item) {
  padding: 10px 16px !important;
  border-radius: 10px !important;
  margin-bottom: 4px;
  transition: all 0.25s ease !important;
  background: transparent !important;

  &:last-child { margin-bottom: 0; }

  &.create-dropdown-item--text { --drop-accent: #{$color-text}; }
  &.create-dropdown-item--image { --drop-accent: #{$color-image}; }
  &.create-dropdown-item--video { --drop-accent: #{$color-video}; }
  &.create-dropdown-item--voice { --drop-accent: #{$color-voice}; }

  &:hover {
    background-color: var(--surface-soft) !important;

    .dropdown-item-icon {
      background-color: var(--drop-accent, var(--primary)) !important;
      color: #ffffff !important;
    }

    .dropdown-item-title {
      color: var(--drop-accent, var(--primary)) !important;
    }
  }
}

.dropdown-item-content {
  display: flex;
  align-items: center;
  gap: var(--space-md);
  min-width: 200px;
}

.dropdown-item-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  border-radius: 10px;
  background-color: var(--surface-soft);
  color: var(--accent-cyan);
  transition: all 0.25s ease;
  
  .el-icon { font-size: 16px; }
}

.dropdown-item-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.dropdown-item-title {
  font-size: 13px;
  font-weight: 700;
  color: var(--on-dark) !important;
  transition: color var(--duration-fast);
}

.dropdown-item-desc {
  font-size: 11px;
  color: var(--muted) !important;
}

// ── Dialog Scoped Styles ────────────────────────────────────────────────────────
.btn-save {
  min-width: 100px;
  font-weight: 700 !important;
}

.form-section {
  margin-bottom: var(--space-xl);
  &:last-child { margin-bottom: 0; }
}

.field-hint {
  margin: 8px 0 0;
  font-size: 11px;
  line-height: 1.5;
  color: var(--muted) !important;
}

.form-section-title {
  margin: 0 0 var(--space-md);
  font-size: 11px;
  font-weight: 800;
  color: var(--accent-cyan) !important;
  letter-spacing: 0 !important;
  text-transform: uppercase;
  padding-bottom: var(--space-xs);
  border-bottom: 1px solid var(--hairline) !important;
}

.field-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 var(--space-md);
}

.masked-key {
  margin-left: var(--space-sm);
  font-family: var(--font-mono);
  font-size: 12px;
  color: var(--muted);
}

.field-error {
  margin: 4px 0 0;
  font-size: 12px;
  color: var(--accent-rose);
}

.dialog-footer {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-sm);
  padding-top: var(--space-md);
}

// ── Animations ────────────────────────────────────────────────────────────────
.list-enter-active,
.list-leave-active {
  transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

.list-enter-from {
  opacity: 0;
  transform: scale(0.9) translateY(20px);
}

.list-leave-to {
  opacity: 0;
  transform: scale(0.9);
}

/* Ensure smooth moving of remaining items */
.list-move {
  transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Ensure leaving items are taken out of flow so moving items can animate */
.list-leave-active {
  position: absolute;
  z-index: 0;
  pointer-events: none;
}
</style>

<style lang="scss">
/* ── Model Settings Dialog (Global for Teleport) ─────────────────────────────────── */
.el-dialog.model-dialog {
  background: var(--surface-card) !important;
  backdrop-filter: none !important;
  border: 1px solid var(--hairline) !important;
  border-radius: var(--radius-lg) !important;
  box-shadow: 0 24px 70px rgba(16, 32, 51, 0.15) !important;
  overflow: hidden;
  position: relative;

  --dialog-accent-color: var(--primary);
  --dialog-accent-glow: rgba(15, 23, 42, 0.08);

  &.model-dialog--text {
    --dialog-accent-color: #1d4ed8;
    --dialog-accent-glow: rgba(37, 99, 235, 0.08);
  }
  &.model-dialog--image {
    --dialog-accent-color: #047857;
    --dialog-accent-glow: rgba(16, 185, 129, 0.09);
  }
  &.model-dialog--video {
    --dialog-accent-color: #b45309;
    --dialog-accent-glow: rgba(245, 158, 11, 0.14);
  }
  &.model-dialog--voice {
    --dialog-accent-color: #6d28d9;
    --dialog-accent-glow: rgba(124, 58, 237, 0.08);
  }

  .el-dialog__header {
    padding: 24px 32px 16px !important;
    border-bottom: 1px solid var(--hairline) !important;
    margin-right: 0 !important;
    background: var(--surface-card) !important;
    position: relative;
    z-index: 1;

    .el-dialog__title {
      font-size: 18px;
      font-weight: 700;
      color: var(--on-dark) !important;
      letter-spacing: 0;
    }
  }

  .el-dialog__headerbtn {
    top: 20px !important;
    right: 32px !important;
    width: 28px;
    height: 28px;
    border-radius: var(--radius-sm);
    background: transparent;
    border: none;
    transition: all var(--duration-fast) var(--ease-out);

    &:hover {
      background: var(--surface-soft);
      .el-dialog__close {
        color: var(--on-dark) !important;
      }
    }
  }

  .el-dialog__body {
    padding: 24px 32px !important;
    position: relative;
    z-index: 1;
    background: var(--surface-card) !important;
  }

  .el-dialog__footer {
    padding: 16px 32px 24px !important;
    border-top: none !important;
    background: var(--surface-card) !important;
    position: relative;
    z-index: 1;
  }

  /* Form and Input Elements Customization */
  .dialog-form {
    .glass-form-card {
      background: var(--surface-raised) !important;
      border: 1px solid var(--hairline) !important;
      border-radius: var(--radius-md) !important;
      padding: 20px 24px !important;
      margin-bottom: 20px !important;
      box-shadow: none !important;
      backdrop-filter: none !important;
      
      &:last-child {
        margin-bottom: 0 !important;
      }
    }

    .el-form-item {
      margin-bottom: 20px;

      &:last-child {
        margin-bottom: 0;
      }

      .el-form-item__label {
        display: inline-flex !important;
        align-items: center !important;
        flex-direction: row !important;
        font-size: 13px;
        font-weight: 700;
        color: var(--body-strong) !important;
        padding-bottom: 8px !important;
        letter-spacing: 0.5px;
        line-height: 1.2 !important;

        &::before {
          content: '*' !important;
          color: var(--accent-rose) !important;
          margin-right: 6px !important;
          font-size: 14px !important;
          font-weight: 900 !important;
          display: inline-block !important;
          vertical-align: middle !important;
          order: -1;
        }
      }
    }

    .form-label-with-icon {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      line-height: 1.2;
      width: 100%;

      .el-icon {
        font-size: 14px;
        color: var(--dialog-accent-color);
      }

      span {
        font-size: 13px;
        font-weight: 700;
      }

      &.label-key-row {
        justify-content: space-between !important;
        display: flex !important;
        width: 100% !important;
      }
    }

    .form-label-inner-title {
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    .masked-key-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 2px 10px;
      background: var(--surface-soft) !important;
      border: 1px solid var(--hairline) !important;
      border-radius: 20px;
      font-size: 11px;
      color: var(--muted) !important;
      font-family: var(--font-mono);

      .el-icon {
        font-size: 11px;
        color: var(--muted) !important;
        filter: none;
      }
    }

    .form-section-title {
      font-size: 12px;
      font-weight: 700;
      color: var(--dialog-accent-color) !important;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      padding-bottom: 10px;
      margin-bottom: 20px;
      border-bottom: 1px solid var(--hairline) !important;
      display: flex;
      align-items: center;
      gap: 8px;

      .title-indicator-line {
        width: 3px;
        height: 12px;
        background-color: var(--dialog-accent-color);
        border-radius: 20px;
        display: inline-block;
      }
    }

    .el-input__wrapper {
      background-color: var(--surface-card) !important;
      box-shadow: 0 0 0 1px var(--hairline) inset !important;
      border-radius: var(--radius-md) !important;
      padding: 8px 14px !important;
      transition: all var(--duration-fast) var(--ease-out);

      &.is-focus, &:hover {
        box-shadow: 
          0 0 0 1px var(--dialog-accent-color) inset,
          0 0 0 3px var(--dialog-accent-glow) !important;
      }

      .el-input__inner {
        color: var(--on-dark) !important;
        font-size: 13.5px !important;

        &::placeholder {
          color: var(--muted-soft) !important;
        }
      }
    }

    /* Input Number Style Customization */
    .el-input-number {
      background-color: transparent !important;
      border: none !important;

      .el-input__wrapper {
        padding-right: 44px !important;
      }

      .el-input-number__decrease, .el-input-number__increase {
        background: var(--surface-soft) !important;
        border-left: 1px solid var(--hairline) !important;
        color: var(--muted) !important;
        width: 32px !important;
        transition: all var(--duration-fast) var(--ease-out);

        &:hover {
          color: var(--dialog-accent-color) !important;
          background: var(--surface-raised) !important;
        }
      }

      .el-input-number__increase {
        border-bottom: 0.5px solid var(--hairline) !important;
        border-top-right-radius: var(--radius-md) !important;
        border-bottom-right-radius: 0 !important;
      }

      .el-input-number__decrease {
        border-top: 0.5px solid var(--hairline) !important;
        border-bottom-right-radius: var(--radius-md) !important;
        border-top-right-radius: 0 !important;
      }
    }

    /* Radio Button Style Customization */
    .el-radio-group {
      gap: 10px;
      width: 100%;

      .el-radio-button {
        flex: 1;
        
        .el-radio-button__inner {
          background: var(--surface-card) !important;
          border: 1px solid var(--hairline) !important;
          border-radius: var(--radius-md) !important;
          color: var(--body) !important;
          font-weight: 700;
          font-size: 12.5px;
          padding: 12px 20px !important;
          box-shadow: none !important;
          transition: all var(--duration-fast) var(--ease-out);
          width: 100%;
          display: flex;
          align-items: center;
          justify-content: center;

          .radio-inner-content {
            display: inline-flex;
            align-items: center;
            gap: 8px;

            .el-icon {
              font-size: 14px;
              transition: all var(--duration-fast) var(--ease-out);
            }
          }

          &:hover {
            color: var(--on-dark) !important;
            background: var(--surface-soft) !important;
            border-color: var(--hairline-strong) !important;
          }
        }

        &.is-active {
          .el-radio-button__inner {
            background: var(--dialog-accent-color) !important;
            border-color: var(--dialog-accent-color) !important;
            color: #ffffff !important;
            box-shadow: 0 4px 12px var(--dialog-accent-glow) !important;

            .radio-inner-content {
              .el-icon {
                transform: scale(1.1);
              }
            }
          }
        }
      }
    }

    .field-hint {
      margin: 8px 0 0;
      font-size: 11px;
      line-height: 1.5;
      color: var(--muted) !important;
    }
  }

  .dialog-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;

    .el-button {
      height: 40px !important;
      border-radius: var(--radius-md) !important;
      padding: 0 24px !important;
      font-weight: 700 !important;
      transition: all var(--duration-fast) var(--ease-out) !important;

      &:not(.el-button--primary) {
        background: var(--surface-card) !important;
        border: 1px solid var(--hairline) !important;
        color: var(--body) !important;

        &:hover {
          background: var(--surface-soft) !important;
          color: var(--on-dark) !important;
        }
      }

      &.el-button--primary {
        background: var(--dialog-accent-color) !important;
        border: 1px solid var(--dialog-accent-color) !important;
        color: #ffffff !important;
        box-shadow: 0 4px 12px var(--dialog-accent-glow) !important;

        &:hover {
          transform: translateY(-1px);
          box-shadow: 0 6px 16px var(--dialog-accent-glow) !important;
        }
      }
    }
  }
}
</style>
