<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { Share } from '@element-plus/icons-vue'
import { t } from '@/i18n'
import {
  getAssetShareStatus,
  getSeriesShareStatus,
  listShareTargets,
  updateAssetShare,
  updateSeriesShare,
  type ShareTargetUser,
} from '@/api/assetShare'

export interface ShareTarget {
  kind: 'asset' | 'series'
  id: number
  name: string
}

const props = defineProps<{
  modelValue: boolean
  target: ShareTarget | null
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
}>()

const dialogVisible = ref(false)
const loading = ref(false)
const saving = ref(false)
const everyone = ref(false)
const selectedUserIds = ref<number[]>([])
const targets = ref<ShareTargetUser[]>([])
const targetKeyword = ref('')

const isSeries = computed(() => props.target?.kind === 'series')
const dialogTitle = computed(() => (isSeries.value ? t('分享作品') : t('分享资产')))
const targetKindLabel = computed(() => (isSeries.value ? t('作品') : t('资产')))
const dialogHint = computed(() =>
  isSeries.value
    ? t('分享给所有人后，平台内所有普通用户都能看到并引用这部作品下的全部资产（含之后新增的资产）')
    : t('分享给所有人后，平台内所有普通用户都能看到并引用这个资产'),
)
const selectedCountLabel = computed(() => t('已选择 {count} 个用户', { count: selectedUserIds.value.length }))
const shareDialogStyle = {
  width: 'min(94vw, 720px)',
  maxWidth: '720px',
  height: 'auto',
  maxHeight: 'calc(100dvh - 48px)',
  margin: '24px auto',
}

watch(
  () => props.modelValue,
  (open) => {
    dialogVisible.value = open
    if (open) void load()
  },
  { immediate: true },
)

watch(
  dialogVisible,
  (open) => {
    if (open !== props.modelValue) {
      emit('update:modelValue', open)
    }
  },
)

async function load() {
  if (!props.target) return
  loading.value = true
  try {
    const statusPromise = isSeries.value
      ? getSeriesShareStatus(props.target.id)
      : getAssetShareStatus(props.target.id)
    const [status, targetsRes] = await Promise.all([statusPromise, listShareTargets()])
    everyone.value = status.everyone
    selectedUserIds.value = status.users.map((u) => u.id)
    targets.value = targetsRes.users
  } catch (err: unknown) {
    ElMessage.error((err as Error)?.message || t('加载分享设置失败'))
  } finally {
    loading.value = false
  }
}

const filteredTargets = computed(() => {
  const keyword = targetKeyword.value.trim().toLowerCase()
  if (!keyword) return targets.value
  return targets.value.filter(
    (u) => u.display_name.toLowerCase().includes(keyword) || u.username.toLowerCase().includes(keyword),
  )
})

function displayUserName(user: ShareTargetUser): string {
  return user.display_name || user.username
}

function userInitial(user: ShareTargetUser): string {
  return (displayUserName(user).trim()[0] || '?').toUpperCase()
}

async function save() {
  if (!props.target) return
  saving.value = true
  try {
    const payload = {
      everyone: everyone.value,
      user_ids: everyone.value ? [] : selectedUserIds.value,
    }
    if (isSeries.value) {
      await updateSeriesShare({ series_id: props.target.id, ...payload })
    } else {
      await updateAssetShare({ asset_id: props.target.id, ...payload })
    }
    ElMessage.success(t('分享设置已保存'))
    dialogVisible.value = false
  } catch (err: unknown) {
    ElMessage.error((err as Error)?.message || t('保存分享设置失败'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <el-dialog
    v-model="dialogVisible"
    :title="dialogTitle"
    width="min(94vw, 720px)"
    :style="shareDialogStyle"
    class="asset-share-dialog-host"
    modal-class="asset-share-dialog-modal"
    header-class="asset-share-dialog__header"
    body-class="asset-share-dialog__body"
    footer-class="asset-share-dialog__footer"
    append-to-body
    destroy-on-close
  >
    <div v-loading="loading" class="share-dialog-body">
      <div v-if="target" class="share-dialog-target">
        <span class="share-dialog-target__icon"><el-icon><Share /></el-icon></span>
        <div>
          <span>{{ targetKindLabel }}</span>
          <strong>{{ target.name }}</strong>
        </div>
      </div>

      <div class="share-dialog-access-card" :class="{ 'is-public': everyone }">
        <div>
          <strong>{{ t('分享给所有人') }}</strong>
          <p>{{ dialogHint }}</p>
        </div>
        <el-switch v-model="everyone" />
      </div>

      <template v-if="!everyone">
        <div class="share-dialog-section-head">
          <span>{{ t('指定用户') }}</span>
          <small>{{ selectedCountLabel }}</small>
        </div>
        <el-input
          v-model="targetKeyword"
          :placeholder="t('搜索用户')"
          size="small"
          clearable
          class="share-dialog-search"
        />
        <el-checkbox-group v-model="selectedUserIds" class="share-dialog-user-list">
          <el-checkbox v-for="u in filteredTargets" :key="u.id" :value="u.id" class="share-dialog-user-item">
            <span class="share-dialog-user-avatar">{{ userInitial(u) }}</span>
            <span class="share-dialog-user-main">
              <strong>{{ displayUserName(u) }}</strong>
              <small>@{{ u.username }}</small>
            </span>
          </el-checkbox>
          <div v-if="filteredTargets.length === 0" class="share-dialog-empty">{{ t('没有匹配的用户') }}</div>
        </el-checkbox-group>
      </template>
    </div>

    <template #footer>
      <el-button @click="dialogVisible = false">{{ t('取消') }}</el-button>
      <el-button type="primary" :loading="saving" @click="save">{{ t('保存') }}</el-button>
    </template>
  </el-dialog>
</template>

<style scoped lang="scss">
:global(.asset-share-dialog-host.el-dialog) {
  width: min(94vw, 720px) !important;
  max-width: 720px;
  height: auto !important;
  min-height: 0;
  max-height: calc(100dvh - 48px);
  margin: 24px auto !important;
  display: flex;
  flex-direction: column;
  border: 1px solid var(--hairline);
  border-radius: 12px;
  overflow: hidden;
  background: var(--surface-card);
  box-shadow: 0 24px 70px rgba(16, 32, 51, 0.18);

  :deep(.el-dialog__title) {
    color: var(--on-dark);
    font-size: 17px;
    font-weight: 950;
  }

  :deep(.el-button) {
    height: 34px;
    border-radius: 8px;
    font-weight: 800;
  }
}

:global(.asset-share-dialog-modal),
:global(.asset-share-dialog-modal .el-overlay-dialog),
:global(.el-overlay-dialog.asset-share-dialog-modal) {
  align-items: flex-start !important;
  justify-content: center !important;
  overflow-x: hidden !important;
  overflow-y: auto !important;
  padding: 0 12px;
}

:global(.asset-share-dialog__header) {
  flex: 0 0 auto;
  display: flex !important;
  align-items: center;
  justify-content: space-between;
  margin: 0;
  padding: 18px 22px 14px;
  border-bottom: 1px solid var(--hairline);
}

:global(.asset-share-dialog__body) {
  flex: 1 1 auto;
  display: block !important;
  min-height: 0;
  max-height: calc(100dvh - 156px);
  overflow-y: auto;
  padding: 18px 22px;
  background: var(--canvas);
}

:global(.asset-share-dialog__footer) {
  flex: 0 0 auto;
  display: block !important;
  padding: 14px 22px;
  border-top: 1px solid var(--hairline);
  background: var(--surface-card);
}

.share-dialog-body {
  min-height: 0;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.share-dialog-target {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px;
  border: 1px solid var(--hairline);
  border-radius: 10px;
  background: var(--surface-card);

  div {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 3px;
  }

  span {
    color: var(--muted);
    font-size: 11px;
    font-weight: 850;
  }

  strong {
    color: var(--body-strong);
    font-size: 14px;
    font-weight: 950;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

.share-dialog-target__icon {
  width: 34px;
  height: 34px;
  flex-shrink: 0;
  display: grid;
  place-items: center;
  border-radius: 9px;
  background: rgba(var(--brand-cyan-rgb), 0.1);
  color: var(--accent-cyan) !important;
}

.share-dialog-access-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 14px;
  border: 1px solid var(--hairline);
  border-radius: 10px;
  background: var(--surface-card);
  transition:
    border-color var(--duration-fast) var(--ease-out),
    background var(--duration-fast) var(--ease-out);

  &.is-public {
    border-color: rgba(var(--brand-cyan-rgb), 0.34);
    background: rgba(var(--brand-cyan-rgb), 0.055);
  }

  strong {
    color: var(--body-strong);
    font-size: 13px;
    font-weight: 950;
  }

  p {
    margin: 5px 0 0;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.5;
  }
}

.share-dialog-section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 2px;

  span {
    color: var(--body-strong);
    font-size: 12px;
    font-weight: 950;
  }

  small {
    color: var(--muted-soft);
    font-size: 11px;
    font-weight: 800;
  }
}

.share-dialog-search {
  :deep(.el-input__wrapper) {
    border-radius: 8px !important;
    border: 1px solid var(--hairline) !important;
    background: var(--surface-card) !important;
    box-shadow: none !important;
  }
}

.share-dialog-user-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  flex: 1 1 260px;
  max-height: none;
  min-height: 0;
  overflow-y: auto;
  border: 1px solid var(--hairline);
  border-radius: 10px;
  padding: 10px;
  background: var(--surface-card);
}

.share-dialog-user-item {
  min-height: 46px;
  height: auto;
  margin: 0;
  padding: 8px 10px;
  border: 1px solid var(--hairline);
  border-radius: 9px;
  background: var(--surface-raised);
  display: flex;
  align-items: center;

  :deep(.el-checkbox__label) {
    min-width: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--body-strong);
  }
}

.share-dialog-user-avatar {
  width: 28px;
  height: 28px;
  flex-shrink: 0;
  display: grid;
  place-items: center;
  border-radius: 8px;
  background: rgba(var(--brand-cyan-rgb), 0.1);
  color: var(--accent-cyan);
  font-size: 12px;
  font-weight: 950;
}

.share-dialog-user-main {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 1px;

  strong {
    color: var(--body-strong);
    font-size: 13px;
    font-weight: 900;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  small {
    color: var(--muted-soft);
    font-size: 11px;
    font-weight: 700;
  }
}

.share-dialog-empty {
  text-align: center;
  color: var(--muted);
  font-size: 13px;
  padding: var(--space-md) 0;
}

@media (max-height: 640px) {
  :global(.asset-share-dialog-host.el-dialog) {
    height: auto !important;
    max-height: calc(100dvh - 32px);
    min-height: 0;
    margin: 16px auto !important;
  }

  :global(.asset-share-dialog__body) {
    max-height: calc(100dvh - 140px);
  }
}

@media (max-width: 680px) {
  :global(.asset-share-dialog-host.el-dialog) {
    width: calc(100vw - 24px) !important;
    height: auto !important;
    max-height: calc(100dvh - 24px);
    margin: 12px auto !important;
  }

  :global(.asset-share-dialog__body) {
    max-height: calc(100dvh - 132px);
  }
}
</style>
