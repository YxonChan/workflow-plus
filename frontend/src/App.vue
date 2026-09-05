<script setup lang="ts">
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import zhCn from 'element-plus/es/locale/lang/zh-cn'
import { useAuthStore } from '@/stores/auth'

const AppSidebar = defineAsyncComponent(() => import('@/components/AppSidebar.vue'))
const WorkerCrew = defineAsyncComponent(() => import('@/components/WorkerCrew.vue'))

const route = useRoute()
const auth = useAuthStore()
const workerCrewReady = ref(false)
const isBlankLayout = () => route.meta?.layout === 'blank'
const showWorkerCrew = computed(() => workerCrewReady.value && auth.user?.role !== 'admin')

let creditPollTimer: number | null = null

function onVisibilityChange() {
  if (document.visibilityState === 'visible' && auth.isAuthenticated) {
    void auth.refreshProfile(true)
  }
}

function startCreditPoll() {
  stopCreditPoll()
  if (!auth.isAuthenticated) return
  // 作品流/节点生成成功点分散；轻量兜底，避免侧栏积分长期停留在登录缓存。
  creditPollTimer = window.setInterval(() => {
    if (document.visibilityState === 'visible' && auth.isAuthenticated) {
      void auth.refreshProfile(false, true)
    }
  }, 30_000)
}

function stopCreditPoll() {
  if (creditPollTimer !== null) {
    window.clearInterval(creditPollTimer)
    creditPollTimer = null
  }
}

watch(
  () => auth.isAuthenticated,
  (ok) => {
    if (ok) {
      void auth.refreshProfile(true)
      startCreditPoll()
    } else {
      stopCreditPoll()
    }
  },
  { immediate: true },
)

onMounted(() => {
  document.addEventListener('visibilitychange', onVisibilityChange)
  window.setTimeout(() => {
    workerCrewReady.value = true
  }, 800)
})

onBeforeUnmount(() => {
  document.removeEventListener('visibilitychange', onVisibilityChange)
  stopCreditPoll()
})
</script>

<template>
  <el-config-provider :locale="zhCn">
    <div class="app-root-shell">
      <!-- Blank layout (login, etc.) -->
      <router-view v-if="isBlankLayout()" />

      <!-- Main shell layout -->
      <div v-else class="app-container">
        <AppSidebar />
        <main class="app-workspace">
          <router-view />
        </main>
        <WorkerCrew v-if="showWorkerCrew" />
      </div>
    </div>
  </el-config-provider>
</template>

<style lang="scss">
.app-root-shell {
  width: 100vw;
  height: 100vh;
}

.app-container {
  display: flex;
  height: 100vh;
  width: 100vw;
  overflow: hidden;
  background: var(--canvas);
}

.app-workspace {
  flex: 1;
  height: 100%;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  background: var(--canvas);
  position: relative;
}
</style>
