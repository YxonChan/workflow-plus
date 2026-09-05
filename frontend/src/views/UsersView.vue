<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useI18n } from 'vue-i18n'
import PageToolbar from '@/components/ui/PageToolbar.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import {
  createAdminUser,
  listAdminUsers,
  resetAdminUserPassword,
  topUpAdminUserCredit,
  updateAdminUser,
} from '@/api/adminUser'
import type { AdminUser } from '@/types'

const loading = ref(false)
const saving = ref(false)
const toppingUp = ref(false)
const rows = ref<AdminUser[]>([])
const keyword = ref('')
const dialogVisible = ref(false)
const topupVisible = ref(false)
const topupTarget = ref<AdminUser | null>(null)
const { t } = useI18n()
const usernamePattern = /^[A-Za-z0-9_-]{3,64}$/
const form = reactive({
  username: '',
  display_name: '',
  password: 'Aa123456',
  role: 'user' as 'admin' | 'user',
  status: 1,
})
const topupForm = reactive({
  amount: 1000,
  note: '',
})

async function load() {
  loading.value = true
  try {
    rows.value = (await listAdminUsers({ keyword: keyword.value.trim() })).list
  } finally {
    loading.value = false
  }
}

function openCreate() {
  form.username = ''
  form.display_name = ''
  form.password = 'Aa123456'
  form.role = 'user'
  form.status = 1
  dialogVisible.value = true
}

function openTopup(row: AdminUser) {
  topupTarget.value = row
  topupForm.amount = 1000
  topupForm.note = ''
  topupVisible.value = true
}

function validateCreateForm(): boolean {
  if (!form.username.trim()) {
    ElMessage.warning(t('请输入账号'))
    return false
  }
  if (!usernamePattern.test(form.username.trim())) {
    ElMessage.warning(t('账号只能包含字母、数字、下划线和短横线，长度 3-64 位'))
    return false
  }
  if (form.display_name.trim().length > 120) {
    ElMessage.warning(t('显示名称不能超过 120 个字符'))
    return false
  }
  if (!form.password) {
    ElMessage.warning(t('请输入初始密码'))
    return false
  }
  if (form.password.length < 6) {
    ElMessage.warning(t('密码至少 6 位'))
    return false
  }
  return true
}

async function saveUser() {
  if (!validateCreateForm()) {
    return
  }
  saving.value = true
  try {
    await createAdminUser({
      username: form.username.trim(),
      display_name: form.display_name.trim(),
      password: form.password,
      role: form.role,
      status: form.status,
    })
    ElMessage.success(t('用户已创建'))
    dialogVisible.value = false
    await load()
  } finally {
    saving.value = false
  }
}

async function submitTopup() {
  if (!topupTarget.value) return
  const amount = Number(topupForm.amount)
  if (!Number.isFinite(amount) || amount <= 0) {
    ElMessage.warning(t('充值积分必须大于 0'))
    return
  }
  toppingUp.value = true
  try {
    const result = await topUpAdminUserCredit(topupTarget.value.id, amount, topupForm.note.trim())
    ElMessage.success(t('已充值 {amount} 积分，当前余额 {balance}', {
      amount,
      balance: result.balance,
    }))
    topupVisible.value = false
    await load()
  } finally {
    toppingUp.value = false
  }
}

async function updateRole(row: AdminUser, role: 'admin' | 'user') {
  await updateAdminUser(row.id, { role })
  ElMessage.success(t('角色已更新'))
  await load()
}

async function updateStatus(row: AdminUser, status: number) {
  await updateAdminUser(row.id, { status })
  ElMessage.success(status ? t('用户已启用') : t('用户已禁用'))
  await load()
}

async function resetPassword(row: AdminUser) {
  const { value } = await ElMessageBox.prompt(t('重置「{name}」的密码', { name: row.username }), t('重置密码'), {
    confirmButtonText: t('重置'),
    cancelButtonText: t('取消'),
    inputValue: 'Aa123456',
    inputPattern: /.+/,
    inputErrorMessage: t('请输入新密码'),
  })
  await resetAdminUserPassword(row.id, value)
  ElMessage.success(t('密码已重置'))
}

function formatCredits(value?: number) {
  return Number(value || 0).toLocaleString(undefined, {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  })
}

onMounted(load)
</script>

<template>
  <div class="users-view">
    <PageToolbar kicker="ADMIN USERS" :title="t('用户管理')" :subtitle="t('管理登录账号、角色、积分余额和启用状态。')">
      <template #actions>
        <el-input v-model="keyword" clearable :placeholder="t('搜索用户')" style="width: 180px" @keyup.enter="load" @clear="load" />
        <el-button :loading="loading" @click="load">
          <el-icon><Refresh /></el-icon><span>{{ t('刷新') }}</span>
        </el-button>
        <el-button type="primary" @click="openCreate">
          <el-icon><Plus /></el-icon><span>{{ t('新建用户') }}</span>
        </el-button>
      </template>
    </PageToolbar>

    <div class="users-view__body scrollable">
      <el-table v-loading="loading" :data="rows" row-key="id" class="users-table">
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="username" :label="t('账号')" min-width="140" />
        <el-table-column prop="display_name" :label="t('显示名称')" min-width="160" />
        <el-table-column :label="t('积分余额')" width="140">
          <template #default="{ row }">
            {{ formatCredits(row.credit_balance) }}
          </template>
        </el-table-column>
        <el-table-column :label="t('角色')" width="160">
          <template #default="{ row }">
            <el-select :model-value="row.role" size="small" @change="(value) => updateRole(row, value)">
              <el-option :label="t('管理员')" value="admin" />
              <el-option :label="t('普通用户')" value="user" />
            </el-select>
          </template>
        </el-table-column>
        <el-table-column :label="t('状态')" width="130">
          <template #default="{ row }">
            <StatusBadge :tone="row.status ? 'done' : 'fail'">{{ row.status ? t('启用') : t('禁用') }}</StatusBadge>
          </template>
        </el-table-column>
        <el-table-column prop="create_time" :label="t('创建时间')" width="180" />
        <el-table-column :label="t('操作')" width="280" fixed="right">
          <template #default="{ row }">
            <el-button text @click="openTopup(row)">{{ t('充值') }}</el-button>
            <el-button text @click="updateStatus(row, row.status ? 0 : 1)">{{ row.status ? t('禁用') : t('启用') }}</el-button>
            <el-button text @click="resetPassword(row)">{{ t('重置密码') }}</el-button>
          </template>
        </el-table-column>
      </el-table>
    </div>

    <el-dialog v-model="dialogVisible" :title="t('新建用户')" width="460px">
      <el-form label-position="top" @submit.prevent="saveUser">
        <el-form-item :label="t('账号')">
          <el-input v-model.trim="form.username" autocomplete="off" :placeholder="t('请输入账号')" />
        </el-form-item>
        <el-form-item :label="t('显示名称')">
          <el-input v-model.trim="form.display_name" autocomplete="off" :placeholder="t('默认使用账号作为显示名称')" />
        </el-form-item>
        <el-form-item :label="t('初始密码')">
          <el-input v-model="form.password" show-password autocomplete="new-password" :placeholder="t('请输入初始密码')" />
        </el-form-item>
        <el-form-item :label="t('角色')">
          <el-radio-group v-model="form.role">
            <el-radio-button value="user">{{ t('普通用户') }}</el-radio-button>
            <el-radio-button value="admin">{{ t('管理员') }}</el-radio-button>
          </el-radio-group>
        </el-form-item>
        <el-form-item :label="t('状态')">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" :active-text="t('启用')" :inactive-text="t('禁用')" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">{{ t('取消') }}</el-button>
        <el-button type="primary" :loading="saving" @click="saveUser">{{ t('创建') }}</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="topupVisible" :title="t('积分充值')" width="460px">
      <el-form label-position="top" @submit.prevent="submitTopup">
        <el-form-item :label="t('用户')">
          <el-input :model-value="topupTarget ? `${topupTarget.display_name || topupTarget.username} (#${topupTarget.id})` : ''" disabled />
        </el-form-item>
        <el-form-item :label="t('当前余额')">
          <el-input :model-value="formatCredits(topupTarget?.credit_balance)" disabled />
        </el-form-item>
        <el-form-item :label="t('充值积分')">
          <el-input-number v-model="topupForm.amount" :min="0.01" :step="100" :precision="2" style="width: 100%" />
        </el-form-item>
        <el-form-item :label="t('备注')">
          <el-input v-model="topupForm.note" type="textarea" :rows="2" :placeholder="t('可选，写入积分流水')" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="topupVisible = false">{{ t('取消') }}</el-button>
        <el-button type="primary" :loading="toppingUp" @click="submitTopup">{{ t('确认充值') }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.users-view {
  height: 100%;
  display: flex;
  flex-direction: column;
  background: var(--canvas);
}

.users-view__body {
  flex: 1;
  padding: var(--space-lg);
  min-height: 0;
}
</style>
