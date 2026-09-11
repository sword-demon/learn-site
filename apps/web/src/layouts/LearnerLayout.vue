<template>
  <div class="campus">
    <header class="learner-header">
      <div class="learner-header__inner">
        <router-link to="/" class="learner-brand" aria-label="拾阶学社">拾阶学社</router-link>
        <nav class="learner-nav" aria-label="主导航">
          <router-link to="/" exact-active-class="on">首页</router-link>
          <router-link to="/maps" active-class="on">学习地图</router-link>
          <router-link to="/me/learning" :class="{ on: isPersonal }">我的学习</router-link>
        </nav>
        <div class="learner-tools">
          <el-button
            text
            circle
            :icon="isNight ? Sunny : Moon"
            :title="isNight ? '切换日间模式' : '切换夜读模式'"
            :aria-label="isNight ? '切换日间模式' : '切换夜读模式'"
            :aria-pressed="isNight"
            @click="toggleNight"
          />
          <template v-if="session.loggedIn">
            <router-link
              to="/me/messages"
              class="notification-link"
              aria-label="消息中心"
              title="消息中心"
            >
              <el-icon :size="21"><Bell /></el-icon>
              <span v-if="unreadCount > 0" class="notification-count">{{
                unreadCount > 99 ? '99+' : unreadCount
              }}</span>
            </router-link>
            <el-dropdown trigger="click" placement="bottom-end" @command="onUserMenu">
              <el-button text class="account-trigger" :aria-label="`账户菜单：${displayName}`">
                <img
                  v-if="profile?.avatar_url"
                  :src="profile.avatar_url"
                  alt=""
                  class="account-trigger__photo"
                />
                <el-icon v-else :size="20"><User /></el-icon>
                <span>{{ displayName }}</span>
                <el-icon><ArrowDown /></el-icon>
              </el-button>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item command="learning" :icon="Reading">我的学习</el-dropdown-item>
                  <el-dropdown-item command="account" :icon="Setting">账户设置</el-dropdown-item>
                  <el-dropdown-item command="orders" :icon="Tickets">我的订单</el-dropdown-item>
                  <el-dropdown-item command="logout" :icon="SwitchButton" divided
                    >退出登录</el-dropdown-item
                  >
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </template>
          <router-link v-else to="/login" class="btn btn-primary btn-sm">登录 / 注册</router-link>
        </div>
      </div>
    </header>
    <div v-if="isPersonal && session.loggedIn" class="personal-layout">
      <aside class="personal-sidebar">
        <p class="personal-sidebar__title">个人中心</p>
        <nav aria-label="个人中心导航">
          <router-link
            v-for="item in personalLinks"
            :key="item.path"
            :to="item.path"
            active-class="on"
          >
            <el-icon :size="18"><component :is="item.icon" /></el-icon>
            {{ item.label }}
            <span v-if="item.path === '/me/messages' && unreadCount > 0" class="personal-unread">{{
              unreadCount
            }}</span>
          </router-link>
        </nav>
      </aside>
      <div class="personal-content"><router-view /></div>
    </div>
    <router-view v-else />
    <DailyCheckinDialog
      v-if="session.loggedIn"
      v-model="checkinDialogVisible"
      @dismiss="dismissCheckinPrompt"
      @success="onCheckinSuccess"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, provide, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { storeToRefs } from 'pinia';
import {
  ArrowDown,
  Bell,
  Calendar,
  Key,
  Moon,
  Reading,
  Setting,
  Star,
  Sunny,
  SwitchButton,
  Tickets,
  User,
  Share,
  Wallet,
} from '@element-plus/icons-vue';
import DailyCheckinDialog from '@/components/DailyCheckinDialog.vue';
import { useLoginFamilyStore } from '@/api/login';
import { useDailyCheckinPrompt } from '@/composables/useDailyCheckinPrompt';
import { usePushNotifications } from '@/composables/usePushNotifications';
import { useTheme } from '@/composables/useTheme';
import { useLearnerProfileStore } from '@/stores/learnerProfile';
import { fetchDistributionStatus } from '@/api/distribution';

const session = useLoginFamilyStore();
const router = useRouter();
const route = useRoute();
const isPersonal = computed(() => route.path.startsWith('/me/'));
const { unreadCount } = usePushNotifications();
const { isNight, toggleNight } = useTheme();
const profileStore = useLearnerProfileStore();
profileStore.ensureSessionWatch();
const { displayName, profile } = storeToRefs(profileStore);
const checkinPrompt = useDailyCheckinPrompt();
const {
  dialogVisible: checkinDialogVisible,
  dismissForSession: dismissCheckinPrompt,
  onCheckinSuccess,
} = checkinPrompt;
provide('dailyCheckinPrompt', checkinPrompt);

const distributionEnabled = ref(false);
async function loadDistributionFlag(): Promise<void> {
  if (!session.loggedIn) {
    distributionEnabled.value = false;
    return;
  }
  try {
    const status = await fetchDistributionStatus();
    distributionEnabled.value = status.enabled;
  } catch {
    distributionEnabled.value = false;
  }
}
onMounted(() => {
  void loadDistributionFlag();
});
watch(
  () => session.loggedIn,
  () => {
    void loadDistributionFlag();
  },
);

const personalLinks = computed(() => {
  const links = [
    { path: '/me/learning', label: '我的学习', icon: Reading },
    { path: '/me/favorites', label: '我的收藏', icon: Star },
    { path: '/me/orders', label: '我的订单', icon: Tickets },
    { path: '/me/messages', label: '消息中心', icon: Bell },
    { path: '/me/checkins', label: '每日签到', icon: Calendar },
    { path: '/me/coupons', label: '优惠券', icon: Wallet },
    { path: '/me/redeem', label: '激活码兑换', icon: Key },
    { path: '/me/account', label: '账户设置', icon: Setting },
  ];
  if (distributionEnabled.value) {
    links.splice(6, 0, { path: '/me/distribution', label: '我的分销', icon: Share });
  }
  return links;
});

async function onUserMenu(command: string): Promise<void> {
  if (command === 'logout') {
    await session.signOut();
    await router.push('/');
    return;
  }
  const target = personalLinks.value.find((item) => item.path === `/me/${command}`);
  if (target) await router.push(target.path);
}
</script>

<style scoped>
.learner-header {
  position: sticky;
  top: 0;
  z-index: 30;
  background: var(--card);
  border-bottom: 1px solid var(--line);
}
.learner-header__inner {
  max-width: 1440px;
  height: 72px;
  margin: auto;
  padding: 0 32px;
  display: flex;
  align-items: center;
  gap: 48px;
}
.learner-brand {
  color: var(--ink);
  font-size: 24px;
  font-weight: 800;
  white-space: nowrap;
}
.learner-nav {
  display: flex;
  gap: 32px;
  height: 100%;
  align-items: center;
}
.learner-nav a {
  display: flex;
  align-items: center;
  height: 100%;
  color: var(--ink-2);
  border-bottom: 2px solid transparent;
  font-size: 15px;
  white-space: nowrap;
}
.learner-nav a.on {
  color: var(--seal);
  border-color: var(--seal);
  font-weight: 600;
}
.learner-tools {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 16px;
}
.notification-link {
  display: flex;
  padding: 8px;
  position: relative;
  color: var(--ink-2);
}
.notification-count {
  position: absolute;
  top: -4px;
  right: -8px;
  padding: 0 4px;
  border-radius: 4px;
  background: var(--gold-soft);
  color: var(--gold);
  font-size: 11px;
}
.account-trigger {
  gap: 8px;
  color: var(--ink);
}
.account-trigger__photo {
  width: 20px;
  height: 20px;
  border-radius: 4px;
  object-fit: cover;
  display: block;
}
.account-trigger :deep(.el-button__text) {
  display: flex;
  align-items: center;
  gap: 8px;
}
.account-trigger :deep(span span) {
  max-width: 130px;
  overflow: hidden;
  text-overflow: ellipsis;
}
.personal-layout {
  width: min(1440px, 100%);
  margin: 0 auto;
  padding: 40px 32px 64px;
  display: grid;
  grid-template-columns: 192px minmax(0, 1fr);
  gap: 36px;
}
.personal-sidebar {
  border-right: 1px solid var(--line);
  padding-right: 20px;
}
.personal-sidebar__title {
  margin: 0 0 24px 12px;
  color: var(--ink-3);
  font-size: 12px;
}
.personal-sidebar nav {
  display: grid;
  gap: 6px;
}
.personal-sidebar a {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px;
  border-radius: 6px;
  color: var(--ink-2);
  white-space: nowrap;
}
.personal-sidebar a:hover {
  background: var(--paper-2);
}
.personal-sidebar a.on {
  background: var(--seal-soft);
  color: var(--seal);
  font-weight: 600;
}
.personal-unread {
  margin-left: auto;
  font-size: 12px;
}
.personal-content {
  min-width: 0;
}
.personal-content :deep(.page) {
  width: 100%;
  max-width: none;
  margin: 0;
  padding: 0;
}
.personal-content :deep(.display),
.personal-content :deep(.page-head h1) {
  font-size: 28px;
  color: var(--ink);
}
</style>
