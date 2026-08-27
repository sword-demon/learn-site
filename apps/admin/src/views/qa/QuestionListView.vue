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
import { ChatDotRound, Close, Promotion } from '@element-plus/icons-vue';

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
const questionItems = computed(() => inbox.value?.items ?? []);

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
    courseOptions.value = options.courses ?? [];
    lessonOptions.value = options.lessons ?? [];
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
    const result = await fetchInbox({
      status: statusFilter.value,
      ...(courseFilter.value === '' ? {} : { course_id: courseFilter.value }),
      ...(lessonFilter.value === '' ? {} : { lesson_id: lessonFilter.value }),
      page: page.value,
      limit,
    });
    inbox.value = { ...result, items: result.items ?? [] };
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
    const thread = await fetchThread(question.id);
    active.value = { ...thread, messages: thread.messages ?? [] };
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
    const thread = await answerQuestion(active.value.question.id, { body });
    active.value = { ...thread, messages: thread.messages ?? [] };
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

function statusType(status: QuestionStatus): 'warning' | 'success' | 'info' {
  if (status === 'pending') return 'warning';
  if (status === 'answered') return 'success';
  return 'info';
}

onMounted(() => {
  void Promise.all([loadFilterOptions(), loadInbox()]);
});
</script>

<template>
  <section class="qa-page">
    <header class="page-head">
      <div class="title-block">
        <span class="section-kicker">运营工作台 / 问答</span>
        <h1 class="display">问答管理</h1>
        <p class="subtitle">集中处理学员提问，保持课程讨论清晰、及时、可追溯。</p>
      </div>
      <div class="head-metric">
        <span class="metric-label">当前队列</span>
        <strong>{{ inbox?.total ?? 0 }}</strong>
        <span>条问答</span>
      </div>
    </header>

    <el-card class="filter-panel" shadow="never">
      <el-form inline class="filter-form" @submit.prevent>
        <el-form-item label="课程">
          <el-select
            v-model="courseFilter"
            class="filter-control"
            placeholder="全部课程"
            clearable
            :disabled="filterOptionsLoading"
            :teleported="false"
            data-field="course_id"
            @change="changeCourse"
          >
            <el-option
              v-for="course in courseOptions"
              :key="course.id"
              :label="course.title"
              :value="course.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="课节">
          <el-select
            v-model="lessonFilter"
            class="filter-control"
            placeholder="全部课节"
            clearable
            :disabled="courseFilter === '' || filterOptionsLoading"
            :teleported="false"
            data-field="lesson_id"
            @change="changeFilter"
          >
            <el-option
              v-for="lesson in lessonOptions"
              :key="lesson.id"
              :label="lesson.title"
              :value="lesson.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select
            v-model="statusFilter"
            class="filter-control"
            :teleported="false"
            data-field="status"
            @change="changeFilter"
          >
            <el-option
              v-for="option in statusOptions"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            />
          </el-select>
        </el-form-item>
      </el-form>
      <el-alert
        v-if="filterError"
        class="inline-alert"
        title="筛选项暂时读不到"
        :description="filterError"
        type="warning"
        show-icon
        :closable="false"
      />
    </el-card>

    <div class="layout">
      <el-card class="inbox" shadow="never">
        <template #header>
          <div class="panel-heading">
            <div>
              <h2>问答队列</h2>
              <p>按最近提问时间排列</p>
            </div>
            <el-tag type="info" effect="plain">{{ inbox?.total ?? 0 }} 条</el-tag>
          </div>
        </template>
        <el-skeleton v-if="loading" :rows="5" animated />
        <el-alert
          v-else-if="listError"
          title="问答暂时读不到"
          :description="listError"
          type="error"
          show-icon
          :closable="false"
        />
        <el-empty
          v-else-if="questionItems.length === 0"
          description="当前筛选下暂无问答。"
          :image-size="88"
        />
        <ol v-else class="thread-list">
          <li
            v-for="question in questionItems"
            :key="question.id"
            class="thread-summary"
            :class="{ active: active && active.question.id === question.id }"
          >
            <el-button text class="thread-button" @click="openThread(question)">
              <span class="title">{{ question.title }}</span>
              <span class="meta">
                <el-tag :type="statusType(question.status)" effect="light" size="small">
                  {{ statusBadge(question.status) }}
                </el-tag>
                <time>{{ formattedAt(question.created_at) }}</time>
              </span>
            </el-button>
          </li>
        </ol>
        <el-pagination
          v-if="inbox && inbox.total > limit"
          class="pager"
          background
          layout="prev, pager, next"
          :total="inbox.total"
          :page-size="limit"
          :current-page="page"
          :pager-count="5"
          @current-change="changePage"
        />
      </el-card>

      <el-card class="thread-detail" :class="{ empty: !active }" shadow="never">
        <template #header>
          <div class="detail-heading">
            <div class="detail-icon"><ChatDotRound /></div>
            <div>
              <h2>问答详情</h2>
              <p>查看上下文并给出处理结果</p>
            </div>
          </div>
        </template>
        <el-skeleton v-if="threadLoading" :rows="6" animated />
        <el-alert
          v-else-if="threadError"
          title="问答详情暂时读不到"
          :description="threadError"
          type="error"
          show-icon
          :closable="false"
        />
        <template v-else-if="active">
          <header class="thread-head">
            <div>
              <h2>{{ active.question.title }}</h2>
              <el-tag :type="statusType(active.question.status)" effect="light">
                {{ statusBadge(active.question.status) }}
              </el-tag>
            </div>
            <el-button
              v-if="active.question.status !== 'closed'"
              class="btn-danger"
              type="danger"
              plain
              :disabled="actionBusy"
              @click="submitClose"
            >
              <el-icon><Close /></el-icon>
              {{ closeSubmitting ? '关闭中...' : '关闭问答' }}
            </el-button>
          </header>
          <el-alert
            v-if="closeError"
            title="关闭失败"
            :description="`（${closeError}）`"
            type="error"
            show-icon
            :closable="false"
          />
          <ol class="messages">
            <li
              v-for="message in active.messages"
              :key="message.id"
              class="message"
              :data-kind="message.kind"
            >
              <div class="meta">
                <strong>{{ authorLabel(message) }}</strong>
                <time>{{ formattedAt(message.created_at) }}</time>
              </div>
              <p class="body">{{ message.body }}</p>
            </li>
          </ol>
          <el-form
            v-if="active.question.status !== 'closed'"
            class="reply"
            @submit.prevent="submitReply"
          >
            <el-form-item label="回复内容" required>
              <el-input
                v-model="replyBody"
                type="textarea"
                :rows="5"
                maxlength="4000"
                show-word-limit
                placeholder="给出明确回答，也可以补充相关提示"
              />
            </el-form-item>
            <el-alert
              v-if="answerError"
              title="回复未提交"
              :description="answerError"
              type="error"
              show-icon
              :closable="false"
            />
            <div class="row-end">
              <el-button
                type="primary"
                native-type="submit"
                :loading="answerSubmitting"
              >
                <el-icon><Promotion /></el-icon>
                {{ answerSubmitting ? '提交中...' : '提交回复' }}
              </el-button>
            </div>
          </el-form>
          <el-empty v-else description="该问答已关闭，无法继续追问。" :image-size="80" />
        </template>
        <el-empty v-else description="从左侧选择一条问答查看详情。" :image-size="110" />
      </el-card>
    </div>
  </section>
</template>

<style scoped>
.qa-page { display: grid; gap: 18px; min-width: 0; }
.page-head { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 18px; }
.title-block { min-width: 0; }
.section-kicker { display: block; margin-bottom: 6px; color: #168da7; font-size: 11px; font-weight: 700; letter-spacing: 0.12em; }
.display { margin: 0; color: #102a43; font-size: clamp(1.6rem, 2vw, 2rem); letter-spacing: -0.025em; }
.subtitle { max-width: 620px; margin: 7px 0 0; color: #6b7c93; font-size: 13px; }
.head-metric { display: grid; min-width: 132px; padding-left: 18px; border-left: 1px solid #d8e2eb; color: #6b7c93; font-size: 12px; line-height: 1.4; }
.head-metric strong { color: #102a43; font-size: 25px; line-height: 1.15; }
.filter-panel,
.inbox,
.thread-detail { --el-card-border-color: #dce6ef; --el-card-padding: 18px; border-radius: 8px; box-shadow: 0 8px 24px rgba(16, 42, 67, 0.04); }
.filter-panel :deep(.el-card__body) { padding: 14px 18px; }
.filter-form { display: flex; flex-wrap: wrap; align-items: center; gap: 0 18px; }
.filter-form :deep(.el-form-item) { margin-bottom: 0; }
.filter-form :deep(.el-form-item__label) { color: #52667a; font-size: 13px; font-weight: 600; }
.filter-control { width: 190px; }
.inline-alert { margin-top: 12px; }
.layout { display: grid; grid-template-columns: minmax(300px, 370px) minmax(0, 1fr); align-items: start; gap: 18px; }
.inbox :deep(.el-card__header),
.thread-detail :deep(.el-card__header) { padding: 16px 18px; }
.panel-heading,
.detail-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.panel-heading h2,
.detail-heading h2 { margin: 0; color: #102a43; font-size: 15px; }
.panel-heading p,
.detail-heading p { margin: 3px 0 0; color: #829ab1; font-size: 12px; }
.detail-heading { justify-content: flex-start; }
.detail-icon { display: grid; width: 34px; height: 34px; place-items: center; border-radius: 8px; color: #168da7; background: #e7f6f8; font-size: 19px; }
.thread-list { display: grid; gap: 6px; padding: 0; margin: 0; list-style: none; }
.thread-summary { border: 1px solid #e4ebf1; border-radius: 7px; transition: border-color 0.18s ease, background-color 0.18s ease; }
.thread-summary.active { border-color: #55b8c5; background: #f1fafb; }
.thread-button { display: grid; width: 100%; height: auto; min-height: 64px; padding: 11px 12px; justify-content: flex-start; gap: 4px; text-align: left; white-space: normal; }
.thread-button:hover { color: #102a43; background: transparent; }
.thread-button .title { overflow-wrap: anywhere; color: #243b53; font-size: 13px; font-weight: 600; }
.thread-button .meta { display: flex; gap: 8px; align-items: center; color: #829ab1; font-size: 12px; }
.thread-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; padding-bottom: 14px; margin-bottom: 14px; border-bottom: 1px solid #e6edf3; }
.thread-head h2 { margin: 0 0 8px; overflow-wrap: anywhere; color: #102a43; font-size: 1.15rem; }
.messages { display: grid; gap: 10px; padding: 0; margin: 12px 0; list-style: none; }
.message { padding: 12px 14px; border: 1px solid #e1eaf0; border-radius: 7px; background: #f8fbfc; }
.message[data-kind='admin'] { border-color: #c4e8ea; background: #eef9fa; }
.message[data-kind='questioner'] { background: #fff; }
.message .meta { display: flex; gap: 8px; align-items: center; margin: 0 0 6px; color: #829ab1; font-size: 12px; }
.message .body { margin: 0; overflow-wrap: anywhere; white-space: pre-wrap; line-height: 1.65; }
.reply { display: grid; gap: 8px; padding-top: 16px; border-top: 1px solid #e6edf3; }
.reply :deep(.el-form-item) { margin-bottom: 0; }
.reply :deep(.el-form-item__label) { color: #52667a; font-weight: 600; }
.row-end { display: flex; justify-content: flex-end; }
.pager { justify-content: center; margin-top: 18px; padding-top: 14px; border-top: 1px solid #edf2f6; }
.thread-detail.empty { min-height: 360px; }
.thread-detail :deep(.el-empty) { min-height: 220px; justify-content: center; }
.inbox :deep(.el-empty) { padding: 38px 0; }
.inline-alert :deep(.el-alert__title),
.thread-detail :deep(.el-alert__title),
.inbox :deep(.el-alert__title) { font-size: 13px; }
@media (max-width: 900px) {
  .head-metric { margin-left: 0; }
  .layout { grid-template-columns: 1fr; }
}
@media (max-width: 560px) {
  .filter-form { display: grid; grid-template-columns: 1fr; gap: 4px; }
  .filter-form :deep(.el-form-item) { display: grid; grid-template-columns: 58px minmax(0, 1fr); align-items: center; }
  .filter-control { width: 100%; }
  .head-metric { width: 100%; padding: 10px 0 0; border-top: 1px solid #d8e2eb; border-left: 0; }
}
</style>
