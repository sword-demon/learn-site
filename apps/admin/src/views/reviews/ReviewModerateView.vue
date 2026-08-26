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

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / limit)));

onMounted(loadCourses);
</script>

<template>
  <section class="reviews-page">
    <header class="head">
      <h1 class="display">评价管理</h1>
      <div class="filter-row">
        <label class="filter">
          课程
          <select v-model.number="courseId" :disabled="coursesLoading">
            <option v-if="!courses.length" :value="null">暂无可审核课程</option>
            <option v-for="c in courses" :key="c.id" :value="c.id">{{ c.title }}</option>
          </select>
        </label>
        <label class="filter">
          状态
          <select v-model="visibility">
            <option v-for="opt in visibilityOptions" :key="opt.value" :value="opt.value">
              {{ opt.label }}
            </option>
          </select>
        </label>
      </div>
    </header>

    <div class="layout">
      <aside class="list-pane">
        <p v-if="loading" class="notice">加载中…</p>
        <p v-else-if="loadError" class="notice error">暂时读不到 ({{ loadError }}).</p>
        <ol v-else-if="items.length" class="review-list">
          <li
            v-for="r in items"
            :key="r.review.id"
            class="review-item"
            :class="{ active: active && active.review.id === r.review.id }"
          >
            <button type="button" class="review-button" @click="openReview(r.review.id)">
              <span class="rating">{{ ratingStars(r.review.rating) }}</span>
              <span class="author">{{ r.review.author_name }}</span>
              <span class="body">{{ r.review.body }}</span>
              <span class="meta">
                <span class="badge" :data-visibility="r.review.visibility">
                  {{ r.review.visibility === 'hidden' ? '已隐藏' : '正常' }}
                </span>
                <time>{{ formattedAt(r.review.created_at) }}</time>
              </span>
            </button>
          </li>
        </ol>
        <p v-else class="notice">该筛选下暂无评价.</p>
        <nav v-if="total > limit" class="pager">
          <button type="button" class="btn" :disabled="page <= 1" @click="page--">上一页</button>
          <span class="pager-info">{{ page }} / {{ totalPages }}</span>
          <button type="button" class="btn" :disabled="page >= totalPages" @click="page++">
            下一页
          </button>
        </nav>
      </aside>

      <article v-if="active" class="detail-pane">
        <header class="detail-head">
          <span class="rating">{{ ratingStars(active.review.rating) }}</span>
          <strong>{{ active.review.author_name }}</strong>
          <span v-if="active.review.edited" class="edited">已编辑</span>
          <span class="badge" :data-visibility="active.review.visibility">
            {{ active.review.visibility === 'hidden' ? '已隐藏' : '正常' }}
          </span>
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
          <form
            v-if="canModerate && active.review.visibility === 'public'"
            class="hide-form"
            @submit.prevent="submitHide"
          >
            <label>
              隐藏原因
              <input
                v-model="hideReason"
                type="text"
                maxlength="255"
                placeholder="例: 含广告 / 违规言论"
              />
            </label>
            <button type="submit" class="btn btn-danger" :disabled="submitting">隐藏评价</button>
          </form>
          <button
            v-else-if="canModerate"
            type="button"
            class="btn btn-primary"
            :disabled="submitting"
            @click="submitRestore"
          >
            恢复评价
          </button>

          <form
            v-if="active.review.visibility === 'public'"
            class="reply-form"
            @submit.prevent="submitReply"
          >
            <label>
              {{ replyTo === null ? '管理员回复' : `回复 #${replyTo}` }}
              <textarea v-model="replyBody" rows="3" maxlength="4000" placeholder="写下回复" />
            </label>
            <div class="row-end">
              <button v-if="replyTo !== null" type="button" class="btn" @click="replyTo = null">
                取消回复
              </button>
              <button type="submit" class="btn btn-primary" :disabled="submitting">
                {{ submitting ? '提交中…' : '提交回复' }}
              </button>
            </div>
          </form>
          <p v-if="actionError" class="error">{{ actionError }}</p>
        </section>
      </article>
      <article v-else class="detail-pane empty">
        <p class="notice">从左侧选择一条评价查看详情.</p>
      </article>
    </div>
  </section>
</template>

<style scoped>
.reviews-page {
  display: grid;
  gap: 16px;
}
.head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}
.display {
  margin: 0;
  font-size: 1.4rem;
}
.filter-row {
  display: flex;
  gap: 12px;
  align-items: center;
}
.filter {
  display: grid;
  gap: 4px;
  font-size: 0.9rem;
}
.filter select {
  padding: 4px 8px;
}
.layout {
  display: grid;
  grid-template-columns: minmax(280px, 360px) 1fr;
  gap: 16px;
}
@media (max-width: 900px) {
  .layout {
    grid-template-columns: 1fr;
  }
}
.list-pane,
.detail-pane {
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 8px;
  padding: 16px;
  background: #fff;
}
.review-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 8px;
}
.review-item {
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
}
.review-item.active {
  border-color: var(--color-primary, #2563eb);
}
.review-button {
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
.review-button .rating {
  color: #f59f00;
}
.review-button .meta {
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
.badge[data-visibility='public'] {
  background: #e7f7ee;
  border-color: #2bb673;
}
.badge[data-visibility='hidden'] {
  background: #f0f1f3;
  border-color: #c5c8d0;
}
.detail-head {
  display: flex;
  gap: 12px;
  align-items: center;
}
.detail-head .rating {
  color: #f59f00;
}
.detail-head time {
  color: var(--color-text-muted, #5b6472);
  font-size: 0.85rem;
}
.detail-pane .body {
  white-space: pre-wrap;
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
  border-top: 1px solid var(--color-border, #d0d4dc);
  padding-top: 12px;
}
.hide-form,
.reply-form {
  display: grid;
  gap: 8px;
}
.hide-form label,
.reply-form label {
  display: grid;
  gap: 4px;
  font-size: 0.9rem;
}
.hide-form input,
.reply-form textarea {
  width: 100%;
  padding: 6px 8px;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
  font: inherit;
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
  color: var(--color-text-muted, #5b6472);
  margin: 0;
}
.notice.error {
  color: #b42318;
}
.error {
  color: #b42318;
  font-size: 0.85rem;
  margin: 0;
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
.detail-pane.empty {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 200px;
}
</style>
