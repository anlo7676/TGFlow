<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { api } from '../api'
import { apiErrorMessage, bytesToGb, formatDate, gbToBytes } from '../utils'

interface Subscription {
  id: number; user_id: number; order_id?: number | null; plan_id?: number | null; status: string
  traffic_limit_bytes: number; traffic_used_bytes: number; bonus_traffic_bytes: number
  started_at: string; expires_at: string; entitlement_snapshot?: Record<string, unknown>
  desired_state_version?: number; applied_state_version?: number; created_at?: string
}

const rows = ref<Subscription[]>([])
const loading = ref(false)
const actionLoading = ref<number | null>(null)
const total = ref(0)
const page = ref(1)
const detailVisible = ref(false)
const detailLoading = ref(false)
const selected = ref<Subscription | null>(null)
const adjustVisible = ref(false)
const adjustType = ref<'traffic' | 'extend'>('traffic')
const adjustFormRef = ref<FormInstance>()
const adjustForm = reactive({ value: 10, reason: '' })
const adjustRules: FormRules = {
  value: [{ required: true, type: 'number', min: 0.01, message: '请输入大于 0 的数值', trigger: 'blur' }],
  reason: [{ required: true, message: '请输入调整原因', trigger: 'blur' }],
}

const statusLabels: Record<string, string> = {
  active: '正常', manually_disabled: '已停用', expired: '已到期', traffic_exhausted: '流量耗尽', pending: '待开通', provisioning: '开通中', error: '异常',
}
const statusType = (status: string) => status === 'active' ? 'success' : status === 'error' ? 'danger' : status === 'pending' || status === 'provisioning' ? 'warning' : 'info'

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/subscriptions', { params: { page: page.value } })
    rows.value = data.data
    total.value = data.total
  } catch (error) { ElMessage.error(apiErrorMessage(error, '订阅列表加载失败')) }
  finally { loading.value = false }
}

async function showDetail(subscription: Subscription) {
  selected.value = subscription
  detailVisible.value = true
  detailLoading.value = true
  try {
    const { data } = await api.get(`/subscriptions/${subscription.id}`)
    selected.value = data
  } catch (error) { ElMessage.error(apiErrorMessage(error, '订阅详情加载失败')) }
  finally { detailLoading.value = false }
}

async function changeStatus(subscription: Subscription, enable: boolean) {
  try {
    await ElMessageBox.confirm(
      enable ? `确定启用订阅 #${subscription.id} 吗？` : `确定停用订阅 #${subscription.id} 吗？关联代理凭据将被禁用。`,
      enable ? '启用订阅' : '停用订阅',
      { type: enable ? 'info' : 'warning', confirmButtonText: enable ? '启用' : '停用' },
    )
    actionLoading.value = subscription.id
    await api.post(`/subscriptions/${subscription.id}/${enable ? 'enable' : 'disable'}`)
    ElMessage.success(enable ? '订阅已启用' : '订阅已停用')
    await load()
  } catch (error) {
    if (error !== 'cancel' && error !== 'close') ElMessage.error(apiErrorMessage(error))
  } finally { actionLoading.value = null }
}

function openAdjust(subscription: Subscription, type: 'traffic' | 'extend') {
  selected.value = subscription
  adjustType.value = type
  adjustForm.value = type === 'traffic' ? 10 : 30
  adjustForm.reason = type === 'traffic' ? '' : '管理员延期'
  adjustVisible.value = true
}

async function submitAdjust() {
  if (!selected.value || !await adjustFormRef.value?.validate().catch(() => false)) return
  actionLoading.value = selected.value.id
  try {
    if (adjustType.value === 'traffic') {
      await api.post(`/subscriptions/${selected.value.id}/traffic`, { bytes: gbToBytes(adjustForm.value), reason: adjustForm.reason.trim() })
      ElMessage.success(`已增加 ${adjustForm.value} GB 流量`)
    } else {
      await api.post(`/subscriptions/${selected.value.id}/extend`, { days: Math.round(adjustForm.value) })
      ElMessage.success(`已延长 ${Math.round(adjustForm.value)} 天`)
    }
    adjustVisible.value = false
    await load()
  } catch (error) { ElMessage.error(apiErrorMessage(error)) }
  finally { actionLoading.value = null }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="toolbar"><div><h1 class="page-title">订阅管理</h1><p class="muted">查看订阅总账，调整状态、流量与有效期</p></div><el-button :loading="loading" @click="load">刷新</el-button></div>
    <section class="panel table-panel">
      <el-table v-loading="loading" :data="rows">
        <el-table-column prop="id" label="ID" width="75" />
        <el-table-column prop="user_id" label="用户" width="90"><template #default="s">#{{ s.row.user_id }}</template></el-table-column>
        <el-table-column label="已用 / 总量" min-width="190"><template #default="s"><div>{{ bytesToGb(s.row.traffic_used_bytes) }} / {{ bytesToGb(Number(s.row.traffic_limit_bytes) + Number(s.row.bonus_traffic_bytes)) }} GB</div><el-progress :percentage="Math.min(100, Math.round(Number(s.row.traffic_used_bytes) / Math.max(1, Number(s.row.traffic_limit_bytes) + Number(s.row.bonus_traffic_bytes)) * 100))" :show-text="false" /></template></el-table-column>
        <el-table-column label="到期时间" width="175"><template #default="s">{{ formatDate(s.row.expires_at) }}</template></el-table-column>
        <el-table-column label="状态" width="105"><template #default="s"><el-tag :type="statusType(s.row.status)">{{ statusLabels[s.row.status] || s.row.status }}</el-tag></template></el-table-column>
        <el-table-column label="操作" width="275" fixed="right"><template #default="s"><el-button link type="primary" @click="showDetail(s.row)">详情</el-button><el-button link type="primary" @click="openAdjust(s.row, 'traffic')">加流量</el-button><el-button link type="primary" @click="openAdjust(s.row, 'extend')">延期</el-button><el-button v-if="s.row.status !== 'active'" link type="success" :loading="actionLoading === s.row.id" @click="changeStatus(s.row, true)">启用</el-button><el-button v-else link type="danger" :loading="actionLoading === s.row.id" @click="changeStatus(s.row, false)">停用</el-button></template></el-table-column>
        <template #empty><el-empty description="暂无订阅" /></template>
      </el-table>
      <el-pagination v-if="total > 50" v-model:current-page="page" layout="prev, pager, next, total" :page-size="50" :total="total" @current-change="load" />
    </section>

    <el-drawer v-model="detailVisible" title="订阅详情" size="480px">
      <div v-loading="detailLoading" class="detail-list" v-if="selected">
        <div><span>订阅 ID</span><b>#{{ selected.id }}</b></div>
        <div><span>用户 ID</span><b>#{{ selected.user_id }}</b></div>
        <div><span>订单 ID</span><b>{{ selected.order_id ? `#${selected.order_id}` : '—' }}</b></div>
        <div><span>套餐 ID</span><b>{{ selected.plan_id ? `#${selected.plan_id}` : '—' }}</b></div>
        <div><span>状态</span><el-tag :type="statusType(selected.status)">{{ statusLabels[selected.status] || selected.status }}</el-tag></div>
        <div><span>基础流量</span><b>{{ bytesToGb(selected.traffic_limit_bytes) }} GB</b></div>
        <div><span>赠送流量</span><b>{{ bytesToGb(selected.bonus_traffic_bytes) }} GB</b></div>
        <div><span>已用流量</span><b>{{ bytesToGb(selected.traffic_used_bytes) }} GB</b></div>
        <div><span>开始时间</span><b>{{ formatDate(selected.started_at) }}</b></div>
        <div><span>到期时间</span><b>{{ formatDate(selected.expires_at) }}</b></div>
        <div><span>期望版本</span><b>{{ selected.desired_state_version ?? '—' }}</b></div>
        <div><span>应用版本</span><b>{{ selected.applied_state_version ?? '—' }}</b></div>
        <div v-if="selected.entitlement_snapshot" class="detail-json"><span>权益快照</span><pre>{{ JSON.stringify(selected.entitlement_snapshot, null, 2) }}</pre></div>
      </div>
    </el-drawer>

    <el-dialog v-model="adjustVisible" :title="adjustType === 'traffic' ? `为订阅 #${selected?.id} 增加流量` : `为订阅 #${selected?.id} 延长有效期`" width="460px" destroy-on-close>
      <el-form ref="adjustFormRef" :model="adjustForm" :rules="adjustRules" label-width="100px">
        <el-form-item :label="adjustType === 'traffic' ? '流量（GB）' : '天数'" prop="value"><el-input-number v-model="adjustForm.value" :min="adjustType === 'traffic' ? 0.01 : 1" :max="adjustType === 'extend' ? 3650 : undefined" :precision="adjustType === 'traffic' ? 2 : 0" /></el-form-item>
        <el-form-item v-if="adjustType === 'traffic'" label="调整原因" prop="reason"><el-input v-model="adjustForm.reason" type="textarea" :rows="3" maxlength="500" show-word-limit /></el-form-item>
      </el-form>
      <template #footer><el-button @click="adjustVisible = false">取消</el-button><el-button type="primary" :loading="actionLoading === selected?.id" @click="submitAdjust">确认调整</el-button></template>
    </el-dialog>
  </div>
</template>
