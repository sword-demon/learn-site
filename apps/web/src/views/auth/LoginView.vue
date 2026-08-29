<template>
  <div class="campus auth-page">
    <header class="topnav">
      <router-link to="/" class="brand">
        <span class="latin">Linjian</span>
        <strong class="display">林间课室</strong>
      </router-link>
    </header>
    <main class="auth-shell">
      <aside class="auth-aside">
        <p class="eyebrow"><span class="eyebrow-rule" />林间课室 · 学员入口</p>
        <p class="auth-index latin">READ / 01</p>
        <h1 class="display">从上次停下的地方，继续读下去。</h1>
        <p>课程、学习地图和进度记录都会留在你的个人书架里。</p>
      </aside>
      <section class="auth-card">
        <p class="badge">学员登录</p>
        <h2 class="display">用手机号进入课室</h2>
        <p class="lede">登录需要手机号、密码和一次图形验证码。</p>
        <el-form :model="form" @submit.prevent="onSubmit">
          <label class="field">
            手机号
            <el-input
              v-model="form.phone"
              maxlength="11"
              autocomplete="username"
              placeholder="11 位大陆手机号"
            />
          </label>
          <label class="field">
            密码
            <el-input
              v-model="form.password"
              type="password"
              autocomplete="current-password"
              show-password
              placeholder="8–72 位"
            />
          </label>
          <label class="field">
            图形验证码
            <div class="captcha-row">
              <el-input
                v-model="form.captcha_answer"
                maxlength="8"
                autocomplete="off"
                placeholder="图中字符"
              />
              <button
                type="button"
                class="captcha-btn"
                :disabled="loadingCaptcha"
                @click="loadCaptcha"
              >
                <img v-if="captcha.image" :src="captcha.image" alt="点击刷新验证码" />
                <span v-else>加载验证码</span>
              </button>
            </div>
          </label>
          <p v-if="error" class="notice">{{ errorLabel }}</p>
          <el-button type="primary" native-type="submit" :loading="busy">登录</el-button>
        </el-form>
        <p class="switch">
          还没有学号？
          <router-link to="/register">注册</router-link>
        </p>
      </section>
    </main>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { fetchCaptcha, loginLearner } from '@/api/learner';
import { useLoginFamilyStore } from '@/api/login';

const router = useRouter();
const route = useRoute();
const session = useLoginFamilyStore();

const form = reactive({
  phone: '',
  password: '',
  captcha_id: '',
  captcha_answer: '',
});
const captcha = reactive({ image: '' });
const busy = ref(false);
const loadingCaptcha = ref(false);
const error = ref('');

const errorLabel = computed(() => {
  const map: Record<string, string> = {
    CAPTCHA_INVALID: '验证码无效, 请换一张再试',
    LOGIN_INVALID: '手机号或密码不正确',
    INVALID_PHONE: '请输入 11 位大陆手机号',
    VALIDATION_FAILED: '请检查手机号和密码',
  };
  return map[error.value] ?? '暂时无法登录';
});

async function loadCaptcha(): Promise<void> {
  loadingCaptcha.value = true;
  error.value = '';
  try {
    const challenge = await fetchCaptcha();
    form.captcha_id = challenge.captcha_id;
    form.captcha_answer = '';
    captcha.image = challenge.image;
  } catch {
    error.value = 'INTERNAL';
  } finally {
    loadingCaptcha.value = false;
  }
}

async function onSubmit(): Promise<void> {
  busy.value = true;
  error.value = '';
  try {
    const pair = await loginLearner({ ...form });
    session.signIn(pair);
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/';
    await router.replace(redirect.startsWith('/') ? redirect : '/');
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'INTERNAL';
    await loadCaptcha();
  } finally {
    busy.value = false;
  }
}

onMounted(() => {
  void loadCaptcha();
});
</script>
