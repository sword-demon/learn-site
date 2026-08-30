<template>
  <main class="page account-page">
    <header class="account-head">
      <div>
        <p class="eyebrow"><span class="eyebrow-rule" />个人书架 · 账户</p>
        <h1 class="display">账户资料</h1>
        <p class="lede">管理你的公开称呼和课程页显示偏好。</p>
      </div>
    </header>

    <el-skeleton v-if="loading" animated :rows="4" />
    <el-alert
      v-else-if="error"
      title="资料暂时读不到，请稍后再试。"
      type="error"
      :closable="false"
      show-icon
    />
    <el-form v-else class="profile-form" :model="form" label-position="top" @submit.prevent="save">
      <el-form-item label="手机号">
        <el-input :model-value="profile?.phone ?? ''" disabled />
      </el-form-item>
      <el-form-item label="公开称呼">
        <el-input v-model="form.nickname" maxlength="32" autocomplete="nickname" />
      </el-form-item>
      <el-form-item label="课程页公开显示">
        <el-switch v-model="form.show_on_course" active-text="在课程页显示我的称呼" />
      </el-form-item>
      <el-alert v-if="saved" title="资料已更新。" type="success" :closable="false" show-icon />
      <el-button type="primary" native-type="submit" :loading="saving">保存资料</el-button>
    </el-form>
  </main>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import type { LearnerProfileDTO } from '@learn-site/contracts';
import { fetchLearnerProfile, updateLearnerProfile } from '@/api/learner';
import { useLearnerProfileStore } from '@/stores/learnerProfile';

defineOptions({ name: 'AccountView' });

const profileStore = useLearnerProfileStore();
profileStore.ensureSessionWatch();
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
    profileStore.setProfile(profile.value);
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
.profile-form :deep(.el-form-item) {
  margin-bottom: 0;
}
.profile-form :deep(.el-form-item__label) {
  color: var(--muted);
  font-size: 0.78rem;
  font-weight: 700;
}
.profile-form > .el-button {
  justify-self: start;
}
</style>
