<script setup lang="ts">
import { onMounted, ref } from 'vue'; import { api } from '../api'
const rows=ref<any[]>([]); async function load(){ rows.value=(await api.get('/servers')).data.data } onMounted(load)
</script>
<template><div><div class="toolbar"><div><h1 class="page-title">服务器与节点</h1><p class="muted">SSH 凭据始终加密保存且不会再次显示</p></div><el-button type="primary">添加服务器</el-button></div><section class="panel"><el-table :data="rows"><el-table-column prop="name" label="名称"/><el-table-column prop="host" label="主机"/><el-table-column prop="ssh_username" label="SSH 用户"/><el-table-column label="状态"><template #default="s"><el-tag>{{ s.row.status }}</el-tag></template></el-table-column><el-table-column label="操作"><template #default="s"><el-button size="small" @click="api.post(`/servers/${s.row.id}/deploy`)">部署</el-button></template></el-table-column></el-table></section></div></template>
