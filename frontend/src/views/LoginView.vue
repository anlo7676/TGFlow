<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { api } from '../api'
const router = useRouter(); const loading = ref(false); const form = reactive({ username: '', password: '', totp_code: '' })
async function login() { loading.value = true; try { const { data } = await api.post('/login', form); sessionStorage.setItem('admin_token', data.token); router.push('/') } catch { ElMessage.error('登录失败，请检查账号信息') } finally { loading.value = false } }
</script>
<template><main class="login-page"><section class="login-card"><div class="brand"><span class="brand-mark">TG</span><div><b style="color:#182230">TGFlow</b><small>商业代理管理平台</small></div></div><h1>管理员登录</h1><p class="muted">使用受授权的管理账号继续</p><el-form @submit.prevent="login"><el-form-item><el-input v-model="form.username" size="large" placeholder="用户名" /></el-form-item><el-form-item><el-input v-model="form.password" size="large" type="password" show-password placeholder="密码" /></el-form-item><el-form-item><el-input v-model="form.totp_code" size="large" inputmode="numeric" maxlength="6" placeholder="两步验证码" /></el-form-item><el-button type="primary" size="large" native-type="submit" :loading="loading" style="width:100%">安全登录</el-button></el-form></section></main></template>
