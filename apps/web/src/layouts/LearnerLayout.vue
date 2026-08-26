<template>
  <div class="campus">
    <header class="topnav">
      <router-link to="/" class="brand">
        <span class="latin">Linjian</span>
        <strong class="display">林间课室</strong>
      </router-link>
      <nav>
        <router-link to="/" active-class="" exact-active-class="on">分类</router-link>
        <router-link to="/maps" active-class="on">学习地图</router-link>
        <template v-if="session.loggedIn">
          <router-link to="/me/learning" active-class="on">我的学习</router-link>
          <router-link to="/me/favorites" active-class="on">收藏</router-link>
          <router-link to="/me/orders" active-class="on">我的订单</router-link>
          <router-link to="/me/messages" active-class="on">消息</router-link>
          <button type="button" class="btn ghost" @click="onLogout">退出</button>
        </template>
        <router-link v-else to="/login" class="btn ghost login-cta">登录</router-link>
      </nav>
    </header>
    <router-view />
  </div>
</template>

<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useLoginFamilyStore } from '@/api/login'

const session = useLoginFamilyStore()
const router = useRouter()

async function onLogout(): Promise<void> {
  await session.signOut()
  await router.push('/')
}
</script>
