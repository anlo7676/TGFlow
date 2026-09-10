<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from './api'

const route = useRoute()
const router = useRouter()
const loggedIn = computed(() => !!sessionStorage.getItem('admin_token') && route.path !== '/login')
async function logout() {
  try { await api.post('/logout') } finally { sessionStorage.removeItem('admin_token'); router.push('/login') }
}
</script>

<template>
  <el-container v-if="loggedIn" class="shell">
    <el-aside width="236px">
      <div class="brand"><span class="brand-mark">TG</span><div><b>TGFlow</b><small>Proxy Control</small></div></div>
      <el-menu router :default-active="route.path">
        <el-menu-item index="/">总览</el-menu-item>
        <el-menu-item index="/servers">服务器与节点</el-menu-item>
        <el-menu-item index="/plans">套餐管理</el-menu-item>
        <el-menu-item index="/subscriptions">订阅管理</el-menu-item>
      </el-menu>
      <button class="logout" @click="logout">退出登录</button>
    </el-aside>
    <el-main><router-view /></el-main>
  </el-container>
  <router-view v-else />
</template>
