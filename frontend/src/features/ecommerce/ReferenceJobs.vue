<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@/i18n'
import { resolveIcon } from '@/utils/iconRegistry'
import type { Job, SectionKind } from './types'

const props = defineProps<{ jobs: Job[]; kind: SectionKind; busy?: boolean }>()
const emit = defineEmits<{ check: [job: Job]; retry: [job: Job] }>()
const tasks = computed(() => props.jobs.filter(job => job.kind === props.kind && job.status !== 'success'))
const isGenerating = (job: Job) => job.status === 'running' || (job.status === 'queued' && job.provider_submitted === true)
const statusText = (job: Job) => isGenerating(job) ? t('生成中') : ({ queued: t('等待生成'), running: t('生成中'), success: t('已完成'), failed: t('生成失败'), unknown: t('待核对'), cancelled: t('已取消') })[job.status]
</script>

<template>
  <div v-for="job in tasks" :key="job.id" class="reference-job">
    <span>{{ statusText(job) }}</span>
    <small v-if="job.error_message">{{ job.error_message }}</small>
    <div v-if="job.status === 'unknown' || job.status === 'failed'" class="reference-actions">
      <el-button link :icon="resolveIcon('Refresh')" :disabled="busy" @click="emit('check', job)">{{ t('核对原任务') }}</el-button>
      <el-button v-if="job.status === 'failed'" link :icon="resolveIcon('RefreshRight')" :disabled="busy" @click="emit('retry', job)">{{ t('重新生成') }}</el-button>
    </div>
  </div>
</template>

<style scoped>
.reference-job { display: flex; align-items: flex-start; flex-direction: column; gap: 6px; font-size: 12px; color: var(--muted); margin-top: 12px; }
.reference-job small { color: #fda4af; line-height: 18px; overflow-wrap: anywhere; }
.reference-actions { display: flex; flex-wrap: wrap; gap: 10px; }
.reference-actions .el-button { margin-left: 0; font-size: 12px; }
</style>
