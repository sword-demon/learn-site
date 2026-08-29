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
  if (openThread.value?.review.id === id) {
    openThread.value = null;
    threadError.value = null;
    return;
  }
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

function renderStars(rating: number): Array<{ on: boolean }> {
  return Array.from({ length: 5 }, (_, index) => ({ on: index < Math.round(rating) }));
}

const ratingSummary = computed(() => {
  if (!list.value.total) return null;
  const items = list.value.items;
  if (!items.length) return { avg: 0, count: list.value.total };
  const avg = items.reduce((sum, review) => sum + review.rating, 0) / items.length;
  return { avg, count: list.value.total };
});

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
    <div v-if="ratingSummary" class="panel rate-summary">
      <div>
        <div class="score">{{ ratingSummary.avg ? ratingSummary.avg.toFixed(1) : '—' }}</div>
        <span class="stars" aria-hidden="true">
          <span
            v-for="(star, index) in renderStars(ratingSummary.avg)"
            :key="index"
            :class="{ off: !star.on }"
            >★</span
          >
        </span>
      </div>
      <div class="small muted">
        {{ ratingSummary.count }} 条学员评价<br />仅统计当前公开有效的评价
      </div>
    </div>

    <header class="review-toolbar">
      <p v-if="!authorized" class="form-note">
        取得课程访问权并完成至少一个课节后，可以发表评价和参与讨论。
      </p>
      <p v-else class="form-note">完成至少一个课节后即可发表评价。</p>
      <button v-if="authorized" type="button" class="btn btn-primary btn-sm" @click="beginCompose">
        {{ reviewActionLabel }}
      </button>
    </header>

    <form v-if="composing && authorized" class="panel rv-form" @submit.prevent="submitReview">
      <h3 class="serif" style="margin: 0; font-size: 16px">{{ composerTitle }}</h3>
      <label class="field">
        评分
        <span class="star-input" role="radiogroup" aria-label="评分">
          <button
            v-for="rating in [1, 2, 3, 4, 5]"
            :key="rating"
            type="button"
            :class="{ on: newRating >= rating }"
            :aria-label="`${rating} 星`"
            @click="newRating = rating"
          >
            ★
          </button>
        </span>
      </label>
      <label class="field">
        正文
        <textarea
          v-model="newBody"
          rows="4"
          maxlength="4000"
          placeholder="分享课程内容、节奏或学习收获"
        />
      </label>
      <p v-if="submitError" class="notice error" role="alert">{{ submitError }}</p>
      <div class="review-actions">
        <button type="button" class="btn btn-ghost btn-sm" @click="cancelCompose">取消</button>
        <button type="submit" class="btn btn-primary btn-sm" :disabled="submitting">
          {{ submitting ? '保存中…' : editingReviewId === null ? '提交评价' : '保存修改' }}
        </button>
      </div>
    </form>

    <p v-if="loading" class="notice" aria-live="polite">评价加载中…</p>
    <p v-else-if="loadError" class="notice error" role="alert">{{ loadError }}</p>
    <template v-else>
      <div v-if="list.items.length">
        <article
          v-for="review in list.items"
          :key="review.id"
          class="review"
          :class="{ open: openThread?.review.id === review.id }"
        >
          <div class="review-head">
            <span class="stars" :aria-label="`${review.rating} 星`">{{ ratingStars(review.rating) }}</span>
            <span class="who">{{ authorLabel(review) }}</span>
            <time class="when" :datetime="review.created_at">{{ formattedAt(review.created_at) }}</time>
            <span v-if="review.edited" class="small muted">已编辑</span>
          </div>
          <div class="review-body">{{ review.body }}</div>
          <button type="button" class="btn-link reply-toggle" @click="openReview(review.id)">
            {{ openThread?.review.id === review.id ? '收起讨论' : '查看讨论' }}
          </button>

          <div v-if="openThread?.review.id === review.id && !threadLoading" class="thread">
            <div v-if="openThread.review.viewer_owned" class="review-actions">
              <button type="button" class="btn btn-ghost btn-sm" @click="beginEdit(openThread.review)">
                编辑
              </button>
              <button type="button" class="btn btn-ghost btn-sm" @click="confirmingDelete = true">
                删除
              </button>
            </div>

            <div v-if="confirmingDelete" class="panel delete-confirm" role="alert">
              <p class="form-note">删除后评价及其全部回复都无法恢复。</p>
              <div class="review-actions">
                <button type="button" class="btn btn-ghost btn-sm" @click="confirmingDelete = false">
                  取消
                </button>
                <button
                  type="button"
                  class="btn btn-primary btn-sm"
                  :disabled="deleting"
                  @click="removeReview"
                >
                  {{ deleting ? '删除中…' : '确认删除' }}
                </button>
              </div>
            </div>

            <div v-if="replyForest.length" class="reply">
              <ol class="reply-tree">
                <ReviewReplyBranch
                  v-for="root in replyForest"
                  :key="root.id"
                  :node="root"
                  :depth="1"
                  :can-reply="authorized"
                  @reply="startReply"
                />
              </ol>
            </div>
            <p v-else class="form-note">还没有回复。</p>

            <form v-if="authorized" class="rv-form" @submit.prevent="submitReply">
              <h4 class="serif" style="margin: 0; font-size: 15px">{{ replyTargetLabel }}</h4>
              <label class="field">
                回复内容
                <textarea v-model="replyBody" rows="3" maxlength="4000" placeholder="留下你的看法" />
              </label>
              <p v-if="replyError" class="notice error" role="alert">{{ replyError }}</p>
              <div class="review-actions">
                <button
                  v-if="replyTo !== null"
                  type="button"
                  class="btn btn-ghost btn-sm"
                  @click="startReply(null)"
                >
                  取消定向回复
                </button>
                <button type="submit" class="btn btn-primary btn-sm" :disabled="replySubmitting">
                  {{ replySubmitting ? '提交中…' : '提交回复' }}
                </button>
              </div>
            </form>
          </div>
        </article>
      </div>
      <div v-else class="empty">
        <span class="serif">还没有评价</span>
        完成一个课节后，来写下第一条吧
      </div>

      <nav v-if="totalPages > 1" class="review-pagination" aria-label="评价分页">
        <button type="button" class="btn btn-ghost btn-sm" :disabled="list.page <= 1" @click="loadList(list.page - 1)">
          上一页
        </button>
        <span class="small muted">第 {{ list.page }} / {{ totalPages }} 页</span>
        <button
          type="button"
          class="btn btn-ghost btn-sm"
          :disabled="list.page >= totalPages"
          @click="loadList(list.page + 1)"
        >
          下一页
        </button>
      </nav>
    </template>

    <p v-if="threadLoading" class="notice" aria-live="polite">讨论加载中…</p>
    <p v-else-if="threadError" class="notice error" role="alert">{{ threadError }}</p>
  </section>
</template>

<style scoped>
.review-tree {
  display: grid;
  gap: 18px;
}

.review-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}

.review-toolbar .form-note {
  margin: 0;
  flex: 1;
}

.review-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
  flex-wrap: wrap;
}

.review.open {
  background: var(--card-2);
}

.review-pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  flex-wrap: wrap;
}

.reply-tree {
  margin: 0;
  padding: 0;
  list-style: none;
}

.delete-confirm {
  margin-top: 12px;
  padding: 14px 16px;
  border-color: #e7b8ab;
  background: #fff5f1;
}

.delete-confirm .form-note {
  margin: 0 0 10px;
  color: #9e3f2c;
}

.rv-form .field textarea {
  width: 100%;
  min-height: 88px;
  resize: vertical;
  line-height: 1.7;
  font-family: inherit;
  font-size: 14px;
  border: 1px solid var(--line-2);
  border-radius: 3px;
  background: var(--card);
  color: var(--ink);
  padding: 9px 12px;
}

.rv-form .field textarea:focus {
  outline: 2px solid rgba(181, 64, 44, 0.25);
  border-color: var(--seal);
}

@media (max-width: 640px) {
  .review-toolbar {
    align-items: stretch;
    flex-direction: column;
  }

  .review-toolbar .btn {
    width: 100%;
  }
}
</style>
