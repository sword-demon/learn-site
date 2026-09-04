<!-- Build timestamp: 20260829180000 -->
<template>
  <el-container class="admin-shell">
    <el-aside :width="collapsed ? '64px' : '220px'" class="aside">
      <div class="brand">
        <span v-if="!collapsed">学习平台 · 管理端</span>
        <span v-else>管理</span>
      </div>
      <el-menu
        :default-active="active"
        :collapse="collapsed"
        router
        class="menu"
        background-color="#0f172a"
        text-color="#cbd5e1"
        active-text-color="#38bdf8"
      >
        <template v-for="entry in entries" :key="entry.path">
          <el-sub-menu
            v-if="'children' in entry && Array.isArray(entry.children) && entry.children.length"
            :index="entry.path"
          >
            <template #title>
              <el-icon><component :is="iconFor(entry.path)" /></el-icon>
              <span>{{ entry.label }}</span>
            </template>
            <el-menu-item v-for="child in entry.children" :key="child.path" :index="child.path">
              <el-icon><component :is="iconFor(child.path)" /></el-icon>
              <template #title>{{ child.label }}</template>
            </el-menu-item>
          </el-sub-menu>
          <el-menu-item v-else :index="entry.path">
            <el-icon><component :is="iconFor(entry.path)" /></el-icon>
            <template #title>{{ entry.label }}</template>
          </el-menu-item>
        </template>
      </el-menu>
    </el-aside>
    <el-container>
      <el-header class="header">
        <el-button text @click="collapsed = !collapsed">
          <el-icon><component :is="collapsed ? Expand : Fold" /></el-icon>
        </el-button>
        <div class="spacer" />
        <el-dropdown @command="onCommand">
          <span class="user">
            <el-icon><User /></el-icon>
            <span>{{ label }}</span>
          </span>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="logout"> 退出登录 </el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </el-header>
      <div class="nav-chrome">
        <AdminTabBar />
        <AdminBreadcrumb />
      </div>
      <el-main class="main">
        <router-view v-slot="{ Component: ViewComponent, route: viewRoute }">
          <keep-alive :max="20">
            <component :is="ViewComponent" :key="viewRoute.fullPath" />
          </keep-alive>
        </router-view>
      </el-main>
    </el-container>
  </el-container>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { Component } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  ChatLineSquare,
  Expand,
  Files,
  Fold,
  Folder,
  Management,
  MapLocation,
  Message,
  Monitor,
  Notebook,
  OfficeBuilding,
  Picture,
  Reading,
  ShoppingCart,
  User,
} from '@element-plus/icons-vue';
import { http, clearTokens, permissionCodes, staffAccount } from '@/api/http';
import { visibleEntries, type AdminMenuEntry } from '@/layouts/AdminMenu';
import AdminTabBar from '@/components/AdminTabBar.vue';
import AdminBreadcrumb from '@/components/AdminBreadcrumb.vue';
import { useTabsStore } from '@/stores/tabs';
import { shouldTrackTab } from '@/router/tabSync';

const route = useRoute();
const router = useRouter();
const tabsStore = useTabsStore();
const collapsed = ref(false);

const menuIcons: Record<string, Component> = {
  '/': Monitor,
  '/categories': Folder,
  '/courses': Reading,
  '/qa': Message,
  '/reviews': ChatLineSquare,
  '/maps': MapLocation,
  '/orders': ShoppingCart,
  '/learners': User,
  '/site/profile': OfficeBuilding,
  '/site/payment-config': ShoppingCart,
  '/site/payment-whitelist': ShoppingCart,
  '/site/audit': Files,
  '/org': OfficeBuilding,
  '/org/departments': Folder,
  '/org/posts': Notebook,
  '/org/roles': Management,
  '/org/staff': User,
  '/banners': Picture,
};

function iconFor(path: string): Component {
  return menuIcons[path] ?? Files;
}

const entries = computed<AdminMenuEntry[]>(() => visibleEntries(permissionCodes()));

const active = computed(() => {
  const p = route.path;
  let best = '/';
  let bestLen = -1;
  for (const e of entries.value) {
    if ('children' in e && e.children) {
      for (const c of e.children) {
        if ((p === c.path || p.startsWith(c.path + '/')) && c.path.length > bestLen) {
          best = c.path;
          bestLen = c.path.length;
        }
      }
    } else if (p === e.path || p.startsWith(e.path + '/')) {
      if (e.path.length > bestLen) {
        best = e.path;
        bestLen = e.path.length;
      }
    }
  }
  return best;
});

const label = computed(() => staffAccount() ?? '管理员');

watch(
  () => route.fullPath,
  () => {
    if (shouldTrackTab(route)) {
      tabsStore.syncFromRoute(route);
    }
  },
  { immediate: true },
);

async function onCommand(cmd: string): Promise<void> {
  if (cmd !== 'logout') return;
  try {
    await ElMessageBox.confirm('确定退出登录吗？', '退出', { type: 'warning' });
  } catch {
    return;
  }
  try {
    await http.post('/auth/logout');
  } catch {
    // ponytail: best-effort; token revoked on server next refresh.
  }
  tabsStore.reset();
  clearTokens();
  ElMessage.success('已退出');
  await router.push('/login');
}
</script>

<style scoped>
.admin-shell {
  min-height: 100vh;
}
.aside {
  background: #0f172a;
  color: #cbd5e1;
  transition: width 0.2s ease;
  overflow-x: hidden;
}
.brand {
  height: 56px;
  display: flex;
  align-items: center;
  padding: 0 16px;
  font-weight: 600;
  color: #f8fafc;
  border-bottom: 1px solid #1e293b;
}
.menu {
  border-right: none;
}
.header {
  display: flex;
  align-items: center;
  background: #ffffff;
  border-bottom: 1px solid #e2e8f0;
  padding: 0 16px;
}
.nav-chrome {
  background: #ffffff;
}
.spacer {
  flex: 1 1 auto;
}
.user {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  padding: 6px 10px;
  border-radius: 6px;
}
.user:hover {
  background: #f1f5f9;
}
.main {
  background: #f8fafc;
  padding: 24px;
}
</style>
