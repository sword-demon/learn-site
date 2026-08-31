<script setup lang="ts">
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { useLoginFamilyStore } from '@/api/login';

const router = useRouter();
const session = useLoginFamilyStore();

// ponytail: derive logged-in state from session reactive — H5
const loggedIn = computed(() => session.loggedIn);

function goLogin(): void {
  void router.push('/login');
}

function goRegister(): void {
  void router.push('/register');
}
</script>

<template>
  <header class="page-header">
    <div class="page-header-inner">
      <router-link to="/" class="logo">拾阶学社</router-link>

      <nav class="primary-nav" aria-label="主导航">
        <router-link to="/" class="nav-link">首页</router-link>
        <router-link to="/maps" class="nav-link">学习地图</router-link>
        <router-link to="/me/learning" class="nav-link">我的学习</router-link>
      </nav>

      <div class="search-box">
        <span class="search-label" aria-hidden="true">搜索</span>
        <input type="search" placeholder="课程、地图…" aria-label="搜索" class="search-input" />
      </div>

      <div v-if="loggedIn" class="user-actions">
        <router-link to="/me/account" class="user-link">个人中心</router-link>
      </div>
      <div v-else class="user-actions">
        <button type="button" class="login-btn" @click="goLogin">登录</button>
        <button type="button" class="register-btn" @click="goRegister">注册</button>
      </div>
    </div>
  </header>
</template>

<style scoped>
.page-header {
  background: #fff;
  border-bottom: 1px solid #ebeef5;
  position: sticky;
  top: 0;
  z-index: 100;
}
.page-header-inner {
  display: flex;
  align-items: center;
  gap: 24px;
  max-width: 1200px;
  margin: 0 auto;
  padding: 12px 24px;
}
.logo {
  font-size: 20px;
  font-weight: 700;
  color: #303133;
  text-decoration: none;
  flex-shrink: 0;
}
.primary-nav {
  display: flex;
  gap: 16px;
}
.nav-link {
  color: #606266;
  text-decoration: none;
  font-size: 14px;
  padding: 4px 0;
}
.nav-link.router-link-active {
  color: #409eff;
}
.search-box {
  flex: 1;
  max-width: 320px;
  position: relative;
}
.search-label {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 12px;
  color: #909399;
  pointer-events: none;
}
.search-input {
  width: 100%;
  padding: 8px 12px 8px 48px;
  border: 1px solid #dcdfe6;
  border-radius: 4px;
  font-size: 14px;
  outline: none;
}
.search-input:focus {
  border-color: #409eff;
}
.user-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-left: auto;
}
.user-link {
  color: #606266;
  text-decoration: none;
  font-size: 14px;
}
.login-btn,
.register-btn {
  padding: 6px 14px;
  border-radius: 4px;
  font-size: 14px;
  cursor: pointer;
  border: 1px solid transparent;
}
.login-btn {
  background: #409eff;
  color: #fff;
}
.register-btn {
  background: #fff;
  color: #409eff;
  border-color: #409eff;
}
</style>
