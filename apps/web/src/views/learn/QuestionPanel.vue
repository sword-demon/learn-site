<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import {
  askLessonQuestion,
  fetchLessonQuestions,
  fetchQuestion,
  postFollowup,
} from '@/api/learner'
import type {
  QuestionListDTO,
  QuestionMessageDTO,
  QuestionStatus,
  QuestionSummaryDTO,
  QuestionThreadDTO,
} from '@learn-site/contracts'

const props = defineProps<{
  lessonId: number
  authorized?: boolean
}>()

const list = ref<QuestionListDTO>({ items: [], total: 0, page: 1, limit: 20 })
const loading = ref(false)
const loadError = ref<string | null>(null)
const statusFilter = ref<'' | QuestionStatus>('')

const composing = ref(false)
const newTitle = ref('')
const newBody = ref('')
const submitting = ref(false)
const submitError = ref<string | null>(null)

const openThread = ref<QuestionThreadDTO | null>(null)
const followupBody = ref('')
const followupSubmitting = ref(false)

const statusOptions: Array<{ value: '' | QuestionStatus; label: string }> = [
  { value: '', label: '全部' },
  { value: 'pending', label: '待回复' },
  { value: 'answered', label: '已回复' },
  { value: 'closed', label: '已关闭' },
]

async function loadList(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    const params: { status?: string } = {}
    if (statusFilter.value) params.status = statusFilter.value
    list.value = await fetchLessonQuestions(props.lessonId, params)
  } catch (err) {
    loadError.value = (err as Error).message || 'load_failed'
  } finally {
    loading.value = false
  }
}

async function openQuestion(q: QuestionSummaryDTO): Promise<void> {
  try {
    openThread.value = await fetchQuestion(q.id)
    followupBody.value = ''
  } catch (err) {
    loadError.value = (err as Error).message || 'open_failed'
  }
}

async function submitAsk(): Promise<void> {
  if (submitting.value) return
  submitError.value = null
  const title = newTitle.value.trim()
  const body = newBody.value.trim()
  if (!title || !body) {
    submitError.value = 'TITLE_BODY_REQUIRED'
    return
  }
  submitting.value = true
  try {
    const thread = await askLessonQuestion(props.lessonId, { title, body })
    openThread.value = thread
    composing.value = false
    newTitle.value = ''
    newBody.value = ''
    await loadList()
  } catch (err) {
    submitError.value = (err as Error).message || 'ASK_FAILED'
  } finally {
    submitting.value = false
  }
}

async function submitFollowup(): Promise<void> {
  if (!openThread.value || followupSubmitting.value) return
  const body = followupBody.value.trim()
  if (!body) return
  followupSubmitting.value = true
  try {
    openThread.value = await postFollowup(openThread.value.question.id, { body })
    followupBody.value = ''
    await loadList()
  } catch (err) {
    submitError.value = (err as Error).message || 'FOLLOWUP_FAILED'
  } finally {
    followupSubmitting.value = false
  }
}

watch(
  () => props.lessonId,
  () => {
    openThread.value = null
    loadList()
  },
  { immediate: true },
)

const statusBadge = (s: QuestionStatus): string => {
  switch (s) {
    case 'pending': return '待回复'
    case 'answered': return '已回复'
    case 'closed': return '已关闭'
  }
}

const authorLabel = (m: QuestionMessageDTO): string => {
  if (m.kind === 'admin') return '管理员'
  if (m.kind === 'system') return '系统'
  return m.author_learner_id === null ? '同学' : '我'
}

const formattedAt = (s: string): string => s ? s.replace('T', ' ').slice(0, 16) : ''

const filteredItems = computed(() => list.value.items)
</script>

<template>
  <section class="qa-panel" aria-label="课节问答">
    <header class="qa-head">
      <h2 class="display">课节问答</h2>
      <div class="filter-row">
        <label class="filter">
          状态
          <select v-model="statusFilter" @change="loadList">
            <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">
              {{ opt.label }}
            </option>
          </select>
        </label>
        <button
          v-if="authorized !== false"
          type="button"
          class="btn btn-primary"
          @click="composing = !composing"
        >
          {{ composing ? '取消提问' : '我要提问' }}
        </button>
      </div>
    </header>

    <p v-if="authorized === false" class="notice">
      未购买该课程, 无法查看或发起问答.
    </p>
    <p v-else-if="loading" class="notice">问答加载中…</p>
    <p v-else-if="loadError" class="notice error">
      问答暂时读不到 ({{ loadError }}).
    </p>

    <form v-if="composing && authorized !== false" class="composer" @submit.prevent="submitAsk">
      <label>
        标题
        <input
          v-model="newTitle"
          type="text"
          maxlength="128"
          placeholder="一句话总结你的问题"
        />
      </label>
      <label>
        正文
        <textarea
          v-model="newBody"
          rows="4"
          maxlength="4000"
          placeholder="补充背景, 课节片段, 已尝试的方法"
        />
      </label>
      <p v-if="submitError" class="error">{{ submitError }}</p>
      <div class="row-end">
        <button type="submit" class="btn btn-primary" :disabled="submitting">
          {{ submitting ? '提交中…' : '提交问题' }}
        </button>
      </div>
    </form>

    <ol v-if="filteredItems.length" class="thread-list">
      <li
        v-for="q in filteredItems"
        :key="q.id"
        class="thread-summary"
      >
        <button type="button" class="thread-button" @click="openQuestion(q)">
          <span class="title">{{ q.title }}</span>
          <span class="meta">
            <span class="badge" :data-status="q.status">{{ statusBadge(q.status) }}</span>
            <time>{{ formattedAt(q.created_at) }}</time>
          </span>
        </button>
      </li>
    </ol>
    <p v-else class="notice">暂无问答, 来提第一个问题吧.</p>

    <article v-if="openThread" class="thread-detail">
      <header class="thread-head">
        <h3>{{ openThread.question.title }}</h3>
        <span class="badge" :data-status="openThread.question.status">
          {{ statusBadge(openThread.question.status) }}
        </span>
      </header>
      <ol class="messages">
        <li
          v-for="m in openThread.messages"
          :key="m.id"
          class="message"
          :data-kind="m.kind"
        >
          <p class="meta">
            <strong>{{ authorLabel(m) }}</strong>
            <time>{{ formattedAt(m.created_at) }}</time>
          </p>
          <p class="body">{{ m.body }}</p>
        </li>
      </ol>
      <form
        v-if="authorized !== false && openThread.question.status !== 'closed'"
        class="followup"
        @submit.prevent="submitFollowup"
      >
        <label>
          追问
          <textarea
            v-model="followupBody"
            rows="3"
            maxlength="4000"
            placeholder="补充信息, 等待管理员回复"
          />
        </label>
        <div class="row-end">
          <button type="submit" class="btn btn-primary" :disabled="followupSubmitting">
            {{ followupSubmitting ? '提交中…' : '追问' }}
          </button>
        </div>
      </form>
      <p v-else-if="openThread.question.status === 'closed'" class="notice">
        该问答已关闭, 无法继续追问.
      </p>
    </article>
  </section>
</template>

<style scoped>
.qa-panel {
  display: grid;
  gap: 16px;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 8px;
  padding: 16px;
}
.qa-head { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
.display { margin: 0; font-size: 1.1rem; }
.filter-row { display: flex; gap: 8px; align-items: center; }
.filter select { padding: 4px 8px; }
.btn { padding: 6px 12px; border: 1px solid var(--color-border, #d0d4dc); border-radius: 6px; background: transparent; font: inherit; cursor: pointer; }
.btn-primary { background: var(--color-primary, #2563eb); color: #fff; border-color: transparent; }
.composer, .followup { display: grid; gap: 8px; }
.composer label, .followup label, .filter { display: grid; gap: 4px; font-size: 0.9rem; }
.composer input, .composer textarea, .followup textarea {
  width: 100%; padding: 6px 8px; border: 1px solid var(--color-border, #d0d4dc); border-radius: 6px; font: inherit;
}
.row-end { display: flex; justify-content: flex-end; }
.thread-list { list-style: none; padding: 0; margin: 0; display: grid; gap: 8px; }
.thread-summary { border: 1px solid var(--color-border, #d0d4dc); border-radius: 6px; }
.thread-button { width: 100%; text-align: left; background: transparent; border: 0; padding: 10px 12px; cursor: pointer; font: inherit; display: grid; gap: 4px; }
.thread-button .title { font-weight: 600; }
.thread-button .meta { display: flex; gap: 8px; align-items: center; color: var(--color-text-muted, #5b6472); font-size: 0.85rem; }
.badge { padding: 2px 8px; border-radius: 999px; background: var(--color-bg-soft, #f5f6fa); border: 1px solid var(--color-border, #d0d4dc); font-size: 0.78rem; }
.badge[data-status='pending'] { background: #fff7e6; border-color: #f59f00; }
.badge[data-status='answered'] { background: #e7f7ee; border-color: #2bb673; }
.badge[data-status='closed'] { background: #f0f1f3; border-color: #c5c8d0; }
.thread-detail { display: grid; gap: 12px; padding-top: 12px; border-top: 1px solid var(--color-border, #d0d4dc); }
.thread-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; }
.messages { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; }
.message { border: 1px solid var(--color-border, #d0d4dc); border-radius: 6px; padding: 10px 12px; background: var(--color-bg-soft, #fafbfd); }
.message[data-kind='admin'] { background: #eef4ff; border-color: #c7d8ff; }
.message .meta { display: flex; gap: 8px; align-items: center; color: var(--color-text-muted, #5b6472); font-size: 0.85rem; margin: 0 0 6px 0; }
.message .body { margin: 0; white-space: pre-wrap; }
.notice { color: var(--color-text-muted, #5b6472); }
.notice.error { color: #b42318; }
.error { color: #b42318; font-size: 0.85rem; }
</style>
