<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { api } from '../api'
import { apiErrorMessage, formatDate } from '../utils'

interface Server {
  id: number; name: string; host: string; ipv4?: string | null; ipv6?: string | null
  ssh_port: number; ssh_username: string; ssh_auth_type: 'key' | 'password'
  ssh_host_fingerprint: string; status: string; last_seen_at?: string | null; last_error?: string | null
}
interface Node {
  id: number; proxy_server_id: number; name: string; region: string; country_code: string
  public_host: string; public_port: number; fake_tls_domain?: string | null; engine: 'mtproxymax'
  status: string; max_users?: number | null; weight: number; server?: Pick<Server, 'id' | 'name' | 'status'>
  last_health_check_at?: string | null
}
interface ServerForm {
  name: string; host: string; ipv4: string; ipv6: string; ssh_port: number; ssh_username: string
  ssh_auth_type: 'key' | 'password'; ssh_private_key: string; ssh_password: string; ssh_host_fingerprint: string
}
interface NodeForm {
  proxy_server_id: number | undefined; name: string; region: string; country_code: string
  public_host: string; public_port: number; fake_tls_domain: string; engine: 'mtproxymax'
  status: string; max_users?: number; weight: number
}

const activeTab = ref('servers')
const servers = ref<Server[]>([])
const nodes = ref<Node[]>([])
const serverLoading = ref(false)
const nodeLoading = ref(false)
const submitting = ref(false)
const serverDialog = ref(false)
const nodeDialog = ref(false)
const editingServerId = ref<number | null>(null)
const editingNodeId = ref<number | null>(null)
const serverTotal = ref(0)
const nodeTotal = ref(0)
const serverPage = ref(1)
const nodePage = ref(1)
const serverFormRef = ref<FormInstance>()
const nodeFormRef = ref<FormInstance>()
const busyServerId = ref<number | null>(null)

const emptyServer = (): ServerForm => ({ name: '', host: '', ipv4: '', ipv6: '', ssh_port: 22, ssh_username: 'root', ssh_auth_type: 'key', ssh_private_key: '', ssh_password: '', ssh_host_fingerprint: '' })
const emptyNode = (): NodeForm => ({ proxy_server_id: undefined, name: '', region: '', country_code: 'US', public_host: '', public_port: 443, fake_tls_domain: '', engine: 'mtproxymax', status: 'pending', max_users: undefined, weight: 100 })
const serverForm = reactive<ServerForm>(emptyServer())
const nodeForm = reactive<NodeForm>(emptyNode())
const serverRules: FormRules<ServerForm> = {
  name: [{ required: true, message: '请输入服务器名称', trigger: 'blur' }],
  host: [{ required: true, message: '请输入 SSH 主机名或 IP', trigger: 'blur' }],
  ssh_port: [{ required: true, type: 'number', min: 1, max: 65535, message: '端口范围为 1–65535', trigger: 'blur' }],
  ssh_username: [{ required: true, message: '请输入 SSH 用户名', trigger: 'blur' }],
  ssh_host_fingerprint: [{ required: true, message: '请输入 SSH 主机指纹', trigger: 'blur' }],
}
const nodeRules: FormRules<NodeForm> = {
  proxy_server_id: [{ required: true, message: '请选择所属服务器', trigger: 'change' }],
  name: [{ required: true, message: '请输入节点名称', trigger: 'blur' }],
  region: [{ required: true, message: '请输入节点地区', trigger: 'blur' }],
  country_code: [{ required: true, len: 2, message: '请输入两位大写国家代码', trigger: 'blur' }],
  public_host: [{ required: true, message: '请输入公网地址', trigger: 'blur' }],
  public_port: [{ required: true, type: 'number', min: 1, max: 65535, message: '端口范围为 1–65535', trigger: 'blur' }],
}
const nodeStatuses = ['pending', 'deploying', 'online', 'offline', 'maintenance', 'error', 'disabled']

async function loadServers() {
  serverLoading.value = true
  try {
    const { data } = await api.get('/servers', { params: { page: serverPage.value } })
    servers.value = data.data
    serverTotal.value = data.total
  } catch (error) { ElMessage.error(apiErrorMessage(error, '服务器列表加载失败')) }
  finally { serverLoading.value = false }
}

async function loadNodes() {
  nodeLoading.value = true
  try {
    const { data } = await api.get('/nodes', { params: { page: nodePage.value } })
    nodes.value = data.data
    nodeTotal.value = data.total
  } catch (error) { ElMessage.error(apiErrorMessage(error, '节点列表加载失败')) }
  finally { nodeLoading.value = false }
}

function openServerCreate() {
  editingServerId.value = null
  Object.assign(serverForm, emptyServer())
  serverDialog.value = true
}

function openServerEdit(server: Server) {
  editingServerId.value = server.id
  Object.assign(serverForm, {
    name: server.name, host: server.host, ipv4: server.ipv4 || '', ipv6: server.ipv6 || '',
    ssh_port: Number(server.ssh_port), ssh_username: server.ssh_username, ssh_auth_type: server.ssh_auth_type,
    ssh_private_key: '', ssh_password: '', ssh_host_fingerprint: server.ssh_host_fingerprint,
  })
  serverDialog.value = true
}

async function submitServer() {
  if (!await serverFormRef.value?.validate().catch(() => false)) return
  const credential = serverForm.ssh_auth_type === 'key' ? serverForm.ssh_private_key : serverForm.ssh_password
  if (!editingServerId.value && !credential.trim()) {
    ElMessage.warning(serverForm.ssh_auth_type === 'key' ? '请粘贴 SSH 私钥' : '请输入 SSH 密码')
    return
  }
  const payload = {
    name: serverForm.name.trim(), host: serverForm.host.trim(), ipv4: serverForm.ipv4.trim() || null,
    ipv6: serverForm.ipv6.trim() || null, ssh_port: serverForm.ssh_port,
    ssh_username: serverForm.ssh_username.trim(), ssh_auth_type: serverForm.ssh_auth_type,
    ssh_private_key: serverForm.ssh_auth_type === 'key' ? serverForm.ssh_private_key.trim() || null : null,
    ssh_password: serverForm.ssh_auth_type === 'password' ? serverForm.ssh_password || null : null,
    ssh_host_fingerprint: serverForm.ssh_host_fingerprint.trim(),
  }
  submitting.value = true
  try {
    if (editingServerId.value) await api.put(`/servers/${editingServerId.value}`, payload)
    else await api.post('/servers', payload)
    ElMessage.success(editingServerId.value ? '服务器已更新' : '服务器已添加')
    serverDialog.value = false
    await loadServers()
  } catch (error) { ElMessage.error(apiErrorMessage(error)) }
  finally { submitting.value = false }
}

async function disableServer(server: Server) {
  try {
    await ElMessageBox.confirm(`确定停用服务器“${server.name}”吗？仍有活跃订阅时后端会拒绝操作。`, '停用服务器', { type: 'warning' })
    busyServerId.value = server.id
    await api.post(`/servers/${server.id}/disable`)
    ElMessage.success('服务器已停用')
    await loadServers()
  } catch (error) {
    if (error !== 'cancel' && error !== 'close') ElMessage.error(apiErrorMessage(error))
  } finally { busyServerId.value = null }
}

async function deployServer(server: Server) {
  try {
    await ElMessageBox.confirm(`将通过 SSH 在“${server.name}”上部署代理节点，是否继续？`, '部署节点', { type: 'warning', confirmButtonText: '开始部署' })
    busyServerId.value = server.id
    const { data } = await api.post(`/servers/${server.id}/deploy`)
    ElMessage.success(`部署任务 #${data.deployment_id} 已提交`)
    await Promise.all([loadServers(), loadNodes()])
  } catch (error) {
    if (error !== 'cancel' && error !== 'close') ElMessage.error(apiErrorMessage(error))
  } finally { busyServerId.value = null }
}

function openNodeCreate() {
  editingNodeId.value = null
  Object.assign(nodeForm, emptyNode())
  if (servers.value.length === 1) nodeForm.proxy_server_id = servers.value[0].id
  nodeDialog.value = true
}

function openNodeEdit(node: Node) {
  editingNodeId.value = node.id
  Object.assign(nodeForm, {
    proxy_server_id: Number(node.proxy_server_id), name: node.name, region: node.region,
    country_code: node.country_code, public_host: node.public_host, public_port: Number(node.public_port),
    fake_tls_domain: node.fake_tls_domain || '', engine: node.engine, status: node.status,
    max_users: node.max_users ?? undefined, weight: Number(node.weight),
  })
  nodeDialog.value = true
}

async function submitNode() {
  if (!await nodeFormRef.value?.validate().catch(() => false)) return
  const payload = {
    ...nodeForm,
    country_code: nodeForm.country_code.toUpperCase(),
    fake_tls_domain: nodeForm.fake_tls_domain.trim() || null,
    max_users: nodeForm.max_users || null,
  }
  submitting.value = true
  try {
    if (editingNodeId.value) await api.put(`/nodes/${editingNodeId.value}`, payload)
    else await api.post('/nodes', payload)
    ElMessage.success(editingNodeId.value ? '节点已更新' : '节点已创建')
    nodeDialog.value = false
    await loadNodes()
  } catch (error) { ElMessage.error(apiErrorMessage(error)) }
  finally { submitting.value = false }
}

onMounted(() => Promise.all([loadServers(), loadNodes()]))
</script>

<template>
  <div>
    <div class="toolbar"><div><h1 class="page-title">服务器与节点</h1><p class="muted">管理 SSH 服务器、代理节点与远程部署</p></div></div>
    <el-tabs v-model="activeTab" class="management-tabs">
      <el-tab-pane label="服务器" name="servers">
        <div class="tab-toolbar"><span>SSH 凭据加密保存，保存后不会再次显示</span><el-button type="primary" @click="openServerCreate">添加服务器</el-button></div>
        <section class="panel table-panel no-top-margin">
          <el-table v-loading="serverLoading" :data="servers">
            <el-table-column prop="name" label="名称" min-width="140" />
            <el-table-column prop="host" label="主机" min-width="150" />
            <el-table-column label="SSH" min-width="140"><template #default="s">{{ s.row.ssh_username }}@{{ s.row.ssh_port }}</template></el-table-column>
            <el-table-column label="认证" width="85"><template #default="s">{{ s.row.ssh_auth_type === 'key' ? '密钥' : '密码' }}</template></el-table-column>
            <el-table-column label="状态" width="100"><template #default="s"><el-tag :type="s.row.status === 'disabled' ? 'info' : s.row.status === 'error' ? 'danger' : 'success'">{{ s.row.status }}</el-tag></template></el-table-column>
            <el-table-column label="最后在线" width="175"><template #default="s">{{ formatDate(s.row.last_seen_at) }}</template></el-table-column>
            <el-table-column label="操作" width="220" fixed="right"><template #default="s"><el-button link type="primary" @click="openServerEdit(s.row)">编辑</el-button><el-button link type="success" :loading="busyServerId === s.row.id" :disabled="s.row.status === 'disabled'" @click="deployServer(s.row)">部署</el-button><el-button link type="danger" :disabled="s.row.status === 'disabled'" @click="disableServer(s.row)">停用</el-button></template></el-table-column>
            <template #empty><el-empty description="暂无服务器，请先添加" /></template>
          </el-table>
          <el-pagination v-if="serverTotal > 50" v-model:current-page="serverPage" layout="prev, pager, next, total" :page-size="50" :total="serverTotal" @current-change="loadServers" />
        </section>
      </el-tab-pane>
      <el-tab-pane label="代理节点" name="nodes">
        <div class="tab-toolbar"><span>部署服务器前，必须先为其创建代理节点</span><el-button type="primary" :disabled="!servers.length" @click="openNodeCreate">创建节点</el-button></div>
        <section class="panel table-panel no-top-margin">
          <el-table v-loading="nodeLoading" :data="nodes">
            <el-table-column prop="name" label="节点" min-width="130" />
            <el-table-column label="服务器" min-width="130"><template #default="s">{{ s.row.server?.name || `#${s.row.proxy_server_id}` }}</template></el-table-column>
            <el-table-column label="地区" width="120"><template #default="s">{{ s.row.country_code }} · {{ s.row.region }}</template></el-table-column>
            <el-table-column label="公网入口" min-width="180"><template #default="s">{{ s.row.public_host }}:{{ s.row.public_port }}</template></el-table-column>
            <el-table-column prop="weight" label="权重" width="75" />
            <el-table-column label="状态" width="105"><template #default="s"><el-tag :type="s.row.status === 'online' ? 'success' : s.row.status === 'error' ? 'danger' : 'warning'">{{ s.row.status }}</el-tag></template></el-table-column>
            <el-table-column label="健康检查" width="175"><template #default="s">{{ formatDate(s.row.last_health_check_at) }}</template></el-table-column>
            <el-table-column label="操作" width="90" fixed="right"><template #default="s"><el-button link type="primary" @click="openNodeEdit(s.row)">编辑</el-button></template></el-table-column>
            <template #empty><el-empty description="暂无代理节点" /></template>
          </el-table>
          <el-pagination v-if="nodeTotal > 100" v-model:current-page="nodePage" layout="prev, pager, next, total" :page-size="100" :total="nodeTotal" @current-change="loadNodes" />
        </section>
      </el-tab-pane>
    </el-tabs>

    <el-dialog v-model="serverDialog" :title="editingServerId ? '编辑服务器' : '添加服务器'" width="720px" destroy-on-close>
      <el-alert type="info" :closable="false" show-icon title="主机指纹用于防止连接到伪造服务器，可在目标服务器执行 ssh-keyscan 后核对。" />
      <el-form ref="serverFormRef" :model="serverForm" :rules="serverRules" label-width="115px" class="dialog-form">
        <div class="form-grid">
          <el-form-item label="服务器名称" prop="name"><el-input v-model="serverForm.name" /></el-form-item>
          <el-form-item label="SSH 主机" prop="host"><el-input v-model="serverForm.host" /></el-form-item>
          <el-form-item label="IPv4"><el-input v-model="serverForm.ipv4" placeholder="可选" /></el-form-item>
          <el-form-item label="IPv6"><el-input v-model="serverForm.ipv6" placeholder="可选" /></el-form-item>
          <el-form-item label="SSH 端口" prop="ssh_port"><el-input-number v-model="serverForm.ssh_port" :min="1" :max="65535" /></el-form-item>
          <el-form-item label="SSH 用户" prop="ssh_username"><el-input v-model="serverForm.ssh_username" /></el-form-item>
          <el-form-item label="认证方式"><el-radio-group v-model="serverForm.ssh_auth_type"><el-radio-button value="key">私钥</el-radio-button><el-radio-button value="password">密码</el-radio-button></el-radio-group></el-form-item>
          <el-form-item label="主机指纹" prop="ssh_host_fingerprint"><el-input v-model="serverForm.ssh_host_fingerprint" placeholder="SHA256:..." /></el-form-item>
        </div>
        <el-form-item v-if="serverForm.ssh_auth_type === 'key'" label="SSH 私钥"><el-input v-model="serverForm.ssh_private_key" type="textarea" :rows="6" :placeholder="editingServerId ? '留空表示不更换现有私钥' : '粘贴 OpenSSH 私钥'" /></el-form-item>
        <el-form-item v-else label="SSH 密码"><el-input v-model="serverForm.ssh_password" type="password" show-password :placeholder="editingServerId ? '留空表示不更换现有密码' : '请输入 SSH 密码'" /></el-form-item>
      </el-form>
      <template #footer><el-button @click="serverDialog = false">取消</el-button><el-button type="primary" :loading="submitting" @click="submitServer">保存</el-button></template>
    </el-dialog>

    <el-dialog v-model="nodeDialog" :title="editingNodeId ? '编辑代理节点' : '创建代理节点'" width="680px" destroy-on-close>
      <el-form ref="nodeFormRef" :model="nodeForm" :rules="nodeRules" label-width="115px">
        <div class="form-grid">
          <el-form-item label="所属服务器" prop="proxy_server_id"><el-select v-model="nodeForm.proxy_server_id" filterable><el-option v-for="server in servers" :key="server.id" :label="server.name" :value="server.id" :disabled="server.status === 'disabled'" /></el-select></el-form-item>
          <el-form-item label="节点名称" prop="name"><el-input v-model="nodeForm.name" /></el-form-item>
          <el-form-item label="地区" prop="region"><el-input v-model="nodeForm.region" placeholder="例如 Hong Kong" /></el-form-item>
          <el-form-item label="国家代码" prop="country_code"><el-input v-model="nodeForm.country_code" maxlength="2" @input="nodeForm.country_code = nodeForm.country_code.toUpperCase()" /></el-form-item>
          <el-form-item label="公网地址" prop="public_host"><el-input v-model="nodeForm.public_host" /></el-form-item>
          <el-form-item label="公网端口" prop="public_port"><el-input-number v-model="nodeForm.public_port" :min="1" :max="65535" /></el-form-item>
          <el-form-item label="Fake TLS 域名"><el-input v-model="nodeForm.fake_tls_domain" placeholder="可选，例如 www.example.com" /></el-form-item>
          <el-form-item label="代理引擎"><el-select v-model="nodeForm.engine"><el-option label="MTProxyMax" value="mtproxymax" /></el-select></el-form-item>
          <el-form-item label="节点状态"><el-select v-model="nodeForm.status"><el-option v-for="status in nodeStatuses" :key="status" :label="status" :value="status" /></el-select></el-form-item>
          <el-form-item label="最大用户数"><el-input-number v-model="nodeForm.max_users" :min="1" placeholder="不限" /></el-form-item>
          <el-form-item label="调度权重"><el-input-number v-model="nodeForm.weight" :min="1" :max="1000" /></el-form-item>
        </div>
      </el-form>
      <template #footer><el-button @click="nodeDialog = false">取消</el-button><el-button type="primary" :loading="submitting" @click="submitNode">保存</el-button></template>
    </el-dialog>
  </div>
</template>
