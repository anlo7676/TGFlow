import { createRouter, createWebHistory } from 'vue-router'
import LoginView from './views/LoginView.vue'
import DashboardView from './views/DashboardView.vue'
import PlansView from './views/PlansView.vue'
import ServersView from './views/ServersView.vue'
import SubscriptionsView from './views/SubscriptionsView.vue'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', component: LoginView, meta: { public: true } },
    { path: '/', component: DashboardView },
    { path: '/plans', component: PlansView },
    { path: '/servers', component: ServersView },
    { path: '/subscriptions', component: SubscriptionsView },
  ],
})

router.beforeEach((to) => {
  if (!to.meta.public && !sessionStorage.getItem('admin_token')) return '/login'
})
