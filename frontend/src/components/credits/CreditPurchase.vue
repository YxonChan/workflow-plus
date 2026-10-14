<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import { CircleCheckFilled, Refresh, Wallet } from '@element-plus/icons-vue'
import QRCode from 'qrcode'
import { createPaymentOrder, getPaymentOrderStatus, listPaymentOrders, listPaymentPackages, type PaymentOrder, type PaymentPackage } from '@/api/payment'
import { useAuthStore } from '@/stores/auth'

const props = defineProps<{ modelValue?: boolean }>()
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; paid: [] }>()
const { t } = useI18n()
const auth = useAuthStore()
const packages = ref<PaymentPackage[]>([])
const orders = ref<PaymentOrder[]>([])
const catalogLoading = ref(true)
const catalogError = ref(false)
const enabled = ref(false)
const creating = ref('')
const visible = ref(false)
const chooserVisible = ref(!!props.modelValue)
const selected = ref<PaymentOrder | null>(null)
const qr = ref('')
const checking = ref(false)
const statusWarning = ref('')
const now = ref(Date.now())
let pollTimer: number | null = null
let clockTimer: number | null = null
let generation = 0
let disposed = false

const remaining = computed(() => Math.max(0, Math.ceil((Date.parse(selected.value?.expires_at || '') - now.value) / 1000)) || 0)
const expired = computed(() => !!selected.value && remaining.value === 0)
const countdown = computed(() => `${Math.floor(remaining.value / 60)}:${String(remaining.value % 60).padStart(2, '0')}`)
const requestKey = computed(() => `workflow.payment.request.${auth.user?.id || 0}`)

function openPurchaseDialog() {
  chooserVisible.value = true
  if (!packages.value.length && !catalogLoading.value) void loadCatalog()
}

function credits(value: number) {
  return value.toLocaleString('zh-CN', { maximumFractionDigits: 2 })
}

async function loadCatalog() {
  catalogLoading.value = true
  catalogError.value = false
  try {
    const result = await listPaymentPackages()
    if (disposed) return
    packages.value = result.packages
    enabled.value = result.enabled
  } catch {
    catalogError.value = true
  } finally {
    catalogLoading.value = false
  }
}

async function loadOrders() {
  try {
    const result = await listPaymentOrders()
    if (!disposed) orders.value = result.orders
  } catch {
    // Checkout remains usable when order history is temporarily unavailable.
  }
}

function requestId(packageId: string) {
  try {
    const saved = JSON.parse(localStorage.getItem(requestKey.value) || 'null')
    if (saved?.packageId === packageId && /^[a-f0-9]{32}$/.test(saved.id)) return saved.id as string
  } catch { /* Ignore an invalid local checkout cache. */ }
  const id = crypto.randomUUID().replaceAll('-', '')
  localStorage.setItem(requestKey.value, JSON.stringify({ id, packageId }))
  return id
}

async function purchase(item: PaymentPackage) {
  if (!enabled.value || creating.value) return
  const existing = orders.value.find(order => order.package_id === item.id && order.status === 'pending'
    && !!order.code_url && Date.parse(order.expires_at) > Date.now())
  if (existing) {
    await openOrder(existing)
    return
  }
  creating.value = item.id
  chooserVisible.value = false
  try {
    const order = await createPaymentOrder(item.id, requestId(item.id))
    localStorage.removeItem(requestKey.value)
    if (disposed) return
    await openOrder(order)
    await loadOrders()
  } catch {
    // Keep the request id so a network retry cannot create a second order.
  } finally {
    creating.value = ''
  }
}

function stopPolling() {
  generation++
  if (pollTimer !== null) window.clearTimeout(pollTimer)
  if (clockTimer !== null) window.clearInterval(clockTimer)
  pollTimer = null
  clockTimer = null
  checking.value = false
}

async function openOrder(order: PaymentOrder) {
  stopPolling()
  const current = generation
  selected.value = order
  qr.value = ''
  statusWarning.value = ''
  now.value = Date.now()
  visible.value = true
  if (order.code_url && order.status === 'pending') {
    try {
      const data = await QRCode.toDataURL(order.code_url, { width: 256, margin: 3, errorCorrectionLevel: 'M' })
      if (current === generation) qr.value = data
    } catch {
      if (current === generation) statusWarning.value = t('二维码生成失败，请关闭后重新打开订单')
    }
  }
  if (current !== generation || order.status !== 'pending') return
  clockTimer = window.setInterval(() => { now.value = Date.now() }, 1000)
  void checkStatus(current)
}

async function checkStatus(current = generation) {
  if (!selected.value || checking.value || current !== generation || !visible.value) return
  if (pollTimer !== null) window.clearTimeout(pollTimer)
  checking.value = true
  const previousStatus = selected.value.status
  try {
    const order = await getPaymentOrderStatus(selected.value.id)
    if (current !== generation || disposed) return
    selected.value = order
    statusWarning.value = order.sync_warning || ''
    if (order.status !== 'pending') {
      stopPolling()
      await loadOrders()
      if (order.status === 'paid' && previousStatus !== 'paid') {
        ElMessage.success(t('充值成功，积分已到账'))
        await auth.refreshProfile(true, true)
        if (!disposed) emit('paid')
      }
      return
    }
  } catch {
    if (current === generation) statusWarning.value = t('支付状态暂未确认，请稍后刷新')
  } finally {
    if (current === generation) {
      checking.value = false
      // Bound background requests; the user can always request another check.
      if (visible.value && selected.value?.status === 'pending'
        && Date.now() < Date.parse(selected.value.expires_at) + 300_000) {
        schedulePoll(current)
      }
    }
  }
}

function schedulePoll(current: number) {
  pollTimer = window.setTimeout(() => {
    if (current !== generation || !visible.value) return
    if (document.visibilityState === 'visible') void checkStatus(current)
    else schedulePoll(current)
  }, 5000)
}

watch(visible, value => { if (!value) stopPolling() })
watch(() => props.modelValue, value => { chooserVisible.value = !!value })
watch(chooserVisible, value => emit('update:modelValue', value))
onMounted(() => { void Promise.all([loadCatalog(), loadOrders()]) })
onBeforeUnmount(() => { disposed = true; stopPolling() })

defineExpose({ open: openPurchaseDialog })
</script>

<template>
  <el-dialog v-model="chooserVisible" :title="t('购买积分')" width="540px" class="credit-chooser-dialog" align-center>
    <div class="chooser-intro">
      <div class="chooser-icon"><el-icon><Wallet /></el-icon></div>
      <div><strong>{{ t('选择充值套餐') }}</strong><span>{{ t('微信扫码支付，到账后自动增加积分') }}</span></div>
      <span class="payment-method"><el-icon><CircleCheckFilled /></el-icon>{{ t('微信支付') }}</span>
    </div>
    <div v-if="catalogError" class="purchase-notice">
      <span>{{ t('积分套餐加载失败') }}</span>
      <el-button :loading="catalogLoading" @click="loadCatalog"><el-icon><Refresh /></el-icon>{{ t('重试') }}</el-button>
    </div>
    <p v-else-if="!catalogLoading && !enabled" class="purchase-notice">{{ t('微信支付暂未开通') }}</p>
    <div v-else v-loading="catalogLoading" class="package-grid">
      <article v-for="item in packages" :key="item.id" class="package">
        <span class="package-name">{{ item.name }}</span>
        <strong class="package-price"><span>¥</span>{{ item.amount_cny }}</strong>
        <span class="package-credits">{{ credits(item.credit_amount) }} {{ t('积分') }}</span>
        <el-button class="buy-button" :disabled="!enabled || !!creating" :loading="creating === item.id" @click="purchase(item)">
          <el-icon><Wallet /></el-icon><span>{{ t('购买') }}</span>
        </el-button>
      </article>
    </div>
    <details v-if="orders.length" class="order-history" @toggle="loadOrders">
      <summary>{{ t('最近支付订单') }} · {{ orders.length }}</summary>
      <div class="order-list">
        <div v-for="order in orders" :key="order.id" class="order-row">
          <div><strong>¥{{ order.amount_cny }} · {{ credits(order.credit_amount) }} {{ t('积分') }}</strong><small>{{ order.order_no }}</small></div>
          <span :class="{ 'is-paid': order.status === 'paid' }">{{ t(order.status === 'paid' ? '支付成功' : order.status === 'closed' ? '已关闭' : '待支付') }}</span>
          <el-button text @click="openOrder(order)">{{ t(order.status === 'pending' ? '查看支付' : '详情') }}</el-button>
        </div>
      </div>
    </details>
  </el-dialog>
  <el-dialog v-model="visible" :title="t('微信支付')" width="380px" class="credit-pay-dialog" align-center>
      <div v-if="selected" class="payment-content">
        <template v-if="selected.status === 'paid'">
          <el-icon class="success-icon" :size="48"><CircleCheckFilled /></el-icon>
          <strong class="result-title">{{ t('支付成功') }}</strong>
          <span>{{ t('积分已到账：{count}', { count: credits(selected.credit_amount) }) }}</span>
        </template>
        <template v-else-if="selected.status === 'closed' || expired">
          <el-icon :size="48"><Clock /></el-icon>
          <strong class="result-title">{{ t(selected.status === 'closed' ? '订单已关闭' : '二维码已过期') }}</strong>
        </template>
        <template v-else>
          <div class="qr-frame" v-loading="!qr && !!selected.code_url">
            <img v-if="qr" :src="qr" :alt="t('微信支付二维码')" width="256" height="256" />
            <span v-else-if="!selected.code_url">{{ t('下单结果待确认') }}</span>
          </div>
          <strong class="payment-price">¥{{ selected.amount_cny }}</strong>
          <span>{{ t('微信扫码支付') }}</span>
          <span v-if="qr" class="payment-countdown">{{ t('剩余 {time}', { time: countdown }) }}</span>
        </template>
        <p v-if="statusWarning" role="status" class="status-warning">{{ statusWarning }}</p>
        <dl class="payment-meta">
          <dt>{{ t('购买积分') }}</dt><dd>{{ credits(selected.credit_amount) }}</dd>
          <dt>{{ t('订单号') }}</dt><dd>{{ selected.order_no }}</dd>
        </dl>
      </div>
      <template #footer>
        <el-button v-if="selected?.status === 'pending'" :loading="checking" @click="checkStatus()"><el-icon><Refresh /></el-icon>{{ t('刷新支付状态') }}</el-button>
        <el-button @click="visible = false">{{ t('关闭') }}</el-button>
      </template>
  </el-dialog>
</template>

<style scoped lang="scss">
.chooser-intro { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; padding: 12px; border: 1px solid var(--hairline); border-radius: 10px; background: rgba(255, 255, 255, 0.025); }
.chooser-intro > div:nth-child(2) { display: flex; flex: 1; flex-direction: column; gap: 3px; min-width: 0; }
.chooser-intro strong { color: var(--on-dark); font-size: 14px; }
.chooser-intro span { color: var(--muted); font-size: 12px; }
.chooser-icon { display: grid; place-items: center; width: 36px; height: 36px; border-radius: 9px; color: #dffbff; background: rgba(34, 211, 238, 0.14); }
.payment-method { display: inline-flex; align-items: center; gap: 6px; color: var(--accent-emerald); font-size: 13px; }
.package-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; min-height: 126px; }
.package { min-width: 0; padding: 14px; border: 1px solid var(--hairline); border-radius: 9px; display: flex; flex-direction: column; gap: 7px; background: var(--surface-card); transition: border-color var(--duration-fast), background var(--duration-fast); }
.package:hover { border-color: rgba(103, 232, 249, 0.45); background: rgba(34, 211, 238, 0.06); }
.package-name { color: var(--muted); font-size: 12px; }
.package-price { color: var(--on-dark); font-size: 25px; line-height: 1.15; }
.package-price span { font-size: 18px; margin-right: 3px; }
.package-credits { color: var(--body); font-size: 13px; margin-bottom: 4px; }
.buy-button { --el-button-text-color: var(--accent-emerald); --el-button-border-color: var(--hairline-strong); }
.buy-button .el-icon { margin-right: 5px; }
.purchase-notice { display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--muted); margin: 0 0 12px; }
.order-history { margin-top: 16px; font-size: 13px; }
.order-history summary { width: fit-content; cursor: pointer; color: var(--muted); }
.order-list { margin-top: 8px; }
.order-row { display: grid; grid-template-columns: 1fr auto auto; gap: 12px; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--hairline); }
.order-row small { display: block; color: var(--muted); overflow-wrap: anywhere; }
.order-row strong { font-weight: 500; }
.is-paid, .success-icon { color: var(--accent-emerald); }
.payment-content { display: flex; align-items: center; flex-direction: column; gap: 10px; color: var(--muted); font-size: 13px; }
.qr-frame { width: 256px; height: 256px; max-width: 100%; background: white; color: #333; display: grid; place-items: center; }
.qr-frame img { display: block; max-width: 100%; height: auto; }
.payment-price { font-size: 26px; color: var(--on-dark); }
.result-title { font-size: 18px; color: var(--on-dark); }
.payment-countdown { font-variant-numeric: tabular-nums; }
.status-warning { margin: 0; color: var(--accent-amber); text-align: center; }
.payment-meta { margin: 14px 0 0; width: 100%; display: grid; grid-template-columns: auto 1fr; gap: 6px 16px; padding-top: 14px; border-top: 1px solid var(--hairline); font-size: 12px; }
.payment-meta dd { margin: 0; text-align: right; overflow-wrap: anywhere; }
@media (max-width: 1050px) {
  .package { grid-template-columns: 1fr; padding: 14px; }
  .package-price { grid-column: auto; }
}
@media (max-width: 600px) {
  .package-grid { grid-template-columns: 1fr; gap: 10px; }
  .package { grid-template-columns: 1fr auto; }
  .package-price { grid-column: auto; grid-row: 1; }
  .package-credits { grid-column: 1; }
  .buy-button { grid-column: 2; grid-row: 1 / 3; }
  .order-row { grid-template-columns: 1fr auto; gap: 6px; }
  .order-row .el-button { grid-column: 2; grid-row: 1 / 3; }
}
</style>

<style lang="scss">
.credit-chooser-dialog, .credit-pay-dialog { max-width: calc(100vw - 32px); max-height: calc(100dvh - 32px); overflow-y: auto; }
.credit-chooser-dialog .el-dialog__body, .credit-pay-dialog .el-dialog__body { padding-top: 8px; }
@media (max-width: 600px) {
  .chooser-intro { align-items: flex-start; flex-wrap: wrap; }
  .chooser-intro .payment-method { width: 100%; padding-left: 48px; }
  .package-grid { grid-template-columns: 1fr; }
  .package { display: grid; grid-template-columns: 1fr auto; align-items: center; }
  .package-name, .package-credits { grid-column: 1; }
  .package-price { grid-column: 1; grid-row: 1; }
  .package-name { grid-row: 2; }
  .package-credits { grid-row: 3; }
  .package .buy-button { grid-column: 2; grid-row: 1 / 4; }
}
</style>
