<script setup lang="ts">
import { ref } from 'vue'
import { createShare } from '@/api/learner'

interface Props {
  courseId: number
  courseTitle: string
}
const props = defineProps<Props>()

const sharing = ref(false)
const shared = ref<string | null>(null)
const copied = ref(false)
const errorMsg = ref<string | null>(null)

async function share(): Promise<void> {
  if (sharing.value) return
  sharing.value = true
  errorMsg.value = null
  try {
    const r = await createShare(props.courseId)
    const url = new URL(r.share_url, window.location.origin).toString()
    shared.value = url
    copied.value = false
    try {
      await navigator.clipboard.writeText(url)
      copied.value = true
    } catch {
      // Clipboard might be unavailable — user can still copy manually.
    }
  } catch (err) {
    errorMsg.value = (err as Error).message || 'share_failed'
  } finally {
    sharing.value = false
  }
}
</script>

<template>
  <div class="share-bar">
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
.share-bar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.btn { padding: 6px 12px; border: 1px solid var(--color-border, #d0d4dc); border-radius: 6px; background: transparent; font: inherit; cursor: pointer; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.result { font-size: 0.85rem; color: var(--color-text-muted, #5b6472); margin: 0; word-break: break-all; }
.ok { color: #2bb673; margin-left: 6px; }
.error { color: #b42318; font-size: 0.85rem; margin: 0; }
</style>