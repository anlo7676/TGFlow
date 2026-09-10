<script setup lang="ts">
import { onMounted, ref } from 'vue'; import { api } from '../api'
const stats = ref<Record<string, number>>({}); onMounted(async () => { stats.value = (await api.get('/dashboard')).data })
const formatBytes = (n = 0) => `${(n / 1073741824).toFixed(2)} GB`
</script>
<template><div><h1 class="page-title">运营总览</h1><p class="muted">实时掌握订阅、节点、收入与流量</p><div class="stats"><div class="stat"><span>注册用户</span><strong>{{ stats.users || 0 }}</strong></div><div class="stat"><span>活跃订阅</span><strong>{{ stats.active_subscriptions || 0 }}</strong></div><div class="stat"><span>在线节点</span><strong>{{ stats.online_nodes || 0 }} / {{ stats.nodes || 0 }}</strong></div><div class="stat"><span>今日收入</span><strong>¥{{ ((stats.today_revenue_cents || 0) / 100).toFixed(2) }}</strong></div></div><section class="panel"><h3>今日代理流量</h3><strong style="font-size:32px">{{ formatBytes(stats.today_traffic_bytes) }}</strong></section></div></template>
