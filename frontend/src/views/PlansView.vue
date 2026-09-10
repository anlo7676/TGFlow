<script setup lang="ts">
import { onMounted, ref } from 'vue'; import { api } from '../api'
interface Plan { id:number; name:string; price_cents:number; currency:string; traffic_bytes:number; duration_days:number; enabled:boolean }
const rows = ref<Plan[]>([]); async function load(){ rows.value=(await api.get('/plans')).data.data } onMounted(load)
</script>
<template><div><div class="toolbar"><div><h1 class="page-title">套餐管理</h1><p class="muted">价格、流量、有效期与节点组</p></div><el-button type="primary">新建套餐</el-button></div><section class="panel"><el-table :data="rows"><el-table-column prop="name" label="套餐"/><el-table-column label="价格"><template #default="s">{{ s.row.currency }} {{ (s.row.price_cents/100).toFixed(2) }}</template></el-table-column><el-table-column label="流量"><template #default="s">{{ (s.row.traffic_bytes/1073741824).toFixed(0) }} GB</template></el-table-column><el-table-column prop="duration_days" label="有效期（天）"/><el-table-column label="状态"><template #default="s"><el-tag :type="s.row.enabled?'success':'info'">{{ s.row.enabled?'在售':'隐藏' }}</el-tag></template></el-table-column></el-table></section></div></template>
