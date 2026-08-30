<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import {
  fetchAdminThread,
  fetchModerationFilterOptions,
  hideReview,
  hideReviewReply,
  listForModeration,
  postReviewReply,
  restoreReview,
  restoreReviewReply,
} from '@/api/reviews';
import { hasPermission } from '@/api/http';
import type {
  HideReviewInput,
  ReviewFilterOptionDTO,
  ReviewThreadDTO,
  ReviewVisibility,
} from '@learn-site/contracts';
import { ChatLineSquare } from '@element-plus/icons-vue';
import ReviewReplyNode from './ReviewReplyNode.vue';
import { buildReviewReplyTree } from './reviewTree';

defineOptions({ name: 'ReviewModerateView' });

const courses = ref<ReviewFilterOptionDTO[]>([]);
const coursesLoading = ref(false);
const courseId = ref<number | null>(null);
const visibility = ref<ReviewVisibility | 'all'>('all');
const page = ref(1);
const limit = 20;

const items = ref<ReviewThreadDTO[]>([]);
const total = ref(0);
const loading = ref(false);
const loadError = ref<string | null>(null);

const active = ref<ReviewThreadDTO | null>(null);
const actionError = ref<string | null>(null);

const hideReason = ref('');
const replyBody = ref('');
const replyTo = ref<number | null>(null);
const submitting = ref(false);

const canModerate = computed(() => hasPermission('review.moderate'));
const replyTree = computed(() => buildReviewReplyTree(active.value?.replies ?? []));

const visibilityOptions: Array<{ value: ReviewVisibility | 'all'; label: string }> = [
  { value: 'all', label: '全部' },
  { value: 'public', label: '正常' },
  { value: 'hidden', label: '已隐藏' },
];

async function loadCourses(): Promise<void> {
  coursesLoading.value = true;
  try {
    const res = await fetchModerationFilterOptions();
    courses.value = res.courses;
    const firstCourse = res.courses[0];
    if (firstCourse && courseId.value === null) {
      courseId.value = firstCourse.id;
    }
  } catch {
    courses.value = [];
    courseId.value = null;
  } finally {
    coursesLoading.value = false;
  }
}

async function loadList(): Promise<void> {
  if (!courseId.value) return;
  loading.value = true;
  loadError.value = null;
  try {
    const res = await listForModeration({
      course_id: courseId.value,
      visibility: visibility.value,
      page: page.value,
      limit,
    });
    total.value = res.total;
    items.value = res.items.map((review) => ({ review, replies: [] }));
  } catch (err) {
    loadError.value = (err as Error).message || 'load_failed';
  } finally {
    loading.value = false;
  }
}

async function openReview(id: number): Promise<void> {
  actionError.value = null;
  try {
    active.value = await fetchAdminThread(id);
    hideReason.value = active.value.review.hidden_reason ?? '';
    replyBody.value = '';
    replyTo.value = null;
  } catch (err) {
    actionError.value = (err as Error).message || 'open_failed';
  }
}

async function submitHide(): Promise<void> {
  if (!active.value || submitting.value) return;
  if (!hideReason.value.trim()) {
    actionError.value = 'REASON_REQUIRED';
    return;
  }
  submitting.value = true;
  actionError.value = null;
  try {
    const input: HideReviewInput = { reason: hideReason.value.trim() };
    active.value = await hideReview(active.value.review.id, input);
    hideReason.value = active.value.review.hidden_reason ?? '';
    await loadList();
  } catch (err) {
    actionError.value = (err as Error).message || 'HIDE_FAILED';
  } finally {
    submitting.value = false;
  }
}

async function submitRestore(): Promise<void> {
  if (!active.value || submitting.value) return;
  submitting.value = true;
  actionError.value = null;
  try {
    active.value = await restoreReview(active.value.review.id);
    await loadList();
  } catch (err) {
    actionError.value = (err as Error).message || 'RESTORE_FAILED';
  } finally {
    submitting.value = false;
  }
}

async function submitReply(): Promise<void> {
  if (!active.value || submitting.value) return;
  const body = replyBody.value.trim();
  if (!body) return;
  submitting.value = true;
  actionError.value = null;
  try {
    const reply = await postReviewReply(active.value.review.id, {
      body,
      parent_id: replyTo.value,
    });
    active.value = {
      ...active.value,
      replies: [...active.value.replies, reply],
    };
    replyBody.value = '';
    replyTo.value = null;
  } catch (err) {
    actionError.value = (err as Error).message || 'REPLY_FAILED';
  } finally {
    submitting.value = false;
  }
}

function selectReply(replyId: number): void {
  replyTo.value = replyId;
  replyBody.value = '';
}

async function submitReplyHide(replyId: number, reason: string): Promise<void> {
  if (!active.value || submitting.value || !canModerate.value) return;
  submitting.value = true;
  actionError.value = null;
  try {
    active.value = await hideReviewReply(replyId, { reason: reason.trim() });
    await loadList();
  } catch (err) {
    actionError.value = (err as Error).message || 'HIDE_REPLY_FAILED';
  } finally {
    submitting.value = false;
  }
}

async function submitReplyRestore(replyId: number): Promise<void> {
  if (!active.value || submitting.value || !canModerate.value) return;
  submitting.value = true;
  actionError.value = null;
  try {
    active.value = await restoreReviewReply(replyId);
    await loadList();
  } catch (err) {
    actionError.value = (err as Error).message || 'RESTORE_REPLY_FAILED';
  } finally {
    submitting.value = false;
  }
}

watch([courseId, visibility], () => {
  active.value = null;
  if (page.value === 1) {
    void loadList();
  } else {
    page.value = 1;
  }
});
watch(page, () => {
  active.value = null;
  void loadList();
});

const ratingStars = (n: number): string => '★'.repeat(n) + '☆'.repeat(5 - n);

const formattedAt = (s: string): string => (s ? s.replace('T', ' ').slice(0, 16) : '');

onMounted(loadCourses);
</script>

<template>
  <section class="reviews-page">
    <header class="page-head">
      <div class="title-block">
        <span class="section-kicker">运营工作台 / 评价</span>
        <h1 class="display">评价管理</h1>
        <p class="subtitle">按课程查看学员评价，处理隐藏、恢复与管理员回复。</p>
      </div>
      <div class="head-metric">
        <span class="metric-label">当前列表</span>
        <strong>{{ total }}</strong>
        <span>条评价</span>
      </div>
    </header>

    <el-card class="filter-panel" shadow="never">
      <el-form inline class="filter-form" @submit.prevent>
        <el-form-item label="课程">
          <el-select
            v-model="courseId"
            class="filter-control filter-control--course"
            clearable
            filterable
            :disabled="coursesLoading"
            no-data-text="暂无可审核课程"
            placeholder="选择课程"
            placement="bottom-start"
            data-field="course_id"
          >
            <el-option v-for="c in courses" :key="c.id" :label="c.title" :value="c.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select
            v-model="visibility"
            class="filter-control filter-control--status"
            clearable
            placeholder="全部"
            placement="bottom-start"
            data-field="visibility"
          >
            <el-option
              v-for="opt in visibilityOptions"
              :key="opt.value"
              :label="opt.label"
              :value="opt.value"
            />
          </el-select>
        </el-form-item>
      </el-form>
    </el-card>

    <div class="reviews-layout">
      <el-card class="inbox" shadow="never">
        <template #header>
          <div class="panel-heading">
            <div>
              <h2>评价列表</h2>
              <p>按最近评价时间排列</p>
            </div>
            <el-tag type="info" effect="plain">{{ total }} 条</el-tag>
          </div>
        </template>
        <el-skeleton v-if="loading" :rows="5" animated />
        <el-alert
          v-else-if="loadError"
          title="评价暂时读不到"
          :description="loadError"
          type="error"
          show-icon
          :closable="false"
        />
        <el-empty
          v-else-if="items.length === 0"
          description="该筛选下暂无评价。"
          :image-size="88"
        />
        <ol v-else class="review-list">
          <li
            v-for="r in items"
            :key="r.review.id"
            class="review-item"
            :class="{ active: active && active.review.id === r.review.id }"
          >
            <button type="button" class="review-button" @click="openReview(r.review.id)">
              <span class="summary-line">
                <span class="rating" aria-hidden="true">{{ ratingStars(r.review.rating) }}</span>
                <span class="author" :title="r.review.author_name">{{ r.review.author_name }}</span>
              </span>
              <span class="meta">
                <el-tag
                  :type="r.review.visibility === 'hidden' ? 'info' : 'success'"
                  effect="light"
                  size="small"
                >
                  {{ r.review.visibility === 'hidden' ? '已隐藏' : '正常' }}
                </el-tag>
                <time>{{ formattedAt(r.review.created_at) }}</time>
              </span>
            </button>
          </li>
        </ol>
        <el-pagination
          v-if="total > limit"
          class="pager"
          background
          layout="prev, pager, next"
          :total="total"
          :page-size="limit"
          :current-page="page"
          :pager-count="5"
          @current-change="(next: number) => (page = next)"
        />
      </el-card>

      <el-card v-if="active" class="thread-detail" shadow="never">
        <template #header>
          <div class="detail-heading">
            <div class="detail-icon"><ChatLineSquare /></div>
            <div>
              <h2>评价详情</h2>
              <p>查看回复并处理隐藏、恢复</p>
            </div>
          </div>
        </template>
        <header class="detail-head">
          <span class="rating">{{ ratingStars(active.review.rating) }}</span>
          <strong>{{ active.review.author_name }}</strong>
          <span v-if="active.review.edited" class="edited">已编辑</span>
          <el-tag
            :type="active.review.visibility === 'hidden' ? 'info' : 'success'"
            effect="light"
            size="small"
          >
            {{ active.review.visibility === 'hidden' ? '已隐藏' : '正常' }}
          </el-tag>
          <time>{{ formattedAt(active.review.created_at) }}</time>
        </header>
        <p class="body">{{ active.review.body }}</p>
        <p v-if="active.review.hidden_reason" class="reason">
          隐藏原因: {{ active.review.hidden_reason }}
        </p>

        <ol v-if="replyTree.length" class="reply-tree">
          <ReviewReplyNode
            v-for="node in replyTree"
            :key="node.reply.id"
            :node="node"
            :depth="1"
            :can-moderate="canModerate"
            :can-reply="active.review.visibility === 'public'"
            :busy="submitting"
            @reply="selectReply"
            @hide="submitReplyHide"
            @restore="submitReplyRestore"
          />
        </ol>
        <p v-else class="notice">暂无回复.</p>

        <section class="actions">
          <el-form
            v-if="canModerate && active.review.visibility === 'public'"
            inline
            class="hide-form"
            @submit.prevent="submitHide"
          >
            <el-form-item label="隐藏原因">
              <el-input
                v-model="hideReason"
                clearable
                maxlength="255"
                placeholder="例: 含广告 / 违规言论"
              />
            </el-form-item>
            <el-button native-type="submit" class="btn btn-danger" :disabled="submitting">
              隐藏评价
            </el-button>
          </el-form>
          <el-button
            v-else-if="canModerate"
            class="btn btn-primary"
            :disabled="submitting"
            @click="submitRestore"
          >
            恢复评价
          </el-button>

          <el-form
            v-if="active.review.visibility === 'public'"
            class="reply-form"
            @submit.prevent="submitReply"
          >
            <el-form-item :label="replyTo === null ? '管理员回复' : `回复 #${replyTo}`">
              <el-input
                v-model="replyBody"
                clearable
                type="textarea"
                :rows="3"
                maxlength="4000"
                placeholder="写下回复"
              />
            </el-form-item>
            <div class="row-end">
              <el-button v-if="replyTo !== null" class="btn" @click="replyTo = null">
                取消回复
              </el-button>
              <el-button native-type="submit" class="btn btn-primary" :disabled="submitting">
                {{ submitting ? '提交中…' : '提交回复' }}
              </el-button>
            </div>
          </el-form>
          <p v-if="actionError" class="error">{{ actionError }}</p>
        </section>
      </el-card>
      <el-card v-else class="thread-detail empty" shadow="never">
        <template #header>
          <div class="detail-heading">
            <div class="detail-icon"><ChatLineSquare /></div>
            <div>
              <h2>评价详情</h2>
              <p>从左侧选择一条评价查看详情</p>
            </div>
          </div>
        </template>
        <el-empty description="从左侧选择一条评价查看详情。" :image-size="110" />
      </el-card>
    </div>
  </section>
</template>

<style scoped>
.reviews-page {
  display: grid;
  gap: 18px;
  min-width: 0;
}
.page-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 18px;
}
.title-block {
  min-width: 0;
}
.section-kicker {
  display: block;
  margin-bottom: 6px;
  color: #168da7;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.12em;
}
.display {
  margin: 0;
  color: #102a43;
  font-size: clamp(1.6rem, 2vw, 2rem);
  letter-spacing: -0.025em;
}
.subtitle {
  max-width: 620px;
  margin: 7px 0 0;
  color: #6b7c93;
  font-size: 13px;
}
.head-metric {
  display: grid;
  min-width: 132px;
  padding-left: 18px;
  border-left: 1px solid #d8e2eb;
  color: #6b7c93;
  font-size: 12px;
  line-height: 1.4;
}
.head-metric strong {
  color: #102a43;
  font-size: 25px;
  line-height: 1.15;
}
.filter-panel,
.inbox,
.thread-detail {
  --el-card-border-color: #dce6ef;
  --el-card-padding: 18px;
  min-width: 0;
  border-radius: 8px;
  box-shadow: 0 8px 24px rgba(16, 42, 67, 0.04);
}
.filter-panel :deep(.el-card__body) {
  min-width: 0;
  padding: 14px 18px;
}
.filter-form {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0 18px;
  min-width: 0;
}
.filter-form :deep(.el-form-item) {
  margin-bottom: 0;
}
.filter-form :deep(.el-form-item__label) {
  color: #52667a;
  font-size: 13px;
  font-weight: 600;
}
.filter-control--course {
  width: min(360px, 100%);
}
.filter-control--status {
  width: 168px;
}
.reviews-layout {
  display: grid;
  grid-template-columns: minmax(300px, 370px) minmax(0, 1fr);
  align-items: start;
  gap: 18px;
  min-width: 0;
}
.inbox :deep(.el-card__header),
.thread-detail :deep(.el-card__header) {
  padding: 16px 18px;
}
.inbox :deep(.el-card__body) {
  min-width: 0;
  overflow: hidden;
}
.panel-heading,
.detail-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.panel-heading h2,
.detail-heading h2 {
  margin: 0;
  color: #102a43;
  font-size: 15px;
}
.panel-heading p,
.detail-heading p {
  margin: 3px 0 0;
  color: #829ab1;
  font-size: 12px;
}
.detail-heading {
  justify-content: flex-start;
}
.detail-icon {
  display: grid;
  width: 34px;
  height: 34px;
  place-items: center;
  border-radius: 8px;
  color: #168da7;
  background: #e7f6f8;
  font-size: 19px;
}
.review-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 6px;
}
.review-item {
  border: 1px solid #e4ebf1;
  border-radius: 7px;
  overflow: hidden;
  transition:
    border-color 0.18s ease,
    background-color 0.18s ease;
}
.review-item.active {
  border-color: #55b8c5;
  background: #f1fafb;
}
.review-button {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  align-items: start;
  justify-items: start;
  width: 100%;
  min-width: 0;
  height: auto;
  margin: 0;
  padding: 11px 12px;
  gap: 6px;
  border: 0;
  background: transparent;
  text-align: left;
  font: inherit;
  color: inherit;
  cursor: pointer;
  white-space: normal;
}
.review-button:hover {
  color: #102a43;
  background: transparent;
}
.review-button:focus-visible {
  outline: 2px solid #55b8c5;
  outline-offset: -2px;
}
.review-button .summary-line {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  min-width: 0;
}
.review-button .rating {
  flex: 0 0 auto;
  color: #f59f00;
  font-size: 13px;
  letter-spacing: 0.02em;
}
.review-button .author {
  flex: 1 1 auto;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: #243b53;
  font-size: 13px;
  font-weight: 600;
}
.review-button .meta {
  display: flex;
  gap: 8px;
  align-items: center;
  width: 100%;
  min-width: 0;
  color: #829ab1;
  font-size: 12px;
}
.review-button .meta time {
  flex: 1 1 auto;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.detail-head {
  display: flex;
  gap: 12px;
  align-items: center;
  flex-wrap: wrap;
  padding-bottom: 14px;
  margin-bottom: 14px;
  border-bottom: 1px solid #e6edf3;
}
.detail-head .rating {
  color: #f59f00;
}
.detail-head time {
  color: #829ab1;
  font-size: 0.85rem;
}
.detail-head .edited {
  color: #829ab1;
  font-size: 0.85rem;
}
.thread-detail .body {
  overflow-wrap: anywhere;
  white-space: pre-wrap;
  line-height: 1.65;
}
.reason {
  color: #b42318;
  font-size: 0.85rem;
}
.reply-tree {
  list-style: none;
  padding: 0;
  margin: 12px 0;
  display: grid;
  gap: 8px;
}
.reply {
  border-left: 3px solid var(--color-border, #d0d4dc);
  padding-left: 10px;
}
.reply.nested {
  margin-left: 12px;
}
.reply[data-kind='admin'] {
  border-left-color: #2563eb;
}
.reply-body {
  display: grid;
  gap: 4px;
  padding: 8px 10px;
  background: var(--color-bg-soft, #fafbfd);
  border-radius: 6px;
}
.reply-body .meta {
  display: flex;
  gap: 8px;
  align-items: center;
  color: var(--color-text-muted, #5b6472);
  font-size: 0.85rem;
  margin: 0;
}
.reply-body .body {
  margin: 0;
  white-space: pre-wrap;
}
.link {
  background: transparent;
  border: 0;
  padding: 0;
  color: var(--color-primary, #2563eb);
  cursor: pointer;
  font: inherit;
}
.actions {
  display: grid;
  gap: 12px;
  border-top: 1px solid #e6edf3;
  padding-top: 12px;
}
.hide-form,
.reply-form {
  display: grid;
  gap: 8px;
}
.hide-form :deep(.el-form-item),
.reply-form :deep(.el-form-item) {
  margin-bottom: 0;
}
.hide-form :deep(.el-form-item__label),
.reply-form :deep(.el-form-item__label) {
  color: #52667a;
  font-weight: 600;
}
.btn {
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
.row-end {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
.notice {
  color: #829ab1;
  margin: 0;
}
.error {
  color: #b42318;
  font-size: 0.85rem;
  margin: 0;
}
.pager {
  justify-content: center;
  margin-top: 18px;
  padding-top: 14px;
  border-top: 1px solid #edf2f6;
}
.thread-detail.empty {
  min-height: 360px;
}
.thread-detail :deep(.el-empty) {
  min-height: 220px;
  justify-content: center;
}
.inbox :deep(.el-empty) {
  padding: 38px 0;
}
@media (max-width: 900px) {
  .head-metric {
    margin-left: 0;
  }
  .reviews-layout {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 560px) {
  .filter-form {
    display: grid;
    grid-template-columns: 1fr;
    gap: 4px;
  }
  .filter-form :deep(.el-form-item) {
    display: grid;
    grid-template-columns: 58px minmax(0, 1fr);
    align-items: center;
  }
  .filter-control--course,
  .filter-control--status {
    width: 100%;
  }
  .head-metric {
    width: 100%;
    padding: 10px 0 0;
    border-top: 1px solid #d8e2eb;
    border-left: 0;
  }
}
</style>
