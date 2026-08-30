<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { ChatDotRound, ArrowRight, Position } from '@element-plus/icons-vue';
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

function setStatusFilter(value: '' | QuestionStatus): void {
  if (statusFilter.value === value) return;
  statusFilter.value = value;
  void loadList();
}
</script>

<template>
  <section class="panel qa-panel" aria-label="课节问答">
    <header class="qa-head">
      <h3 class="qa-title-heading">课节问答</h3>
      <div class="qa-toolbar">
        <div class="status-filters">
          <span class="status-filters-label">状态</span>
          <el-segmented
            :model-value="statusFilter"
            :options="statusOptions"
            aria-label="按状态筛选"
            @update:model-value="setStatusFilter"
          />
        </div>
        <el-button
          v-if="authorized !== false"
          type="primary"
          size="small"
          :icon="ChatDotRound"
          @click="composing = !composing"
        >
          {{ composing ? '取消提问' : '我要提问' }}
        </el-button>
      </div>
    </header>

    <el-alert
      v-if="authorized === false"
      title="未取得课程学习资格，无法查看或发起问答。"
      type="warning"
      :closable="false"
      show-icon
    />
    <el-skeleton v-else-if="loading" animated :rows="3" />
    <el-alert
      v-else-if="loadError"
      :title="`问答暂时读不到（${loadError}）。`"
      type="error"
      :closable="false"
      show-icon
    />

    <el-form
      v-if="composing && authorized !== false"
      class="composer"
      label-position="top"
      @submit.prevent="submitAsk"
    >
      <el-form-item label="标题">
        <el-input v-model="newTitle" maxlength="128" placeholder="一句话总结你的问题" />
      </el-form-item>
      <el-form-item label="正文">
        <el-input
          v-model="newBody"
          type="textarea"
          :rows="4"
          maxlength="4000"
          show-word-limit
          resize="vertical"
          placeholder="补充背景, 课节片段, 已尝试的方法"
        />
      </el-form-item>
      <el-alert v-if="submitError" :title="submitError" type="error" :closable="false" show-icon />
      <div class="row-end">
        <el-button type="primary" native-type="submit" :loading="submitting" :icon="Position">
          提交问题
        </el-button>
      </div>
    </el-form>

    <ol v-if="filteredItems.length" class="thread-list">
      <li
        v-for="q in filteredItems"
        :key="q.id"
        class="thread-summary"
        :class="{ 'is-active': openThread?.question.id === q.id }"
      >
        <el-button text class="thread-button" @click="openQuestion(q)">
          <span class="thread-main">
            <span class="title">{{ q.title }}</span>
            <span class="meta">
              <el-tag
                :type="
                  q.status === 'answered' ? 'success' : q.status === 'pending' ? 'warning' : 'info'
                "
                size="small"
                effect="plain"
              >
                {{ statusBadge(q.status) }}
              </el-tag>
              <time>{{ formattedAt(q.created_at) }}</time>
            </span>
          </span>
          <el-icon class="thread-chevron" aria-hidden="true"><ArrowRight /></el-icon>
        </el-button>
      </li>
    </ol>
    <p v-else class="notice">暂无问答, 来提第一个问题吧.</p>

    <article v-if="openThread" class="thread-detail">
      <header class="thread-head">
        <h3>{{ openThread.question.title }}</h3>
        <el-tag
          :type="
            openThread.question.status === 'answered'
              ? 'success'
              : openThread.question.status === 'pending'
                ? 'warning'
                : 'info'
          "
          size="small"
        >
          {{ statusBadge(openThread.question.status) }}
        </el-tag>
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
      <el-form
        v-if="authorized !== false && openThread.question.status !== 'closed'"
        class="followup"
        label-position="top"
        @submit.prevent="submitFollowup"
      >
        <el-form-item label="追问">
          <el-input
            v-model="followupBody"
            type="textarea"
            :rows="3"
            maxlength="4000"
            show-word-limit
            resize="vertical"
            placeholder="补充信息, 等待管理员回复"
          />
        </el-form-item>
        <div class="row-end">
          <el-button
            type="primary"
            native-type="submit"
            :loading="followupSubmitting"
            :icon="Position"
          >
            追问
          </el-button>
        </div>
      </el-form>
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
}

.qa-head,
.thread-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 14px;
}

.qa-toolbar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}

.status-filters {
  display: inline-flex;
  align-items: center;
  gap: 10px;
}

.status-filters-label {
  font-family: var(--mono);
  font-size: 11px;
  letter-spacing: 0.08em;
  color: var(--ink-3);
  white-space: nowrap;
}

.composer,
.followup {
  display: grid;
  gap: 12px;
  padding: 16px;
  border-left: 3px solid var(--accent);
  background: var(--surface-muted);
}

.composer :deep(.el-form-item__label),
.followup :deep(.el-form-item__label) {
  color: var(--pine-deep);
  font-size: 0.82rem;
  font-weight: 700;
}

.composer :deep(.el-form-item),
.followup :deep(.el-form-item) {
  margin-bottom: 0;
}

.row-end {
  display: flex;
  justify-content: flex-end;
}

.thread-list {
  display: grid;
  gap: 0;
  margin: 0;
  padding: 0;
  list-style: none;
  border: 1px solid var(--line);
  border-radius: 10px;
  overflow: hidden;
  background: var(--surface);
}

.thread-summary {
  border-bottom: 1px solid var(--line);
}

.thread-summary:last-child {
  border-bottom: 0;
}

.thread-button.el-button {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  width: 100%;
  height: auto;
  padding: 15px 16px;
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
  margin-left: 0;
  transition: background-color 0.2s ease;
}

.thread-button.el-button:hover,
.thread-summary.is-active .thread-button {
  background: var(--surface-muted);
}

.thread-main {
  display: grid;
  gap: 8px;
  min-width: 0;
  flex: 1;
}

.thread-button .title {
  color: var(--pine-deep);
  font-family: var(--font-display);
  font-size: 1rem;
  font-weight: 700;
  line-height: 1.45;
  word-break: break-word;
}

.thread-button .meta,
.message .meta {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  color: var(--muted);
  font-size: 0.78rem;
}

.thread-button .meta time {
  font-family: var(--mono);
  font-size: 0.72rem;
  letter-spacing: 0.02em;
}

.thread-chevron {
  flex-shrink: 0;
  color: var(--ink-3);
  font-size: 14px;
  transition:
    transform 0.2s ease,
    color 0.2s ease;
}

.thread-summary.is-active .thread-chevron {
  transform: rotate(90deg);
  color: var(--seal);
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

  .qa-head {
    align-items: flex-start;
  }

  .qa-toolbar {
    width: 100%;
    flex-direction: column;
    align-items: stretch;
  }

  .status-filters {
    width: 100%;
    flex-direction: column;
    align-items: stretch;
    gap: 8px;
  }

  .status-filters :deep(.el-segmented) {
    width: 100%;
  }

  .qa-toolbar > .el-button {
    width: 100%;
  }
}
</style>
