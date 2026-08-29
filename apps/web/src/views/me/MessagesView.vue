<template>
  <main class="page account-page messages-page">
    <header class="account-head">
      <div>
        <p class="eyebrow"><span class="eyebrow-rule" />个人书架 · 消息</p>
        <h1 class="display">消息</h1>
        <p class="lede">课程通知和学习提醒会集中放在这里。</p>
      </div>
    </header>
    <p v-if="loading" class="notice">消息加载中…</p>
    <p v-else-if="errorMessage" class="notice error">{{ errorMessage }}</p>
    <section v-else-if="messages.length === 0" class="empty-state">
      <span class="empty-mark" aria-hidden="true">—</span>
      <h2 class="display">暂时没有新消息</h2>
      <p>有新的课程动态时会出现在这里。</p>
      <router-link to="/" class="btn btn-primary">
        回到课程目录 <span aria-hidden="true">→</span>
      </router-link>
    </section>
    <ol v-else class="message-list">
      <li v-for="message in messages" :key="message.id" :class="{ unread: !message.read }">
        <div class="message-copy">
          <div class="message-meta">
            <span>{{ kindLabel(message.kind) }}</span>
            <time>{{ message.created_at }}</time>
          </div>
          <h2>{{ message.title }}</h2>
          <p v-if="message.body">{{ message.body }}</p>
          <router-link
            v-if="message.resource_available && resourcePath(message)"
            :to="resourcePath(message)!"
          >
            查看关联内容
          </router-link>
          <span v-else-if="message.resource_type" class="unavailable">关联内容已不可用</span>
        </div>
        <button
          v-if="!message.read"
          type="button"
          class="btn"
          :data-read-id="message.id"
          :disabled="readingId === message.id"
          @click="markRead(message.id)"
        >
          标记已读
        </button>
      </li>
    </ol>
  </main>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import type { LearnerNotificationDTO } from '@learn-site/contracts';
import { listNotifications, markNotificationRead } from '@/api/notifications';

defineOptions({ name: 'MessagesView' });

const messages = ref<LearnerNotificationDTO[]>([]);
const loading = ref(true);
const errorMessage = ref('');
const readingId = ref<number | null>(null);

function kindLabel(kind: LearnerNotificationDTO['kind']): string {
  return {
    question_update: '问答',
    progress_reset: '学习进度',
    entitlement_revoked: '课程授权',
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

<style scoped>
.account-page {
  display: grid;
  gap: 28px;
}
.account-head {
  padding: 18px 0 26px;
  border-bottom: 1px solid var(--line);
}
.account-head .eyebrow {
  margin-bottom: 16px;
}
.account-head .display {
  margin: 0 0 9px;
  color: var(--pine-deep);
}
.empty-state {
  display: grid;
  justify-items: start;
  gap: 12px;
  padding: 48px 6px 60px;
  border-bottom: 1px solid var(--line);
}
.empty-mark {
  color: var(--accent);
  font: 700 2.4rem var(--font-mono);
  line-height: 1;
}
.empty-state h2 {
  margin: 0;
  color: var(--pine-deep);
  font-size: 1.55rem;
}
.empty-state p {
  margin: 0 0 6px;
  color: var(--muted);
  font-size: 0.85rem;
}
.message-list {
  display: grid;
  gap: 0;
  margin: 0;
  padding: 0;
  list-style: none;
  border-top: 1px solid var(--line);
}
.message-list li {
  display: flex;
  align-items: start;
  justify-content: space-between;
  gap: 24px;
  padding: 22px 6px;
  border-bottom: 1px solid var(--line);
}
.message-list li.unread {
  border-left: 3px solid var(--accent);
  padding-left: 14px;
}
.message-copy {
  display: grid;
  gap: 7px;
  min-width: 0;
}
.message-copy h2,
.message-copy p {
  margin: 0;
}
.message-copy h2 {
  font-size: 1rem;
}
.message-copy p,
.message-meta,
.unavailable {
  color: var(--muted);
  font-size: 0.8rem;
}
.message-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}
.unavailable {
  display: inline-block;
}
</style>
