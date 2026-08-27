<script setup lang="ts">
import { ref } from 'vue';
import { createShare } from '@/api/learner';

interface Props {
  courseId: number;
  courseTitle: string;
}
const props = defineProps<Props>();

const sharing = ref(false);
const shared = ref<string | null>(null);
const copied = ref(false);
const errorMsg = ref<string | null>(null);

async function share(): Promise<void> {
  if (sharing.value) return;
  sharing.value = true;
  errorMsg.value = null;
  try {
    const r = await createShare(props.courseId);
    const url = new URL(r.share_url, window.location.origin).toString();
    shared.value = url;
    copied.value = false;
    try {
      await navigator.clipboard.writeText(url);
      copied.value = true;
    } catch {
      // Clipboard might be unavailable — user can still copy manually.
    }
  } catch (err) {
    errorMsg.value = (err as Error).message || 'share_failed';
  } finally {
    sharing.value = false;
  }
}
</script>

<template>
  <div class="share-bar">
    <span class="share-label">课程分享</span>
    <button type="button" class="btn" :disabled="sharing" @click="share">
      {{ sharing ? '生成中…' : '分享课程' }}
    </button>
    <p v-if="errorMsg" class="error">{{ errorMsg }}</p>
    <p v-else-if="shared" class="result">
      链接：<a :href="shared">{{ shared }}</a>
      <span v-if="copied" class="ok">已复制到剪贴板</span>
    </p>
  </div>
</template>

<style scoped>
.share-bar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  padding: 15px 0;
  border-top: 1px solid var(--line);
}

.share-label {
  color: var(--muted);
  font-size: 0.78rem;
}

.btn {
  min-height: 34px;
  padding: 6px 11px;
  border: 1px solid var(--line);
  border-radius: 5px;
  background: var(--surface);
  color: var(--pine-deep);
  font: inherit;
  cursor: pointer;
}

.btn:disabled {
  cursor: not-allowed;
  opacity: 0.5;
}

.result {
  max-width: 100%;
  margin: 0;
  color: var(--muted);
  font-size: 0.78rem;
  word-break: break-all;
}

.ok {
  margin-left: 6px;
  color: var(--pine);
}

.error {
  margin: 0;
  color: #9e3f2c;
  font-size: 0.78rem;
}
</style>
