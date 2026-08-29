<template>
  <main class="password-shell">
    <el-card class="password-card" shadow="always">
      <h1 class="title">首次登录改密</h1>
      <p class="subtitle">修改初始密码后才能进入管理端。</p>
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top" @submit.prevent>
        <el-form-item label="当前密码" prop="current_password">
          <el-input
            v-model="form.current_password"
            clearable
            type="password"
            autocomplete="current-password"
            show-password
            placeholder="请输入初始密码"
          />
        </el-form-item>
        <el-form-item label="新密码" prop="new_password">
          <el-input
            v-model="form.new_password"
            clearable
            type="password"
            autocomplete="new-password"
            show-password
            placeholder="8–72 位"
          />
        </el-form-item>
        <el-form-item label="确认新密码" prop="confirm_password">
          <el-input
            v-model="form.confirm_password"
            clearable
            type="password"
            autocomplete="new-password-confirm"
            show-password
            placeholder="再次输入新密码"
          />
        </el-form-item>
        <el-button
          class="w-full"
          type="primary"
          native-type="submit"
          :loading="submitting"
          @click="onSubmit"
        >
          保存新密码
        </el-button>
      </el-form>
    </el-card>
  </main>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage, type FormInstance, type FormRules } from 'element-plus';
import { ApiResponse, FirstPasswordInput, FirstPasswordOutput } from '@learn-site/contracts';
import { clearTokens, http } from '@/api/http';

const route = useRoute();
const router = useRouter();
const formRef = ref<FormInstance | null>(null);
const submitting = ref(false);

const form = reactive({
  current_password: '',
  new_password: '',
  confirm_password: '',
});

const rules: FormRules = {
  current_password: [{ required: true, message: '请输入当前密码', trigger: 'blur' }],
  new_password: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    { min: 8, max: 72, message: '密码长度 8–72', trigger: 'blur' },
  ],
  confirm_password: [
    { required: true, message: '请再次输入新密码', trigger: 'blur' },
    {
      validator: (_rule, value: string, callback) => {
        if (value !== form.new_password) {
          callback(new Error('两次输入的新密码不一致'));
          return;
        }
        callback();
      },
      trigger: ['blur', 'change'],
    },
  ],
};

async function onSubmit(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false);
  if (!valid) return;

  const parsed = FirstPasswordInput.safeParse({
    current_password: form.current_password,
    new_password: form.new_password,
  });
  if (!parsed.success) {
    ElMessage.error('表单校验失败');
    return;
  }

  submitting.value = true;
  try {
    const response = await http.post('/auth/password/first', parsed.data);
    const result = ApiResponse(FirstPasswordOutput).safeParse(response.data);
    if (!result.success || !result.data.ok) {
      throw new Error('INVALID_FIRST_PASSWORD_RESPONSE');
    }

    const next = typeof route.query.next === 'string' ? route.query.next : '/';
    clearTokens();
    ElMessage.success('密码修改成功');
    await router.replace({
      name: 'login',
      query: { next, reason: 'password_changed' },
    });
  } catch (error: unknown) {
    const code = (error as { response?: { data?: { error?: { code?: string } } } })?.response?.data
      ?.error?.code;
    if (code === 'LOGIN_INVALID') {
      ElMessage.error('当前密码错误');
    } else if (code === 'TOKEN_EXPIRED' || code === 'TOKEN_REVOKED' || code === 'UNAUTHENTICATED') {
      clearTokens();
      ElMessage.error('登录状态已失效，请重新登录');
      await router.replace({ name: 'login', query: { next: '/first-password' } });
    } else {
      ElMessage.error('密码修改失败，请稍后重试');
    }
  } finally {
    submitting.value = false;
  }
}
</script>

<style scoped>
.password-shell {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
}
.password-card {
  width: 100%;
  max-width: 420px;
  padding: 8px;
}
.title {
  margin: 0 0 4px;
  color: #0f172a;
  font-size: 22px;
  font-weight: 600;
}
.subtitle {
  margin: 0 0 16px;
  color: #64748b;
  font-size: 13px;
}
</style>
