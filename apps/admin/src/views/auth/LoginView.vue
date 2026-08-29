<template>
  <main class="login-shell">
    <el-card class="login-card" shadow="always">
      <h1 class="title">管理员登录</h1>
      <p class="subtitle">仅允许后台工作人员使用账号 + 密码 + 图形验证码登录。</p>
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top" @submit.prevent>
        <el-form-item label="账号" prop="account">
          <el-input
            v-model="form.account"
            clearable
            autocomplete="username"
            placeholder="3–64 位管理员账号"
          />
        </el-form-item>
        <el-form-item label="密码" prop="password">
          <el-input
            v-model="form.password"
            clearable
            type="password"
            autocomplete="current-password"
            show-password
            placeholder="8–72 位"
          />
        </el-form-item>
        <el-form-item label="验证码" prop="captcha_answer">
          <div class="captcha-row">
            <el-input
              v-model="form.captcha_answer"
              clearable
              placeholder="图中字符"
              maxlength="10"
            />
            <div class="captcha-image" :class="{ refreshing: refreshing }" @click="refreshCaptcha">
              <el-image v-if="captchaImage" :src="captchaImage" fit="cover" alt="验证码" />
              <span v-else class="placeholder">点击加载</span>
            </div>
          </div>
        </el-form-item>
        <el-button
          class="w-full"
          type="primary"
          native-type="submit"
          :loading="submitting"
          @click="onSubmit"
        >
          登录
        </el-button>
      </el-form>
      <p v-if="reason === 'password_changed'" class="hint warn">
        密码修改成功，请使用新密码重新登录。
      </p>
    </el-card>
  </main>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage, type FormInstance, type FormRules } from 'element-plus';
import {
  AdminLoginInput,
  ApiResponse,
  CaptchaChallenge,
  parseTokenEnvelope,
} from '@learn-site/contracts';
import { http, setTokens } from '@/api/http';

const route = useRoute();
const router = useRouter();
const formRef = ref<FormInstance | null>(null);
const submitting = ref(false);
const refreshing = ref(false);
const captchaImage = ref<string>('');
const captchaId = ref<string>('');
const reason = ref<string>(typeof route.query.reason === 'string' ? route.query.reason : '');

const form = reactive({
  account: '',
  password: '',
  captcha_answer: '',
});

const rules: FormRules = {
  account: [
    { required: true, message: '请输入账号', trigger: 'blur' },
    { min: 3, max: 64, message: '账号长度 3–64', trigger: 'blur' },
  ],
  password: [
    { required: true, message: '请输入密码', trigger: 'blur' },
    { min: 8, max: 72, message: '密码长度 8–72', trigger: 'blur' },
  ],
  captcha_answer: [
    { required: true, message: '请输入验证码', trigger: 'blur' },
    { min: 1, max: 10, message: '验证码无效', trigger: 'blur' },
  ],
};

async function refreshCaptcha(): Promise<void> {
  if (refreshing.value) return;
  refreshing.value = true;
  try {
    const res = await http.get('/auth/captcha');
    const parsed = ApiResponse(CaptchaChallenge).safeParse(res.data);
    if (parsed.success && parsed.data.ok) {
      captchaId.value = parsed.data.data.captcha_id;
      captchaImage.value = parsed.data.data.image;
    } else {
      throw new Error('INVALID_CAPTCHA_RESPONSE');
    }
  } catch {
    ElMessage.error('验证码加载失败，请重试');
  } finally {
    refreshing.value = false;
  }
}

async function onSubmit(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false);
  if (!valid) return;
  const parsed = AdminLoginInput.safeParse({
    account: form.account,
    password: form.password,
    captcha_id: captchaId.value,
    captcha_answer: form.captcha_answer,
  });
  if (!parsed.success) {
    ElMessage.error('表单校验失败');
    return;
  }
  submitting.value = true;
  try {
    const res = await http.post('/auth/login', parsed.data);
    // Extract both standard token fields and custom extensions
    const pair: any = parseTokenEnvelope(res.data);
    if (!pair) throw new Error('INVALID_TOKEN_RESPONSE');
    // Include the staff account id for display in AdminLayout.
    const staffAccount = (res as { account_id?: string }).account_id ?? form.account;
    setTokens({
      access_token: pair.access_token,
      refresh_token: pair.refresh_token,
      access_expires_in: pair.access_expires_in,
      refresh_expires_in: pair.refresh_expires_in,
      must_change_password: pair.must_change_password === true,
      permission_codes: pair.permission_codes ?? [],
      account_id: staffAccount,
    });
    const next = typeof route.query.next === 'string' ? route.query.next : '/';
    if (pair.must_change_password === true) {
      await router.push({ name: 'first-password', query: { next } });
    } else {
      await router.push(next);
    }
  } catch (err: unknown) {
    const code = (err as { response?: { data?: { error?: { code?: string } } } })?.response?.data
      ?.error?.code;
    if (code === 'CAPTCHA_INVALID') {
      ElMessage.error('验证码错误或已过期');
    } else if (code === 'LOGIN_INVALID') {
      ElMessage.error('账号或密码错误');
    } else {
      ElMessage.error('登录失败，请稍后重试');
    }
    await refreshCaptcha();
  } finally {
    submitting.value = false;
  }
}

onMounted(() => {
  void refreshCaptcha();
});
</script>

<style scoped>
.login-shell {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
}
.login-card {
  width: 100%;
  max-width: 420px;
  padding: 8px;
}
.title {
  font-size: 22px;
  font-weight: 600;
  margin: 0 0 4px;
  color: #0f172a;
}
.subtitle {
  font-size: 13px;
  color: #64748b;
  margin: 0 0 16px;
}
.captcha-row {
  display: flex;
  gap: 12px;
  width: 100%;
}
.captcha-row .el-input {
  flex: 1 1 auto;
}
.captcha-image {
  flex: 0 0 140px;
  height: 40px;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  overflow: hidden;
  background: #f8fafc;
}
.captcha-image img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.captcha-image .placeholder {
  color: #94a3b8;
  font-size: 12px;
}
.captcha-image.refreshing {
  opacity: 0.6;
}
.hint {
  margin-top: 12px;
  font-size: 12px;
  color: #64748b;
}
.hint.warn {
  color: #b45309;
}
</style>
