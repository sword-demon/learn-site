<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import {
  answerQuestion,
  closeQuestion,
  fetchFilterOptions,
  fetchInbox,
  fetchThread,
} from '@/api/qa';
import type {
  AdminInboxDTO,
  QuestionFilterOptionDTO,
  QuestionMessageDTO,
  QuestionStatus,
  QuestionSummaryDTO,
  QuestionThreadDTO,
} from '@learn-site/contracts';

defineOptions({ name: 'QuestionListView' });

const inbox = ref<AdminInboxDTO | null>(null);
const loading = ref(false);
const listError = ref<string | null>(null);
const page = ref(1);
const limit = 20;

const statusFilter = ref<QuestionStatus>('pending');
const courseFilter = ref<number | ''>('');
const lessonFilter = ref<number | ''>('');
const courseOptions = ref<QuestionFilterOptionDTO[]>([]);
const lessonOptions = ref<QuestionFilterOptionDTO[]>([]);
const filterOptionsLoading = ref(false);
const filterError = ref<string | null>(null);

const active = ref<QuestionThreadDTO | null>(null);
const threadLoading = ref(false);
const threadError = ref<string | null>(null);
const replyBody = ref('');
const answerSubmitting = ref(false);
const closeSubmitting = ref(false);
const answerError = ref<string | null>(null);
const closeError = ref<string | null>(null);
const actionBusy = computed(() => answerSubmitting.value || closeSubmitting.value);

const statusOptions: Array<{ value: QuestionStatus; label: string }> = [
  { value: 'pending', label: '待回复' },
  { value: 'answered', label: '已回复' },
  { value: 'closed', label: '已关闭' },
];

function messageFrom(err: unknown, fallback: string): string {
  return err instanceof Error && err.message ? err.message : fallback;
}

function clearDetail(): void {
  active.value = null;
  threadError.value = null;
  replyBody.value = '';
  answerError.value = null;
  closeError.value = null;
}

async function loadFilterOptions(courseId?: number): Promise<void> {
  filterOptionsLoading.value = true;
  filterError.value = null;
  try {
    const options = await fetchFilterOptions(courseId);
    courseOptions.value = options.courses;
    lessonOptions.value = options.lessons;
  } catch (err) {
    filterError.value = messageFrom(err, 'FILTER_OPTIONS_FAILED');
    lessonOptions.value = [];
  } finally {
    filterOptionsLoading.value = false;
  }
}

async function loadInbox(): Promise<void> {
  loading.value = true;
  listError.value = null;
  try {
    inbox.value = await fetchInbox({
      status: statusFilter.value,
      ...(courseFilter.value === '' ? {} : { course_id: courseFilter.value }),
      ...(lessonFilter.value === '' ? {} : { lesson_id: lessonFilter.value }),
      page: page.value,
      limit,
    });
  } catch (err) {
    listError.value = messageFrom(err, 'INBOX_FAILED');
  } finally {
    loading.value = false;
  }
}

async function changeCourse(): Promise<void> {
  lessonFilter.value = '';
  page.value = 1;
  clearDetail();
  await Promise.all([
    loadFilterOptions(courseFilter.value === '' ? undefined : courseFilter.value),
    loadInbox(),
  ]);
}

function changeFilter(): void {
  page.value = 1;
  clearDetail();
  void loadInbox();
}

function changePage(nextPage: number): void {
  page.value = nextPage;
  clearDetail();
  void loadInbox();
}

async function openThread(question: QuestionSummaryDTO): Promise<void> {
  threadLoading.value = true;
  threadError.value = null;
  answerError.value = null;
  closeError.value = null;
  active.value = null;
  try {
    active.value = await fetchThread(question.id);
    replyBody.value = '';
  } catch (err) {
    threadError.value = messageFrom(err, 'THREAD_FAILED');
  } finally {
    threadLoading.value = false;
  }
}

async function submitReply(): Promise<void> {
  if (!active.value || actionBusy.value) return;
  const body = replyBody.value.trim();
  if (!body) {
    answerError.value = '回复内容不能为空。';
    return;
  }
  answerSubmitting.value = true;
  answerError.value = null;
  try {
    active.value = await answerQuestion(active.value.question.id, { body });
    replyBody.value = '';
    await loadInbox();
  } catch (err) {
    answerError.value = messageFrom(err, 'ANSWER_FAILED');
  } finally {
    answerSubmitting.value = false;
  }
}

async function submitClose(): Promise<void> {
  if (!active.value || actionBusy.value) return;
  if (!confirm('关闭后学员将无法继续追问，确认关闭？')) return;
  closeSubmitting.value = true;
  closeError.value = null;
  try {
    active.value = await closeQuestion(active.value.question.id);
    await loadInbox();
  } catch (err) {
    closeError.value = messageFrom(err, 'CLOSE_FAILED');
  } finally {
    closeSubmitting.value = false;
  }
}

const statusBadge = (status: QuestionStatus): string => {
  switch (status) {
    case 'pending':
      return '待回复';
    case 'answered':
      return '已回复';
    case 'closed':
      return '已关闭';
  }
};

const authorLabel = (message: QuestionMessageDTO): string => {
  if (message.kind === 'admin') return '管理员';
  if (message.kind === 'system') return '系统';
  return '学员';
};

const formattedAt = (value: string): string => (value ? value.replace('T', ' ').slice(0, 16) : '');

onMounted(() => {
  void Promise.all([loadFilterOptions(), loadInbox()]);
});
</script>

<template>
  <section class="qa-page">
    <header class="head">
      <h1 class="display">问答管理</h1>
      <div class="filter-row">
        <label class="filter">
          课程
          <select
            v-model="courseFilter"
            name="course_id"
            :disabled="filterOptionsLoading"
            @change="changeCourse"
          >
            <option value="">全部课程</option>
            <option v-for="course in courseOptions" :key="course.id" :value="course.id">
              {{ course.title }}
            </option>
          </select>
        </label>
        <label class="filter">
          课节
          <select
            v-model="lessonFilter"
            name="lesson_id"
            :disabled="courseFilter === '' || filterOptionsLoading"
            @change="changeFilter"
          >
            <option value="">全部课节</option>
            <option v-for="lesson in lessonOptions" :key="lesson.id" :value="lesson.id">
              {{ lesson.title }}
            </option>
          </select>
        </label>
        <label class="filter">
          状态
          <select v-model="statusFilter" name="status" @change="changeFilter">
            <option v-for="option in statusOptions" :key="option.value" :value="option.value">
              {{ option.label }}
            </option>
          </select>
        </label>
      </div>
    </header>

    <p v-if="filterError" class="notice error">筛选项暂时读不到（{{ filterError }}）。</p>

    <div class="layout">
      <aside class="inbox">
        <p v-if="loading" class="notice">加载中...</p>
        <p v-else-if="listError" class="notice error">问答暂时读不到（{{ listError }}）。</p>
        <ol v-else-if="inbox && inbox.items.length" class="thread-list">
          <li
            v-for="question in inbox.items"
            :key="question.id"
            class="thread-summary"
            :class="{ active: active && active.question.id === question.id }"
          >
            <button type="button" class="thread-button" @click="openThread(question)">
              <span class="title">{{ question.title }}</span>
              <span class="meta">
                <span class="badge" :data-status="question.status">
                  {{ statusBadge(question.status) }}
                </span>
                <time>{{ formattedAt(question.created_at) }}</time>
              </span>
            </button>
          </li>
        </ol>
        <p v-else class="notice">当前筛选下暂无问答。</p>
        <nav v-if="inbox && inbox.total > limit" class="pager" aria-label="问答分页">
          <button type="button" class="btn" :disabled="page <= 1" @click="changePage(page - 1)">
            上一页
          </button>
          <span class="pager-info">{{ page }} / {{ Math.ceil(inbox.total / limit) }}</span>
          <button
            type="button"
            class="btn"
            :disabled="page >= Math.ceil(inbox.total / limit)"
            @click="changePage(page + 1)"
          >
            下一页
          </button>
        </nav>
      </aside>

      <article class="thread-detail" :class="{ empty: !active }">
        <p v-if="threadLoading" class="notice">正在读取问答...</p>
        <p v-else-if="threadError" class="notice error">
          问答详情暂时读不到（{{ threadError }}）。
        </p>
        <template v-else-if="active">
          <header class="thread-head">
            <div>
              <h2>{{ active.question.title }}</h2>
              <span class="badge" :data-status="active.question.status">
                {{ statusBadge(active.question.status) }}
              </span>
            </div>
            <button
              v-if="active.question.status !== 'closed'"
              type="button"
              class="btn btn-danger"
              :disabled="actionBusy"
              @click="submitClose"
            >
              {{ closeSubmitting ? '关闭中...' : '关闭问答' }}
            </button>
          </header>
          <p v-if="closeError" class="notice error">关闭失败（{{ closeError }}）。</p>
          <ol class="messages">
            <li
              v-for="message in active.messages"
              :key="message.id"
              class="message"
              :data-kind="message.kind"
            >
              <p class="meta">
                <strong>{{ authorLabel(message) }}</strong>
                <time>{{ formattedAt(message.created_at) }}</time>
              </p>
              <p class="body">{{ message.body }}</p>
            </li>
          </ol>
          <form
            v-if="active.question.status !== 'closed'"
            class="reply"
            @submit.prevent="submitReply"
          >
            <label>
              回复
              <textarea
                v-model="replyBody"
                rows="4"
                maxlength="4000"
                placeholder="给出明确回答，也可以补充相关提示"
              />
            </label>
            <p v-if="answerError" class="notice error">{{ answerError }}</p>
            <div class="row-end">
              <button type="submit" class="btn btn-primary" :disabled="actionBusy">
                {{ answerSubmitting ? '提交中...' : '提交回复' }}
              </button>
            </div>
          </form>
          <p v-else class="notice">该问答已关闭，无法继续追问。</p>
        </template>
        <p v-else class="notice">从左侧选择一条问答查看详情。</p>
      </article>
    </div>
  </section>
</template>

<style scoped>
.qa-page {
  display: grid;
  gap: 16px;
}
.head {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  flex-wrap: wrap;
  gap: 12px;
}
.display {
  margin: 0;
  font-size: 1.4rem;
}
.filter-row {
  display: flex;
  align-items: flex-end;
  flex-wrap: wrap;
  gap: 10px;
}
.filter {
  display: grid;
  gap: 4px;
  min-width: 150px;
  color: var(--color-text-muted, #5b6472);
  font-size: 0.85rem;
}
.filter select {
  min-height: 34px;
  padding: 4px 8px;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
  background: #fff;
  color: inherit;
  font: inherit;
}
.filter select:disabled {
  background: var(--color-bg-soft, #f5f6fa);
  opacity: 0.7;
}
.layout {
  display: grid;
  grid-template-columns: minmax(280px, 360px) minmax(0, 1fr);
  gap: 16px;
}
@media (max-width: 900px) {
  .layout {
    grid-template-columns: 1fr;
  }
}
.inbox,
.thread-detail {
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 8px;
  padding: 16px;
  background: #fff;
}
.thread-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 8px;
}
.thread-summary {
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
}
.thread-summary.active {
  border-color: var(--color-primary, #2563eb);
}
.thread-button {
  width: 100%;
  text-align: left;
  background: transparent;
  border: 0;
  padding: 10px 12px;
  cursor: pointer;
  font: inherit;
  display: grid;
  gap: 4px;
}
.thread-button .title {
  overflow-wrap: anywhere;
  font-weight: 600;
}
.thread-button .meta {
  display: flex;
  gap: 8px;
  align-items: center;
  color: var(--color-text-muted, #5b6472);
  font-size: 0.85rem;
}
.badge {
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--color-bg-soft, #f5f6fa);
  border: 1px solid var(--color-border, #d0d4dc);
  font-size: 0.78rem;
}
.badge[data-status='pending'] {
  background: #fff7e6;
  border-color: #f59f00;
}
.badge[data-status='answered'] {
  background: #e7f7ee;
  border-color: #2bb673;
}
.badge[data-status='closed'] {
  background: #f0f1f3;
  border-color: #c5c8d0;
}
.thread-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}
.thread-head h2 {
  margin: 0 0 6px 0;
  overflow-wrap: anywhere;
  font-size: 1.15rem;
}
.messages {
  list-style: none;
  padding: 0;
  margin: 12px 0;
  display: grid;
  gap: 10px;
}
.message {
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
  padding: 10px 12px;
  background: var(--color-bg-soft, #fafbfd);
}
.message[data-kind='admin'] {
  background: #eef4ff;
  border-color: #c7d8ff;
}
.message[data-kind='questioner'] {
  background: #fff;
}
.message .meta {
  display: flex;
  gap: 8px;
  align-items: center;
  color: var(--color-text-muted, #5b6472);
  font-size: 0.85rem;
  margin: 0 0 6px 0;
}
.message .body {
  margin: 0;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
.reply {
  display: grid;
  gap: 8px;
  border-top: 1px solid var(--color-border, #d0d4dc);
  padding-top: 12px;
}
.reply label {
  display: grid;
  gap: 4px;
  font-size: 0.9rem;
}
.reply textarea {
  box-sizing: border-box;
  width: 100%;
  padding: 6px 8px;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
  font: inherit;
  resize: vertical;
}
.row-end {
  display: flex;
  justify-content: flex-end;
}
.btn {
  min-height: 34px;
  padding: 6px 12px;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
  background: transparent;
  font: inherit;
  cursor: pointer;
}
.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.btn-primary {
  background: var(--color-primary, #2563eb);
  color: #fff;
  border-color: transparent;
}
.btn-danger {
  background: #b42318;
  color: #fff;
  border-color: transparent;
}
.notice {
  color: var(--color-text-muted, #5b6472);
  margin: 0;
}
.notice.error {
  color: #b42318;
}
.pager {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 12px;
}
.pager-info {
  font-size: 0.85rem;
  color: var(--color-text-muted, #5b6472);
}
.thread-detail.empty {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 200px;
}
</style>
