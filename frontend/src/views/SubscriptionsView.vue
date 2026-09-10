<script setup lang="ts">
import { onMounted, ref } from 'vue'; import { api } from '../api'
const rows=ref<any[]>([]); async function load(){ rows.value=(await api.get('/subscriptions')).data.data } onMounted(load)
const bytes=(n:number)=>`${(Math.max(0,n)/1073741824).toFixed(2)} GB`
</script>
<template><div><h1 class="page-title">订阅管理</h1><p class="muted">中央订阅是流量与有效期的唯一总账</p><section class="panel"><el-table :data="rows"><el-table-column prop="id" label="ID" width="80"/><el-table-column prop="user_id" label="用户"/><el-table-column label="已用 / 总量"><template #default="s">{{ bytes(s.row.traffic_used_bytes) }} / {{ bytes(Number(s.row.traffic_limit_bytes)+Number(s.row.bonus_traffic_bytes)) }}</template></el-table-column><el-table-column prop="expires_at" label="到期时间"/><el-table-column label="状态"><template #default="s"><el-tag :type="s.row.status==='active'?'success':'warning'">{{ s.row.status }}</el-tag></template></el-table-column></el-table></section></div></template>
