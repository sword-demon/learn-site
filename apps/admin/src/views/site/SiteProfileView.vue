<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { http } from '@/api/http'
import type { SiteIntro } from '@learn-site/contracts'

defineOptions({ name: 'SiteProfileView' })

const profile = ref<SiteIntro | null>(null)
const draft = ref<SiteIntro | null>(null)
const loading = ref(false)
const errorMsg = ref<string | null>(null)
const submitting = ref(false)

async function reload(): Promise<void> {
  loading.value = true
  errorMsg.value = null
  try {
    const { data } = await http.get('/site')
    const body = data.data ?? data
    profile.value = body
    draft.value = { ...body }
  } catch (err) {
    errorMsg.value = (err as Error).message || 'load_failed'
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  if (!draft.value) return
  submitting.value = true
  errorMsg.value = null
  try {
    const { data } = await http.put('/site', {
      title: draft.value.title,
      subtitle: draft.value.subtitle,
      body_html: draft.value.body_html,
      contact_email: draft.value.contact_email,
    })
    const body = data.data ?? data
    profile.value = body
    draft.value = { ...body }
    ElMessage.success('已保存')
  } catch (err) {
    errorMsg.value = (err as Error).message || 'save_failed'
  } finally {
    submitting.value = false
  }
}

function reset(): void {
  if (profile.value) {
    draft.value = { ...profile.value }
  }
}

onMounted(() => {
  void reload()
})
</script>

<template>
  <main class="page">
    <header class="head">
      <h1 class="display">站点资料</h1>
      <p class="muted">这里的内容会展示在学员首页。</p>
    </header>

    <p v-if="errorMsg" class="error">{{ errorMsg }}</p>
    <p v-else-if="loading" class="notice">加载中…</p>

    <form v-else-if="draft" class="form" @submit.prevent="save">
      <label>
        标题
        <input v-model="draft.title" type="text" maxlength="80" required />
      </label>
      <label>
        副标题
        <input v-model="draft.subtitle" type="text" maxlength="160" />
      </label>
      <label>
        正文（HTML）
        <textarea
          v-model="draft.body_html"
          rows="10"
          maxlength="4000"
        />
      </label>
      <label>
        联系邮箱
        <input v-model="draft.contact_email" type="email" maxlength="120" />
      </label>
      <p v-if="profile?.updated_at" class="muted">
        最近更新：{{ profile.updated_at }}
      </p>
      <div class="actions">
        <button type="button" class="btn" :disabled="submitting" @click="reset">
          撤销修改
        </button>
        <button type="submit" class="btn btn-primary" :disabled="submitting">
          {{ submitting ? '保存中…' : '保存' }}
        </button>
      </div>
    </form>
  </main>
</template>

<style scoped>
.page { display: grid; gap: 16px; max-width: 720px; }
.head { display: grid; gap: 4px; }
.display { margin: 0; font-size: 1.4rem; }
.muted { color: var(--color-text-muted, #5b6472); margin: 0; font-size: 0.85rem; }
.error { color: #b42318; margin: 0; }
.notice { color: var(--color-text-muted, #5b6472); margin: 0; }
.form { display: grid; gap: 14px; background: #fff; padding: 16px; border: 1px solid var(--color-border, #e3e6ee); border-radius: 8px; }
.form label { display: grid; gap: 4px; font-size: 0.85rem; }
.form input,
.form textarea {
  padding: 6px 10px;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
  font: inherit;
  resize: vertical;
}
.actions { display: flex; gap: 8px; justify-content: flex-end; }
.btn {
  padding: 6px 14px;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
  background: #fff;
  font: inherit;
  cursor: pointer;
}
.btn-primary { background: var(--color-primary, #2563eb); color: #fff; border-color: transparent; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
</style>