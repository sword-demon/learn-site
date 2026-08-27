<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import {
  askLessonQuestion,
  fetchLessonQuestions,
  fetchQuestion,
  postFollowup,
} from '@/api/learner';
import type {
  QuestionListDTO,
  QuestionMessageDTO,
  QuestionStatus,
  QuestionSummaryDTO,
  QuestionThreadDTO,
} from '@learn-site/contracts';

const props = defineProps<{
  lessonId: number;
  authorized?: boolean;
}>();

const list = ref<QuestionListDTO>({ items: [], total: 0, page: 1, limit: 20 });
const loading = ref(false);
const loadError = ref<string | null>(null);
const statusFilter = ref<'' | QuestionStatus>('');

const composing = ref(false);
const newTitle = ref('');
const newBody = ref('');
const submitting = ref(false);
const submitError = ref<string | null>(null);

const openThread = ref<QuestionThreadDTO | null>(null);
const followupBody = ref('');
const followupSubmitting = ref(false);

const statusOptions: Array<{ value: '' | QuestionStatus; label: string }> = [
  { value: '', label: '全部' },
  { value: 'pending', label: '待回复' },
  { value: 'answered', label: '已回复' },
  { value: 'closed', label: '已关闭' },
];

async function loadList(): Promise<void> {
  loading.value = true;
  loadError.value = null;
  try {
    const params: { status?: string } = {};
    if (statusFilter.value) params.status = statusFilter.value;
    list.value = await fetchLessonQuestions(props.lessonId, params);
  } catch (err) {
    loadError.value = (err as Error).message || 'load_failed';
  } finally {
    loading.value = false;
  }
}

async function openQuestion(q: QuestionSummaryDTO): Promise<void> {
  try {
    openThread.value = await fetchQuestion(q.id);
    followupBody.value = '';
  } catch (err) {
    loadError.value = (err as Error).message || 'open_failed';
  }
}

async function submitAsk(): Promise<void> {
  if (submitting.value) return;
  submitError.value = null;
  const title = newTitle.value.trim();
  const body = newBody.value.trim();
  if (!title || !body) {
    submitError.value = 'TITLE_BODY_REQUIRED';
    return;
  }
  submitting.value = true;
  try {
    const thread = await askLessonQuestion(props.lessonId, { title, body });
    openThread.value = thread;
    composing.value = false;
    newTitle.value = '';
    newBody.value = '';
    await loadList();
  } catch (err) {
    submitError.value = (err as Error).message || 'ASK_FAILED';
  } finally {
    submitting.value = false;
  }
}

async function submitFollowup(): Promise<void> {
  if (!openThread.value || followupSubmitting.value) return;
  const body = followupBody.value.trim();
  if (!body) return;
  followupSubmitting.value = true;
  try {
    openThread.value = await postFollowup(openThread.value.question.id, { body });
    followupBody.value = '';
    await loadList();
  } catch (err) {
    submitError.value = (err as Error).message || 'FOLLOWUP_FAILED';
  } finally {
    followupSubmitting.value = false;
  }
}

watch(
  () => props.lessonId,
  () => {
    openThread.value = null;
    loadList();
  },
  { immediate: true },
);

const statusBadge = (s: QuestionStatus): string => {
  switch (s) {
    case 'pending':
      return '待回复';
    case 'answered':
      return '已回复';
    case 'closed':
      return '已关闭';
  }
};

const authorLabel = (m: QuestionMessageDTO): string => {
  if (m.kind === 'admin') return '管理员';
  if (m.kind === 'system') return '系统';
  return m.author_learner_id === null ? '同学' : '我';
};

const formattedAt = (s: string): string => (s ? s.replace('T', ' ').slice(0, 16) : '');

const filteredItems = computed(() => list.value.items);
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

    <p v-if="authorized === false" class="notice">未购买该课程, 无法查看或发起问答.</p>
    <p v-else-if="loading" class="notice">问答加载中…</p>
    <p v-else-if="loadError" class="notice error">问答暂时读不到 ({{ loadError }}).</p>

    <form v-if="composing && authorized !== false" class="composer" @submit.prevent="submitAsk">
      <label>
        标题
        <input v-model="newTitle" type="text" maxlength="128" placeholder="一句话总结你的问题" />
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
      <li v-for="q in filteredItems" :key="q.id" class="thread-summary">
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
        <li v-for="m in openThread.messages" :key="m.id" class="message" :data-kind="m.kind">
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
  gap: 18px;
  padding: 24px 26px 28px;
  border-top: 3px solid var(--pine);
  border-bottom: 1px solid var(--line);
  background: rgba(255, 254, 250, 0.68);
}

.qa-head,
.thread-head {
  display: flex;
  align-items: end;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
}

.display {
  margin: 0;
  color: var(--pine-deep);
  font-size: 1.55rem;
}

.filter-row {
  display: flex;
  align-items: end;
  flex-wrap: wrap;
  gap: 10px;
}

.filter {
  display: grid;
  gap: 5px;
  color: var(--muted);
  font-size: 0.76rem;
}

.filter select,
.composer input,
.composer textarea,
.followup textarea {
  width: 100%;
  min-height: 38px;
  padding: 7px 10px;
  border: 1px solid var(--line);
  border-radius: 5px;
  outline: 0;
  background: var(--surface);
  color: var(--ink);
  font: inherit;
}

.filter select:focus,
.composer input:focus,
.composer textarea:focus,
.followup textarea:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(201, 94, 67, 0.13);
}

.composer,
.followup {
  display: grid;
  gap: 12px;
  padding: 16px;
  border-left: 3px solid var(--accent);
  background: var(--surface-muted);
}

.composer label,
.followup label {
  display: grid;
  gap: 6px;
  color: var(--pine-deep);
  font-size: 0.82rem;
  font-weight: 700;
}

.composer textarea,
.followup textarea {
  min-height: 96px;
  resize: vertical;
}

.row-end {
  display: flex;
  justify-content: flex-end;
}

.thread-list {
  display: grid;
  gap: 2px;
  margin: 0;
  padding: 0;
  list-style: none;
  border-top: 1px solid var(--line);
}

.thread-summary {
  border-bottom: 1px solid var(--line);
}

.thread-button {
  display: grid;
  width: 100%;
  gap: 7px;
  padding: 13px 7px;
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition:
    background-color 0.2s ease,
    padding-left 0.2s ease;
}

.thread-button:hover {
  padding-left: 12px;
  background: var(--surface-muted);
}

.thread-button .title {
  color: var(--pine-deep);
  font-family: var(--font-display);
  font-size: 1rem;
  font-weight: 700;
}

.thread-button .meta,
.message .meta {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  color: var(--muted);
  font-size: 0.78rem;
}

.badge {
  display: inline-flex;
  min-height: 22px;
  align-items: center;
  padding: 2px 7px;
  border: 1px solid var(--line);
  border-radius: 999px;
  background: var(--surface-muted);
  font-family: var(--font-body);
  font-size: 0.72rem;
  letter-spacing: 0;
}

.badge[data-status='pending'] {
  border-color: #e2c38f;
  background: #fff7e5;
  color: #8b5b13;
}

.badge[data-status='answered'] {
  border-color: #bad4c1;
  background: #eef7f0;
  color: var(--pine-deep);
}

.badge[data-status='closed'] {
  color: var(--muted);
}

.thread-detail {
  display: grid;
  gap: 16px;
  padding-top: 18px;
  border-top: 1px solid var(--line);
}

.thread-head {
  align-items: start;
}

.thread-head h3 {
  margin: 0;
  color: var(--pine-deep);
  font-family: var(--font-display);
  font-size: 1.15rem;
}

.messages {
  display: grid;
  gap: 9px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.message {
  padding: 13px 15px;
  border: 1px solid var(--line);
  border-left: 3px solid var(--line);
  background: var(--surface);
}

.message[data-kind='admin'] {
  border-left-color: var(--pine);
  background: #eff5ef;
}

.message .meta {
  margin: 0 0 7px;
}

.message .body {
  margin: 0;
  line-height: 1.7;
  white-space: pre-wrap;
}

.error {
  margin: 0;
  color: #9e3f2c;
  font-size: 0.82rem;
}

@media (max-width: 560px) {
  .qa-panel {
    padding: 20px 16px 23px;
  }

  .filter-row,
  .filter,
  .filter select {
    width: 100%;
  }

  .filter-row .btn {
    width: 100%;
  }
}
</style>
