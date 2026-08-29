<template>
  <main class="page messages-page">
    <div class="list-head">
      <h2>消息中心</h2>
      <span v-if="!loading && !errorMessage" class="cnt">
        {{ unreadCount }} 条未读 · {{ messages.length }} 条
      </span>
    </div>
    <p class="muted small" style="margin: 0 0 14px">
      公告与站内信来自系统通知，问答 / 进度 / 授权为课程相关提醒。
    </p>

    <p v-if="loading" class="notice">消息加载中…</p>
    <p v-else-if="errorMessage" class="notice error">{{ errorMessage }}</p>
    <div v-else-if="messages.length === 0" class="empty">
      <span class="serif">没有消息</span>
      公告、站内信与问答、进度、授权等系统通知会出现在这里
    </div>
    <div v-else class="panel">
      <article
        v-for="message in messages"
        :key="message.id"
        class="msg-row"
        :class="{ unread: !message.read }"
      >
        <span class="msg-dot" :class="{ read: message.read }" aria-hidden="true" />
        <div>
          <div class="mtitle">
            <span v-if="!message.read" class="tag tag-sale" style="font-size: 10px; margin-right: 6px">
              未读
            </span>
            <span class="tag" :class="kindTagClass(message.kind)" style="font-size: 10px; margin-right: 6px">
              {{ kindLabel(message.kind) }}
            </span>
            {{ message.title }}
          </div>
          <div v-if="message.body" class="mbody">{{ message.body }}</div>
          <router-link
            v-if="message.resource_available && resourcePath(message)"
            :to="resourcePath(message)!"
            class="btn-link"
            style="margin-top: 6px; display: inline-block"
          >
            查看关联内容
          </router-link>
          <span v-else-if="message.resource_type" class="small muted">关联内容已不可用</span>
        </div>
        <div style="display: grid; gap: 8px; justify-items: end">
          <time class="when small muted">{{ message.created_at }}</time>
          <button
            v-if="!message.read"
            type="button"
            class="btn btn-ghost btn-sm"
            :data-read-id="message.id"
            :disabled="readingId === message.id"
            @click="markRead(message.id)"
          >
            标记已读
          </button>
        </div>
      </article>
    </div>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import type { LearnerNotificationDTO } from '@learn-site/contracts';
import { listNotifications, markNotificationRead } from '@/api/notifications';

defineOptions({ name: 'MessagesView' });

const messages = ref<LearnerNotificationDTO[]>([]);
const loading = ref(true);
const errorMessage = ref('');
const readingId = ref<number | null>(null);

const unreadCount = computed(() => messages.value.filter((message) => !message.read).length);

function kindLabel(kind: LearnerNotificationDTO['kind']): string {
  return {
    question_update: '问答',
    progress_reset: '进度',
    entitlement_revoked: '授权',
    announcement: '公告',
    internal_message: '站内信',
  }[kind];
}

function kindTagClass(kind: LearnerNotificationDTO['kind']): string {
  return {
    question_update: 'tag-trial',
    progress_reset: 'tag-free',
    entitlement_revoked: 'tag-free',
    announcement: 'tag-sale',
    internal_message: 'tag-on',
  }[kind];
}

function resourcePath(message: LearnerNotificationDTO): string | null {
  if (message.resource_type === 'course' && message.resource_id) {
    return `/courses/${message.resource_id}`;
  }
  if (message.resource_type === 'question') {
    const payload = message.payload as { course_id?: number } | null;
    return payload?.course_id ? `/courses/${payload.course_id}` : null;
  }
  return null;
}

async function markRead(id: number): Promise<void> {
  readingId.value = id;
  try {
    await markNotificationRead(id);
    const target = messages.value.find((message) => message.id === id);
    if (target) target.read = true;
  } catch {
    errorMessage.value = '消息状态更新失败，请稍后重试。';
  } finally {
    readingId.value = null;
  }
}

onMounted(async () => {
  try {
    messages.value = (await listNotifications()).items;
  } catch {
    errorMessage.value = '消息加载失败，请稍后重试。';
  } finally {
    loading.value = false;
  }
});
</script>
