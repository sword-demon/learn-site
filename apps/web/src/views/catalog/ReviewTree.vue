<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { ReviewDTO, ReviewListDTO, ReviewThreadDTO } from '@learn-site/contracts';
import {
  deleteCourseReview,
  fetchCourseReviews,
  fetchReviewThread,
  postCourseReview,
  postReviewReply,
  updateCourseReview,
} from '@/api/learner';
import ReviewReplyBranch from '@/views/catalog/ReviewReplyBranch.vue';
import { buildReplyForest, findViewerReview } from '@/views/catalog/reviewTreeModel';

const props = defineProps<{
  courseId: number;
  authorized: boolean;
}>();

const pageSize = 10;
const list = ref<ReviewListDTO>({
  items: [],
  viewer_review: null,
  total: 0,
  page: 1,
  limit: pageSize,
});
const loading = ref(false);
const loadError = ref<string | null>(null);

const composing = ref(false);
const editingReviewId = ref<number | null>(null);
const newRating = ref(5);
const newBody = ref('');
const submitting = ref(false);
const submitError = ref<string | null>(null);

const openThread = ref<ReviewThreadDTO | null>(null);
const threadLoading = ref(false);
const threadError = ref<string | null>(null);
const replyBody = ref('');
const replyTo = ref<number | null>(null);
const replySubmitting = ref(false);
const replyError = ref<string | null>(null);

const confirmingDelete = ref(false);
const deleting = ref(false);

const ownReview = computed(() => list.value.viewer_review ?? findViewerReview(list.value.items));
const replyForest = computed(() => buildReplyForest(openThread.value?.replies ?? []));
const totalPages = computed(() => Math.max(1, Math.ceil(list.value.total / list.value.limit)));
const reviewActionLabel = computed(() => (ownReview.value ? '编辑我的评价' : '我要评价'));
const composerTitle = computed(() =>
  editingReviewId.value === null ? '发表评价' : '编辑我的评价',
);
const replyTargetLabel = computed(() => {
  if (replyTo.value === null || !openThread.value) return '回复这条评价';
  const target = openThread.value.replies.find((reply) => reply.id === replyTo.value);
  if (!target) return '回复讨论';
  return `回复 ${target.viewer_owned ? '我' : target.author_name}`;
});

async function loadList(page = 1): Promise<void> {
  if (props.courseId <= 0) return;
  loading.value = true;
  loadError.value = null;
  try {
    list.value = await fetchCourseReviews(props.courseId, { page, limit: pageSize });
  } catch (error) {
    loadError.value = errorMessage(error, '评价暂时无法加载，请稍后再试。');
  } finally {
    loading.value = false;
  }
}

async function openReview(id: number): Promise<void> {
  threadLoading.value = true;
  threadError.value = null;
  replyError.value = null;
  confirmingDelete.value = false;
  try {
    openThread.value = await fetchReviewThread(id);
    replyBody.value = '';
    replyTo.value = null;
  } catch (error) {
    threadError.value = errorMessage(error, '这条评价暂时无法打开。');
  } finally {
    threadLoading.value = false;
  }
}

function beginCompose(): void {
  if (ownReview.value) {
    beginEdit(ownReview.value);
    return;
  }
  editingReviewId.value = null;
  newRating.value = 5;
  newBody.value = '';
  submitError.value = null;
  composing.value = true;
}

function beginEdit(review: ReviewDTO): void {
  editingReviewId.value = review.id;
  newRating.value = review.rating;
  newBody.value = review.body;
  submitError.value = null;
  composing.value = true;
}

function cancelCompose(): void {
  composing.value = false;
  editingReviewId.value = null;
  submitError.value = null;
}

async function submitReview(): Promise<void> {
  if (submitting.value) return;
  submitError.value = null;
  const body = newBody.value.trim();
  if (!body) {
    submitError.value = '请填写评价正文。';
    return;
  }
  submitting.value = true;
  try {
    const thread =
      editingReviewId.value === null
        ? await postCourseReview(props.courseId, { rating: newRating.value, body })
        : await updateCourseReview(editingReviewId.value, { rating: newRating.value, body });
    openThread.value = thread;
    cancelCompose();
    await loadList(list.value.page);
  } catch (error) {
    if (errorCode(error) === 'REVIEW_ALREADY_EXISTS') {
      await loadList(1);
      if (ownReview.value) beginEdit(ownReview.value);
      submitError.value = '你已经评价过这门课，可以直接修改原评价。';
    } else {
      submitError.value = errorMessage(error, '评价提交失败，请稍后再试。');
    }
  } finally {
    submitting.value = false;
  }
}

function startReply(parentId: number | null): void {
  replyTo.value = parentId;
  replyBody.value = '';
  replyError.value = null;
}

async function submitReply(): Promise<void> {
  if (!openThread.value || replySubmitting.value) return;
  const body = replyBody.value.trim();
  if (!body) {
    replyError.value = '请填写回复内容。';
    return;
  }
  replySubmitting.value = true;
  replyError.value = null;
  try {
    const reply = await postReviewReply(openThread.value.review.id, {
      body,
      parent_id: replyTo.value,
    });
    openThread.value.replies.push(reply);
    replyBody.value = '';
    replyTo.value = null;
  } catch (error) {
    replyError.value = errorMessage(error, '回复提交失败，请稍后再试。');
  } finally {
    replySubmitting.value = false;
  }
}

async function removeReview(): Promise<void> {
  const review = openThread.value?.review;
  if (!review?.viewer_owned || deleting.value) return;
  deleting.value = true;
  threadError.value = null;
  try {
    await deleteCourseReview(review.id);
    openThread.value = null;
    confirmingDelete.value = false;
    cancelCompose();
    const nextPage =
      list.value.items.length === 1 && list.value.page > 1 ? list.value.page - 1 : list.value.page;
    await loadList(nextPage);
  } catch (error) {
    threadError.value = errorMessage(error, '评价删除失败，请稍后再试。');
  } finally {
    deleting.value = false;
  }
}

function errorCode(error: unknown): string {
  return error instanceof Error ? error.message : '';
}

function errorMessage(error: unknown, fallback: string): string {
  const messages: Record<string, string> = {
    REVIEW_REQUIRES_COMPLETED_LESSON: '完成至少一个课节后才能发表评价。',
    REVIEW_ALREADY_EXISTS: '你已经评价过这门课，可以直接修改原评价。',
    NOT_AUTHORIZED: '取得课程访问权后才能参与评价讨论。',
    NOT_REVIEW_OWNER: '只能修改或删除自己的评价。',
    REPLY_DEPTH_EXCEEDED: '回复最多支持三级。',
    TOKEN_EXPIRED: '登录状态已过期，请重新登录。',
    UNAUTHENTICATED: '登录后才能执行此操作。',
  };
  return messages[errorCode(error)] ?? fallback;
}

function ratingStars(rating: number): string {
  return '★'.repeat(rating) + '☆'.repeat(5 - rating);
}

function authorLabel(review: ReviewDTO): string {
  return review.viewer_owned ? '我' : review.author_name;
}

function formattedAt(value: string): string {
  return value ? value.replace('T', ' ').slice(0, 16) : '';
}

watch(
  () => props.courseId,
  () => {
    openThread.value = null;
    cancelCompose();
    void loadList(1);
  },
  { immediate: true },
);
</script>

<template>
  <section class="review-tree" aria-labelledby="review-heading">
    <header class="head">
      <div>
        <h2 id="review-heading" class="display">学员评价</h2>
        <p class="count">{{ list.total }} 条公开评价</p>
      </div>
      <button v-if="authorized" type="button" class="btn btn-primary" @click="beginCompose">
        {{ reviewActionLabel }}
      </button>
    </header>

    <p v-if="!authorized" class="notice">
      取得课程访问权并完成至少一个课节后，可以发表评价和参与讨论。
    </p>
    <p v-else class="notice">完成至少一个课节后即可发表评价。</p>

    <form v-if="composing && authorized" class="composer" @submit.prevent="submitReview">
      <div class="composer-head">
        <h3>{{ composerTitle }}</h3>
        <button type="button" class="link" @click="cancelCompose">取消</button>
      </div>
      <label>
        评分
        <select v-model.number="newRating">
          <option v-for="rating in [5, 4, 3, 2, 1]" :key="rating" :value="rating">
            {{ rating }} 星
          </option>
        </select>
      </label>
      <label>
        正文
        <textarea
          v-model="newBody"
          rows="4"
          maxlength="4000"
          placeholder="分享课程内容、节奏或学习收获"
        />
      </label>
      <p v-if="submitError" class="error" role="alert">{{ submitError }}</p>
      <div class="row-end">
        <button type="submit" class="btn btn-primary" :disabled="submitting">
          {{ submitting ? '保存中…' : editingReviewId === null ? '提交评价' : '保存修改' }}
        </button>
      </div>
    </form>

    <p v-if="loading" class="notice" aria-live="polite">评价加载中…</p>
    <p v-else-if="loadError" class="error" role="alert">{{ loadError }}</p>
    <template v-else>
      <ol v-if="list.items.length" class="review-list">
        <li v-for="review in list.items" :key="review.id" class="review-summary">
          <button
            type="button"
            class="review-button"
            :class="{ selected: openThread?.review.id === review.id }"
            @click="openReview(review.id)"
          >
            <span class="summary-head">
              <strong>{{ authorLabel(review) }}</strong>
              <span class="status">公开</span>
              <span v-if="review.edited">已编辑</span>
              <time :datetime="review.created_at">{{ formattedAt(review.created_at) }}</time>
            </span>
            <span class="rating" :aria-label="`${review.rating} 星`">
              {{ ratingStars(review.rating) }}
            </span>
            <span class="body">{{ review.body }}</span>
          </button>
        </li>
      </ol>
      <p v-else class="notice empty">还没有公开评价。</p>

      <nav v-if="totalPages > 1" class="pagination" aria-label="评价分页">
        <button
          type="button"
          class="btn"
          :disabled="list.page <= 1"
          @click="loadList(list.page - 1)"
        >
          上一页
        </button>
        <span>第 {{ list.page }} / {{ totalPages }} 页</span>
        <button
          type="button"
          class="btn"
          :disabled="list.page >= totalPages"
          @click="loadList(list.page + 1)"
        >
          下一页
        </button>
      </nav>
    </template>

    <p v-if="threadLoading" class="notice" aria-live="polite">讨论加载中…</p>
    <p v-else-if="threadError" class="error" role="alert">{{ threadError }}</p>

    <article v-if="openThread && !threadLoading" class="thread" aria-label="评价讨论">
      <header class="thread-head">
        <div class="thread-meta">
          <span class="summary-head">
            <strong>{{ authorLabel(openThread.review) }}</strong>
            <span class="status">公开</span>
            <span v-if="openThread.review.edited">已编辑</span>
            <time :datetime="openThread.review.created_at">
              {{ formattedAt(openThread.review.created_at) }}
            </time>
          </span>
          <span class="rating" :aria-label="`${openThread.review.rating} 星`">
            {{ ratingStars(openThread.review.rating) }}
          </span>
          <p class="body">{{ openThread.review.body }}</p>
        </div>
        <div v-if="openThread.review.viewer_owned" class="owner-actions">
          <button type="button" class="btn" @click="beginEdit(openThread.review)">编辑</button>
          <button type="button" class="btn danger" @click="confirmingDelete = true">删除</button>
        </div>
      </header>

      <div v-if="confirmingDelete" class="delete-confirm" role="alert">
        <p>删除后评价及其全部回复都无法恢复。</p>
        <div class="row-end">
          <button type="button" class="btn" @click="confirmingDelete = false">取消</button>
          <button type="button" class="btn danger" :disabled="deleting" @click="removeReview">
            {{ deleting ? '删除中…' : '确认删除' }}
          </button>
        </div>
      </div>

      <section class="discussion" aria-labelledby="discussion-heading">
        <h3 id="discussion-heading">讨论</h3>
        <ol v-if="replyForest.length" class="reply-tree">
          <ReviewReplyBranch
            v-for="root in replyForest"
            :key="root.id"
            :node="root"
            :depth="1"
            :can-reply="authorized"
            @reply="startReply"
          />
        </ol>
        <p v-else class="notice empty">还没有回复。</p>
      </section>

      <form v-if="authorized" class="reply-form" @submit.prevent="submitReply">
        <div class="composer-head">
          <h3>{{ replyTargetLabel }}</h3>
          <button v-if="replyTo !== null" type="button" class="link" @click="startReply(null)">
            取消定向回复
          </button>
        </div>
        <label>
          回复内容
          <textarea v-model="replyBody" rows="3" maxlength="4000" placeholder="留下你的看法" />
        </label>
        <p v-if="replyError" class="error" role="alert">{{ replyError }}</p>
        <div class="row-end">
          <button type="submit" class="btn btn-primary" :disabled="replySubmitting">
            {{ replySubmitting ? '提交中…' : '提交回复' }}
          </button>
        </div>
      </form>
    </article>
  </section>
</template>

<style scoped>
.review-tree {
  display: grid;
  gap: 18px;
}

.head,
.composer-head,
.thread-head,
.summary-head,
.owner-actions,
.pagination,
.row-end {
  display: flex;
  align-items: center;
}

.head,
.thread-head {
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
}

.display,
.composer-head h3,
.discussion h3,
.reply-form h3 {
  margin: 0;
  color: var(--pine-deep);
  font-family: var(--font-display);
  font-size: 1.15rem;
}

.count,
.notice {
  margin: 4px 0 0;
  color: var(--muted);
}

.btn {
  min-height: 36px;
  padding: 7px 12px;
  border: 1px solid var(--line);
  border-radius: 5px;
  background: var(--surface);
  color: inherit;
  font: inherit;
  cursor: pointer;
}

.btn:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.btn-primary {
  border-color: var(--accent);
  background: var(--accent);
  color: #fffefa;
}

.btn.danger {
  border-color: #d99a8c;
  color: #9e3f2c;
}

.link {
  padding: 0;
  border: 0;
  background: transparent;
  color: var(--accent);
  font: inherit;
  cursor: pointer;
}

.composer,
.reply-form,
.delete-confirm {
  display: grid;
  gap: 12px;
  padding: 16px;
  border-left: 3px solid var(--accent);
  background: var(--surface-muted);
}

.composer-head {
  justify-content: space-between;
  gap: 12px;
}

.composer label,
.reply-form label {
  display: grid;
  gap: 6px;
  color: var(--pine-deep);
  font-size: 0.82rem;
  font-weight: 700;
}

.composer textarea,
.reply-form textarea,
.composer select {
  width: 100%;
  min-height: 38px;
  padding: 8px 10px;
  border: 1px solid var(--line);
  border-radius: 5px;
  background: var(--surface);
  font: inherit;
}

.composer textarea,
.reply-form textarea {
  resize: vertical;
}

.row-end {
  justify-content: flex-end;
  gap: 8px;
  flex-wrap: wrap;
}

.review-list,
.reply-tree {
  display: grid;
  gap: 2px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.review-list {
  border-top: 1px solid var(--line);
}

.review-summary {
  border-bottom: 1px solid var(--line);
}

.review-button {
  display: grid;
  width: 100%;
  gap: 7px;
  padding: 14px 7px;
  border: 0;
  border-left: 3px solid transparent;
  background: transparent;
  text-align: left;
  font: inherit;
  cursor: pointer;
  transition:
    background-color 0.2s ease,
    padding-left 0.2s ease;
}

.review-button:hover,
.review-button.selected {
  padding-left: 12px;
  border-left-color: var(--accent);
  background: var(--surface-muted);
}

.summary-head {
  gap: 6px 10px;
  flex-wrap: wrap;
  color: var(--muted);
  font-size: 0.78rem;
}

.summary-head strong {
  color: var(--pine-deep);
}

.status {
  padding: 2px 6px;
  border: 1px solid #bad4c1;
  border-radius: 999px;
  color: var(--pine-deep);
  background: #eef7f0;
  font-size: 0.72rem;
}

.rating {
  color: var(--accent);
  letter-spacing: 0;
}

.body {
  margin: 0;
  line-height: 1.65;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}

.pagination {
  justify-content: center;
  gap: 12px;
  flex-wrap: wrap;
  color: var(--muted);
  font-size: 0.8rem;
}

.thread {
  display: grid;
  gap: 18px;
  padding-top: 18px;
  border-top: 1px solid var(--line);
}

.thread-head {
  align-items: start;
}

.thread-meta {
  display: grid;
  flex: 1 1 420px;
  gap: 8px;
  min-width: 0;
}

.owner-actions {
  gap: 8px;
  flex-wrap: wrap;
}

.delete-confirm {
  border-left-color: #c75c44;
  background: #fff5f1;
}

.delete-confirm p {
  margin: 0;
}

.discussion {
  display: grid;
  gap: 10px;
}

.empty {
  padding: 10px 0;
}

.error {
  margin: 0;
  color: #9e3f2c;
  font-size: 0.82rem;
}

@media (max-width: 640px) {
  .head .btn-primary {
    width: 100%;
  }

  .thread-head {
    align-items: stretch;
  }

  .owner-actions {
    width: 100%;
  }

  .owner-actions .btn {
    flex: 1;
  }

  .composer,
  .reply-form,
  .delete-confirm {
    padding: 12px;
  }
}
</style>
