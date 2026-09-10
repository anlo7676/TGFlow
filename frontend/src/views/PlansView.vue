<script setup lang="ts">
import { reactive, ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { api } from '../api'
import { apiErrorMessage, bytesToGb, gbToBytes } from '../utils'

interface Plan {
  id: number; name: string; description?: string | null; price_cents: number; currency: string
  traffic_bytes: number; duration_days: number; device_limit?: number | null
  connection_limit?: number | null; speed_limit_mbps?: number | null; node_group_id: number
  reset_mode: 'none' | 'monthly'; sort: number; enabled: boolean
}
interface PlanForm {
  name: string; description: string; price: number; currency: string; traffic_gb: number
  duration_days: number; device_limit?: number; connection_limit?: number
  speed_limit_mbps?: number; node_group_id: number; reset_mode: 'none' | 'monthly'
  sort: number; enabled: boolean
}

const rows = ref<Plan[]>([])
const loading = ref(false)
const submitting = ref(false)
const dialogVisible = ref(false)
const editingId = ref<number | null>(null)
const total = ref(0)
const page = ref(1)
const formRef = ref<FormInstance>()
const emptyForm = (): PlanForm => ({ name: '', description: '', price: 0, currency: 'CNY', traffic_gb: 100, duration_days: 30, node_group_id: 1, reset_mode: 'none', sort: 0, enabled: true })
const form = reactive<PlanForm>(emptyForm())
const rules: FormRules<PlanForm> = {
  name: [{ required: true, message: '请输入套餐名称', trigger: 'blur' }],
  price: [{ required: true, type: 'number', min: 0, message: '价格不能小于 0', trigger: 'blur' }],
  traffic_gb: [{ required: true, type: 'number', min: 0.01, message: '流量必须大于 0', trigger: 'blur' }],
  duration_days: [{ required: true, type: 'number', min: 1, max: 3650, message: '有效期为 1–3650 天', trigger: 'blur' }],
  node_group_id: [{ required: true, type: 'number', min: 1, message: '请输入有效的节点组 ID', trigger: 'blur' }],
}

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/plans', { params: { page: page.value } })
    rows.value = data.data
    total.value = data.total
  } catch (error) { ElMessage.error(apiErrorMessage(error, '套餐列表加载失败')) }
  finally { loading.value = false }
}

function openCreate() {
  editingId.value = null
  Object.assign(form, emptyForm())
  dialogVisible.value = true
}

function openEdit(plan: Plan) {
  editingId.value = plan.id
  Object.assign(form, {
    name: plan.name, description: plan.description || '', price: Number(plan.price_cents) / 100,
    currency: plan.currency, traffic_gb: Number(bytesToGb(plan.traffic_bytes)), duration_days: Number(plan.duration_days),
    device_limit: plan.device_limit ?? undefined, connection_limit: plan.connection_limit ?? undefined,
    speed_limit_mbps: plan.speed_limit_mbps ?? undefined, node_group_id: Number(plan.node_group_id),
    reset_mode: plan.reset_mode, sort: Number(plan.sort), enabled: Boolean(plan.enabled),
  })
  dialogVisible.value = true
}

async function submit() {
  if (!await formRef.value?.validate().catch(() => false)) return
  const payload = {
    name: form.name.trim(), description: form.description.trim() || null,
    price_cents: Math.round(form.price * 100), currency: form.currency.toUpperCase(),
    traffic_bytes: gbToBytes(form.traffic_gb), duration_days: form.duration_days,
    device_limit: form.device_limit || null, connection_limit: form.connection_limit || null,
    speed_limit_mbps: form.speed_limit_mbps || null, node_group_id: form.node_group_id,
    reset_mode: form.reset_mode, sort: form.sort, enabled: form.enabled,
  }
  submitting.value = true
  try {
    if (editingId.value) await api.put(`/plans/${editingId.value}`, payload)
    else await api.post('/plans', payload)
    ElMessage.success(editingId.value ? '套餐已更新' : '套餐已创建')
    dialogVisible.value = false
    await load()
  } catch (error) { ElMessage.error(apiErrorMessage(error)) }
  finally { submitting.value = false }
}

async function disable(plan: Plan) {
  try {
    await ElMessageBox.confirm(`确定下架套餐“${plan.name}”吗？已有订阅不会被删除。`, '下架套餐', { type: 'warning' })
    await api.delete(`/plans/${plan.id}`)
    ElMessage.success('套餐已下架')
    await load()
  } catch (error) {
    if (error !== 'cancel' && error !== 'close') ElMessage.error(apiErrorMessage(error))
  }
}

onMounted(load)
</script>

<template>
  <div>
    <div class="toolbar">
      <div><h1 class="page-title">套餐管理</h1><p class="muted">管理价格、流量、有效期与销售状态</p></div>
      <el-button type="primary" @click="openCreate">新建套餐</el-button>
    </div>
    <section class="panel table-panel">
      <el-table v-loading="loading" :data="rows">
        <el-table-column prop="name" label="套餐" min-width="150" />
        <el-table-column label="价格" width="120"><template #default="s">{{ s.row.currency }} {{ (s.row.price_cents / 100).toFixed(2) }}</template></el-table-column>
        <el-table-column label="流量" width="120"><template #default="s">{{ bytesToGb(s.row.traffic_bytes) }} GB</template></el-table-column>
        <el-table-column prop="duration_days" label="有效期" width="100"><template #default="s">{{ s.row.duration_days }} 天</template></el-table-column>
        <el-table-column prop="node_group_id" label="节点组" width="90" />
        <el-table-column label="状态" width="90"><template #default="s"><el-tag :type="s.row.enabled ? 'success' : 'info'">{{ s.row.enabled ? '在售' : '隐藏' }}</el-tag></template></el-table-column>
        <el-table-column label="操作" width="150" fixed="right"><template #default="s"><el-button link type="primary" @click="openEdit(s.row)">编辑</el-button><el-button v-if="s.row.enabled" link type="danger" @click="disable(s.row)">下架</el-button></template></el-table-column>
        <template #empty><el-empty description="暂无套餐" /></template>
      </el-table>
      <el-pagination v-if="total > 50" v-model:current-page="page" layout="prev, pager, next, total" :page-size="50" :total="total" @current-change="load" />
    </section>

    <el-dialog v-model="dialogVisible" :title="editingId ? '编辑套餐' : '新建套餐'" width="680px" destroy-on-close>
      <el-form ref="formRef" :model="form" :rules="rules" label-width="105px">
        <div class="form-grid">
          <el-form-item label="套餐名称" prop="name"><el-input v-model="form.name" /></el-form-item>
          <el-form-item label="货币"><el-input v-model="form.currency" maxlength="3" /></el-form-item>
          <el-form-item label="价格" prop="price"><el-input-number v-model="form.price" :min="0" :precision="2" :step="1" /></el-form-item>
          <el-form-item label="流量（GB）" prop="traffic_gb"><el-input-number v-model="form.traffic_gb" :min="0.01" :precision="2" /></el-form-item>
          <el-form-item label="有效期（天）" prop="duration_days"><el-input-number v-model="form.duration_days" :min="1" :max="3650" /></el-form-item>
          <el-form-item label="节点组 ID" prop="node_group_id"><el-input-number v-model="form.node_group_id" :min="1" /></el-form-item>
          <el-form-item label="设备上限"><el-input-number v-model="form.device_limit" :min="1" placeholder="不限" /></el-form-item>
          <el-form-item label="连接上限"><el-input-number v-model="form.connection_limit" :min="1" placeholder="不限" /></el-form-item>
          <el-form-item label="限速 Mbps"><el-input-number v-model="form.speed_limit_mbps" :min="1" placeholder="不限" /></el-form-item>
          <el-form-item label="流量重置"><el-select v-model="form.reset_mode"><el-option label="不重置" value="none" /><el-option label="每月重置" value="monthly" /></el-select></el-form-item>
          <el-form-item label="排序"><el-input-number v-model="form.sort" /></el-form-item>
          <el-form-item label="销售状态"><el-switch v-model="form.enabled" active-text="在售" inactive-text="隐藏" /></el-form-item>
        </div>
        <el-form-item label="套餐说明"><el-input v-model="form.description" type="textarea" :rows="3" maxlength="5000" show-word-limit /></el-form-item>
      </el-form>
      <template #footer><el-button @click="dialogVisible = false">取消</el-button><el-button type="primary" :loading="submitting" @click="submit">保存</el-button></template>
    </el-dialog>
  </div>
</template>
