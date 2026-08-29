<template>
  <div class="campus auth-page">
    <header class="masthead">
      <div class="masthead-inner">
        <router-link to="/" class="brand">
          <div class="seal-mark" aria-hidden="true">
            <span>拾</span><span>阶</span><span>学</span><span>社</span>
          </div>
          <div class="brand-txt">
            <h1>拾阶学社</h1>
            <p>拾级而上 · 日进一阶</p>
          </div>
        </router-link>
        <div class="masthead-tools">
          <button
            type="button"
            class="btn-night"
            :title="isNight ? '切换日间模式' : '夜读模式'"
            :aria-pressed="isNight"
            @click="toggleNight"
          >
            {{ isNight ? '☀' : '☾' }}
          </button>
        </div>
      </div>
    </header>
    <main class="auth-shell">
      <aside class="auth-aside">
        <p class="eyebrow"><span class="eyebrow-rule" />拾阶学社 · 学员入口</p>
        <p class="auth-index latin">READ / 02</p>
        <h1 class="display">给自己留一间安静的课室。</h1>
        <p>注册后可以加入课程、记录进度，也可以沿着学习地图逐阶段完成。</p>
      </aside>
      <section class="auth-card">
        <p class="badge">学员注册</p>
        <h2 class="display">领一张课室学号</h2>
        <p class="lede">只用大陆手机号注册，后台账号不能登录这里。</p>
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
              autocomplete="new-password"
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
          <el-button type="primary" native-type="submit" :loading="busy">注册并进入</el-button>
        </el-form>
        <p class="switch">
          已经有学号？
          <router-link to="/login" class="switch-link">登录</router-link>
        </p>
        <p class="form-note">注册成功后会自动登录，并保留你的学习进度与收藏。</p>
      </section>
    </main>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { fetchCaptcha, registerLearner } from '@/api/learner';
import { useLoginFamilyStore } from '@/api/login';
import { useTheme } from '@/composables/useTheme';

const router = useRouter();
const session = useLoginFamilyStore();
const { isNight, toggleNight } = useTheme();

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
    PHONE_TAKEN: '这个手机号已经注册',
    CONFLICT: '这个手机号已经注册',
    INVALID_PHONE: '请输入 11 位大陆手机号',
    PASSWORD_LENGTH: '密码需要 8 到 72 位',
    VALIDATION_FAILED: '请检查手机号和密码',
  };
  return map[error.value] ?? '暂时无法注册';
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
    const pair = await registerLearner({ ...form });
    session.signIn(pair);
    await router.replace('/');
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
