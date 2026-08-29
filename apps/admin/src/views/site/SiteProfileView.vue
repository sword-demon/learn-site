<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { ElMessage } from 'element-plus';
import type { SiteIntro, SiteProfileUpdateInput } from '@learn-site/contracts';
import { fetchSiteProfile, updateSiteProfile } from '@/api/site';
import ContentEditor from '@/components/course/ContentEditor.vue';

defineOptions({ name: 'SiteProfileView' });

const profile = ref<SiteIntro | null>(null);
const draft = ref<SiteProfileUpdateInput | null>(null);
const loading = ref(false);
const submitting = ref(false);
const errorMsg = ref<string | null>(null);

function edit(saved: SiteIntro): SiteProfileUpdateInput {
  return {
    title: saved.title,
    subtitle: saved.subtitle,
    body_html: saved.body_html,
    contact_email: saved.contact_email,
  };
}

async function reload(): Promise<void> {
  loading.value = true;
  errorMsg.value = null;
  try {
    profile.value = await fetchSiteProfile();
    draft.value = edit(profile.value);
  } catch (error) {
    errorMsg.value = (error as Error).message || 'load_failed';
  } finally {
    loading.value = false;
  }
}

async function save(): Promise<void> {
  if (!draft.value) return;
  submitting.value = true;
  errorMsg.value = null;
  try {
    profile.value = await updateSiteProfile(draft.value);
    draft.value = edit(profile.value);
    ElMessage.success('已保存');
  } catch (error) {
    errorMsg.value = (error as Error).message || 'save_failed';
  } finally {
    submitting.value = false;
  }
}

function reset(): void {
  if (profile.value) draft.value = edit(profile.value);
}

onMounted(() => void reload());
</script>

<template>
  <main class="page">
    <header class="head">
      <h1 class="display">站点资料</h1>
      <p class="muted">这里的内容会展示在学员首页。</p>
    </header>

    <p v-if="errorMsg" class="error">{{ errorMsg }}</p>
    <p v-else-if="loading" class="notice">加载中…</p>

    <el-form
      v-else-if="draft"
      :model="draft"
      class="form"
      label-position="top"
      @submit.prevent="save"
    >
      <el-form-item label="标题" required>
        <el-input v-model="draft.title" clearable name="title" type="text" maxlength="80" />
      </el-form-item>
      <el-form-item label="副标题">
        <el-input v-model="draft.subtitle" clearable name="subtitle" type="text" maxlength="160" />
      </el-form-item>
      <el-form-item label="富文本正文（HTML）" class="body-field">
        <ContentEditor
          v-model="draft.body_html"
          placeholder="请输入站点首页展示的正文，支持格式化文本与图片"
          :height="360"
        />
      </el-form-item>
      <el-form-item label="联系邮箱">
        <el-input
          v-model="draft.contact_email"
          clearable
          name="contact_email"
          type="email"
          maxlength="120"
        />
      </el-form-item>
      <p v-if="profile?.updated_at" class="muted">最近更新：{{ profile.updated_at }}</p>
      <div class="actions">
        <el-button class="btn" :disabled="submitting" @click="reset">撤销修改</el-button>
        <el-button class="btn btn-primary" native-type="submit" :disabled="submitting">
          {{ submitting ? '保存中…' : '保存' }}
        </el-button>
      </div>
    </el-form>
  </main>
</template>

<style scoped>
.page {
  display: grid;
  gap: 16px;
  max-width: 860px;
}
.head {
  display: grid;
  gap: 4px;
}
.display {
  margin: 0;
  font-size: 1.4rem;
}
.muted {
  color: var(--color-text-muted, #5b6472);
  margin: 0;
  font-size: 0.85rem;
}
.error {
  color: #b42318;
  margin: 0;
}
.notice {
  color: var(--color-text-muted, #5b6472);
  margin: 0;
}
.form {
  display: grid;
  gap: 14px;
  background: #fff;
  padding: 16px;
  border: 1px solid var(--color-border, #e3e6ee);
  border-radius: 8px;
}
.form :deep(.el-form-item__label) {
  color: #52667a;
  font-size: 13px;
  font-weight: 600;
}
.body-field :deep(.el-form-item__content) {
  line-height: normal;
}
.actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}
.btn {
  padding: 6px 14px;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
  background: #fff;
  font: inherit;
  cursor: pointer;
}
.btn-primary {
  background: var(--color-primary, #2563eb);
  color: #fff;
  border-color: transparent;
}
.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
