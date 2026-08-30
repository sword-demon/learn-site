<template>
  <div class="campus">
    <header class="masthead">
      <div class="masthead-inner">
        <div class="masthead-bar">
          <router-link to="/" class="brand" aria-label="拾阶学社">
            <div class="seal-mark" aria-hidden="true">
              <span>拾</span><span>阶</span><span>学</span><span>社</span>
            </div>
            <div class="brand-txt">
              <h1>拾阶学社</h1>
              <p>拾级而上 · 日进一阶</p>
            </div>
          </router-link>

          <div class="masthead-tools">
            <el-button
              circle
              class="btn-night"
              :icon="isNight ? Sunny : Moon"
              :title="isNight ? '切换日间模式' : '夜读模式'"
              :aria-label="isNight ? '切换日间模式' : '切换夜读模式'"
              :aria-pressed="isNight"
              @click="toggleNight"
            />
            <template v-if="session.loggedIn">
              <router-link to="/me/account" class="chip-user" :title="displayName">
                <span class="avatar">{{ userInitial }}</span>
                <span class="chip-user-name">{{ displayName }}</span>
              </router-link>
              <el-button
                size="small"
                class="btn-logout"
                :icon="SwitchButton"
                aria-label="退出"
                @click="onLogout"
              >
                <span class="btn-logout-label">退出</span>
              </el-button>
            </template>
            <router-link v-else to="/login" class="btn btn-primary btn-sm">登录 / 注册</router-link>
          </div>

          <el-button
            class="nav-toggle"
            :icon="Menu"
            :aria-expanded="menuOpen"
            aria-controls="learner-navigation"
            @click="menuOpen = !menuOpen"
          >
            {{ menuOpen ? '收起' : '菜单' }}
          </el-button>
        </div>

        <nav
          id="learner-navigation"
          class="mainnav"
          :class="{ open: menuOpen }"
          aria-label="主导航"
          @click="menuOpen = false"
        >
          <router-link to="/" active-class="on" exact-active-class="on">首页</router-link>
          <router-link to="/maps" active-class="on">学习地图</router-link>
          <template v-if="session.loggedIn">
            <router-link to="/me/learning" active-class="on">我的学习</router-link>
            <router-link to="/me/favorites" active-class="on">收藏</router-link>
            <router-link to="/me/orders" active-class="on">我的订单</router-link>
            <router-link to="/me/messages" active-class="on">
              消息
              <span v-if="unreadCount > 0" class="nav-badge">{{
                unreadCount > 99 ? '99+' : unreadCount
              }}</span>
            </router-link>
            <router-link to="/me/checkins" active-class="on">每日签到</router-link>
          </template>
        </nav>
      </div>
    </header>
    <router-view />
    <DailyCheckinDialog
      v-if="session.loggedIn"
      v-model="checkinDialogVisible"
      @dismiss="dismissCheckinPrompt"
      @success="onCheckinSuccess"
    />
  </div>
</template>

<script setup lang="ts">
import { provide, ref } from 'vue';
import { useRouter } from 'vue-router';
import { storeToRefs } from 'pinia';
import { Menu, Moon, Sunny, SwitchButton } from '@element-plus/icons-vue';
import DailyCheckinDialog from '@/components/DailyCheckinDialog.vue';
import { useLoginFamilyStore } from '@/api/login';
import { useDailyCheckinPrompt } from '@/composables/useDailyCheckinPrompt';
import { usePushNotifications } from '@/composables/usePushNotifications';
import { useTheme } from '@/composables/useTheme';
import { useLearnerProfileStore } from '@/stores/learnerProfile';

const session = useLoginFamilyStore();
const router = useRouter();
const menuOpen = ref(false);
const { unreadCount } = usePushNotifications();
const { isNight, toggleNight } = useTheme();
const profileStore = useLearnerProfileStore();
profileStore.ensureSessionWatch();
const { displayName, userInitial } = storeToRefs(profileStore);

const checkinPrompt = useDailyCheckinPrompt();
const {
  dialogVisible: checkinDialogVisible,
  dismissForSession: dismissCheckinPrompt,
  onCheckinSuccess,
} = checkinPrompt;

provide('dailyCheckinPrompt', checkinPrompt);

async function onLogout(): Promise<void> {
  await session.signOut();
  await router.push('/');
}
</script>
