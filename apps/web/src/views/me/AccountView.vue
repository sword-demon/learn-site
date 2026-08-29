<template>
  <main class="page account-page">
    <header class="account-head">
      <div>
        <p class="eyebrow"><span class="eyebrow-rule" />个人书架 · 账户</p>
        <h1 class="display">账户资料</h1>
        <p class="lede">管理你的公开称呼和课程页显示偏好。</p>
      </div>
    </header>

    <p v-if="loading" class="notice">正在加载资料…</p>
    <p v-else-if="error" class="notice error">资料暂时读不到，请稍后再试。</p>
    <form v-else class="profile-form" @submit.prevent="save">
      <label class="field">
        手机号
        <input :value="profile?.phone" type="text" disabled />
      </label>
      <label class="field">
        公开称呼
        <input v-model="form.nickname" type="text" maxlength="32" autocomplete="nickname" />
      </label>
      <label class="toggle-field">
        <input v-model="form.show_on_course" type="checkbox" />
        <span>在课程页显示我的称呼</span>
      </label>
      <p v-if="saved" class="notice success">资料已更新。</p>
      <button type="submit" class="btn btn-primary" :disabled="saving">
        {{ saving ? '保存中…' : '保存资料' }}
      </button>
    </form>
  </main>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import type { LearnerProfileDTO } from '@learn-site/contracts';
import { fetchLearnerProfile, updateLearnerProfile } from '@/api/learner';

defineOptions({ name: 'AccountView' });

const profile = ref<LearnerProfileDTO | null>(null);
const loading = ref(true);
const saving = ref(false);
const saved = ref(false);
const error = ref(false);
const form = reactive({ nickname: '', show_on_course: false });

async function load(): Promise<void> {
  try {
    profile.value = await fetchLearnerProfile();
    form.nickname = profile.value.nickname ?? '';
    form.show_on_course = profile.value.show_on_course;
  } catch {
    error.value = true;
  } finally {
    loading.value = false;
  }
}

async function save(): Promise<void> {
  saving.value = true;
  saved.value = false;
  error.value = false;
  try {
    profile.value = await updateLearnerProfile({
      nickname: form.nickname.trim() || null,
      show_on_course: form.show_on_course,
    });
    saved.value = true;
  } catch {
    error.value = true;
  } finally {
    saving.value = false;
  }
}

onMounted(() => void load());
</script>

<style scoped>
.account-page {
  display: grid;
  gap: 28px;
}
.account-head {
  padding: 18px 0 26px;
  border-bottom: 1px solid var(--line);
}
.account-head .eyebrow {
  margin-bottom: 16px;
}
.account-head .display {
  margin: 0 0 9px;
  color: var(--pine-deep);
}
.profile-form {
  display: grid;
  max-width: 520px;
  gap: 18px;
}
.field {
  display: grid;
  gap: 7px;
  color: var(--muted);
  font-size: 0.78rem;
}
.field input {
  width: 100%;
  padding: 11px 12px;
  border: 1px solid var(--line);
  background: var(--surface);
  color: var(--ink);
  font: inherit;
}
.field input:disabled {
  background: var(--paper-deep);
  color: var(--muted);
}
.toggle-field {
  display: flex;
  align-items: center;
  gap: 9px;
  color: var(--ink);
  font-size: 0.82rem;
}
.toggle-field input {
  accent-color: var(--accent);
}
.success {
  color: var(--pine-deep);
}
</style>
