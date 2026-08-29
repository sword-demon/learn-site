<template>
  <div class="campus">
    <header class="topnav">
      <div class="topnav-inner">
        <router-link to="/" class="brand">
          <span class="latin">Linjian</span>
          <strong class="display">林间课室</strong>
        </router-link>
        <button
          type="button"
          class="nav-toggle"
          :aria-expanded="menuOpen"
          aria-controls="learner-navigation"
          @click="menuOpen = !menuOpen"
        >
          {{ menuOpen ? '收起' : '菜单' }}
        </button>
        <nav id="learner-navigation" :class="{ open: menuOpen }" @click="menuOpen = false">
          <router-link to="/" active-class="" exact-active-class="on">分类</router-link>
          <router-link to="/maps" active-class="on">学习地图</router-link>
          <template v-if="session.loggedIn">
            <router-link to="/me/learning" active-class="on">我的学习</router-link>
            <router-link to="/me/favorites" active-class="on">收藏</router-link>
            <router-link to="/me/orders" active-class="on">我的订单</router-link>
            <router-link to="/me/messages" active-class="on" class="messages-link">
              消息
              <span v-if="unreadCount > 0" class="badge">{{
                unreadCount > 99 ? '99+' : unreadCount
              }}</span>
            </router-link>
            <router-link to="/me/account" active-class="on">账户</router-link>
            <button type="button" class="btn ghost" @click="onLogout">退出</button>
          </template>
          <router-link v-else to="/login" class="btn ghost login-cta">登录</router-link>
        </nav>
      </div>
    </header>
    <router-view />
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useLoginFamilyStore } from '@/api/login';
import { usePushNotifications } from '@/composables/usePushNotifications';

const session = useLoginFamilyStore();
const router = useRouter();
const menuOpen = ref(false);
const { unreadCount } = usePushNotifications();

async function onLogout(): Promise<void> {
  await session.signOut();
  await router.push('/');
}
</script>

<style scoped>
.messages-link {
  position: relative;
}
.badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  margin-left: 6px;
  padding: 0 5px;
  border-radius: 999px;
  background: var(--accent);
  color: #fff;
  font: 600 0.65rem var(--font-mono);
  line-height: 1;
}
</style>
