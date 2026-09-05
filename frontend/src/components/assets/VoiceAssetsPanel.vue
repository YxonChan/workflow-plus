<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Delete, Microphone, UploadFilled } from '@element-plus/icons-vue'
import { deleteVoiceAsset, getCharacterVoiceAsset, uploadVoiceAsset } from '@/api/voiceAsset'
import { t } from '@/i18n'
import type { VoiceAsset, VoiceAssetLimits } from '@/types'

const props = defineProps<{ assetId: number; characterName: string; disabled?: boolean }>()
const voice = ref<VoiceAsset | null>(null)
const loading = ref(false)
const uploading = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)
const limits = ref<VoiceAssetLimits>({ max_size_bytes: 20 * 1024 * 1024, min_duration_seconds: 5, max_duration_seconds: 30, allowed_extensions: ['wav', 'mp3', 'm4a'] })
const accept = computed(() => limits.value.allowed_extensions.map((ext) => `.${ext}`).join(','))
const limitDescription = computed(() => t('支持 {formats}，{min}-{max} 秒，最大 {size}MB', {
  formats: limits.value.allowed_extensions.map((ext) => ext.toUpperCase()).join(' / '),
  min: limits.value.min_duration_seconds,
  max: limits.value.max_duration_seconds,
  size: formatMb(limits.value.max_size_bytes),
}))

watch(() => props.assetId, (assetId) => { if (assetId > 0) void loadVoice(); else voice.value = null }, { immediate: true })

async function loadVoice() {
  loading.value = true
  try {
    const data = await getCharacterVoiceAsset(props.assetId)
    voice.value = data.voice_asset
    limits.value = data.limits ?? limits.value
  } catch (error: any) {
    ElMessage.error(error?.message || t('角色音色加载失败'))
  } finally { loading.value = false }
}

function chooseFile() { if (!props.disabled) fileInput.value?.click() }

async function onFileChange(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  const extension = file.name.split('.').pop()?.toLowerCase() ?? ''
  if (!limits.value.allowed_extensions.includes(extension)) return ElMessage.warning(t('请选择受支持的音频格式'))
  if (file.size > limits.value.max_size_bytes) return ElMessage.warning(t('音频文件不能超过 {size}MB', { size: formatMb(limits.value.max_size_bytes) }))

  try {
    await ElMessageBox.confirm(
      t('确认该声音已获得合法授权，并绑定给角色「{name}」？', { name: props.characterName }),
      voice.value ? t('替换角色音色') : t('上传角色音色'),
      { type: 'warning', confirmButtonText: t('确认上传'), cancelButtonText: t('取消') },
    )
  } catch { return }
  uploading.value = true
  try {
    const result = await uploadVoiceAsset({ asset_id: props.assetId, file, name: `${props.characterName}${t('的音色')}`, rights_confirmed: true })
    voice.value = result.voice_asset
    ElMessage.success(result.deduplicated ? t('该角色已使用相同音色') : t('角色音色已保存'))
  } catch (error: any) {
    ElMessage.error(error?.message || t('角色音色上传失败'))
  } finally { uploading.value = false }
}

async function removeVoice() {
  if (!voice.value) return
  try {
    await ElMessageBox.confirm(t('确定删除角色「{name}」的音色？', { name: props.characterName }), t('删除角色音色'), { type: 'warning' })
  } catch { return }
  try {
    await deleteVoiceAsset(props.assetId, voice.value.id)
    voice.value = null
    ElMessage.success(t('角色音色已删除'))
  } catch (error: any) { ElMessage.error(error?.message || t('删除角色音色失败')) }
}

function formatMb(bytes: number) { return Math.round((bytes / 1024 / 1024) * 10) / 10 }
function formatDuration(ms: number) { return `${Math.round(ms / 100) / 10}${t('秒')}` }
</script>

<template>
  <div class="character-voice" v-loading="loading">
    <input ref="fileInput" type="file" class="voice-file-input" :accept="accept" @change="onFileChange" />
    <div v-if="voice" class="character-voice__current">
      <div class="character-voice__icon"><el-icon><Microphone /></el-icon></div>
      <div class="character-voice__body">
        <strong>{{ voice.name }}</strong>
        <audio controls preload="none" :src="voice.source_url" />
        <small>{{ voice.extension.toUpperCase() }} · {{ formatDuration(voice.duration_ms) }} · {{ formatMb(voice.file_size) }}MB</small>
      </div>
      <div class="character-voice__actions">
        <el-button type="primary" plain :loading="uploading" :disabled="disabled" @click="chooseFile"><el-icon><UploadFilled /></el-icon>{{ t('替换音色') }}</el-button>
        <el-button type="danger" plain :disabled="disabled" @click="removeVoice"><el-icon><Delete /></el-icon>{{ t('删除') }}</el-button>
      </div>
    </div>
    <button v-else type="button" class="character-voice__empty" :disabled="disabled || uploading" @click="chooseFile">
      <el-icon><Microphone /></el-icon>
      <span>{{ t('为这个角色上传音色') }}</span>
      <small>{{ limitDescription }}</small>
    </button>
  </div>
</template>

<style scoped lang="scss">
.voice-file-input { display: none; }
.character-voice__current { display: grid; grid-template-columns: 48px minmax(0, 1fr) auto; gap: 14px; align-items: center; padding: 14px; border: 1px solid var(--hairline); border-radius: 12px; background: var(--surface-soft); }
.character-voice__icon { display: grid; place-items: center; width: 48px; height: 48px; border-radius: 14px; background: rgba(var(--brand-cyan-rgb), .12); color: var(--accent-cyan); font-size: 24px; }
.character-voice__body { display: grid; min-width: 0; gap: 7px; }
.character-voice__body strong { color: var(--on-dark); }
.character-voice__body audio { width: 100%; height: 36px; }
.character-voice__body small { color: #718196; }
.character-voice__actions { display: flex; gap: 8px; }
.character-voice__empty { display: grid; width: 100%; min-height: 120px; place-items: center; align-content: center; gap: 7px; border: 1px dashed var(--hairline-strong); border-radius: 12px; background: var(--surface-soft); color: var(--body); cursor: pointer; }
.character-voice__empty:not(:disabled):hover { border-color: var(--accent-cyan); background: rgba(var(--brand-cyan-rgb), 0.1); }
.character-voice__empty:disabled { cursor: not-allowed; opacity: .6; }
.character-voice__empty .el-icon { color: var(--accent-cyan); font-size: 30px; }
.character-voice__empty small { color: #718196; }
@media (max-width: 760px) { .character-voice__current { grid-template-columns: 42px minmax(0, 1fr); } .character-voice__actions { grid-column: 1 / -1; } }
</style>
