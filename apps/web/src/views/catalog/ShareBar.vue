<script setup lang="ts">
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Link, Picture, Star, StarFilled } from '@element-plus/icons-vue';
import type { SharePosterDTO } from '@learn-site/contracts';
import { hasTokens } from '@/api/http';
import { addFavorite } from '@/api/learner';
import { createShareLink, createSharePoster } from '@/api/share';
import { loginPathFor } from '@/router/guards';
import SharePosterDialog from '@/components/SharePosterDialog.vue';

const props = withDefaults(
  defineProps<{
    courseId: number;
    courseTitle: string;
    variant?: 'bar' | 'panel';
  }>(),
  { variant: 'bar' },
);

const route = useRoute();
const router = useRouter();

const busyAction = ref<'favorite' | 'link' | 'poster' | null>(null);
const stableLink = ref<string | null>(null);
const copied = ref(false);
const favorited = ref(false);
const poster = ref<SharePosterDTO | null>(null);
const posterOpen = ref(false);
const message = ref<string | null>(null);

async function favorite(): Promise<void> {
  if (busyAction.value || favorited.value) return;
  if (!hasTokens()) {
    await router.push(loginPathFor(route.fullPath));
    return;
  }
  busyAction.value = 'favorite';
  message.value = null;
  try {
    await addFavorite(props.courseId);
    favorited.value = true;
  } catch (error) {
    const code = (error as { code?: string }).code;
    if (code === 'UNAUTHENTICATED') {
      await router.push(loginPathFor(route.fullPath));
      return;
    }
    message.value = '收藏失败，请稍后重试';
  } finally {
    busyAction.value = null;
  }
}

async function ensureStableLink(): Promise<string> {
  if (stableLink.value) return stableLink.value;
  const result = await createShareLink(props.courseId);
  stableLink.value = new URL(result.share_url, window.location.origin).toString();
  return stableLink.value;
}

async function copyLink(): Promise<void> {
  if (busyAction.value) return;
  busyAction.value = 'link';
  message.value = null;
  copied.value = false;
  try {
    const url = await ensureStableLink();
    await navigator.clipboard.writeText(url);
    copied.value = true;
  } catch {
    message.value = stableLink.value ? '请手动复制课程链接' : '无法生成分享链接';
  } finally {
    busyAction.value = null;
  }
}

async function generatePoster(): Promise<void> {
  if (busyAction.value) return;
  busyAction.value = 'poster';
  message.value = null;
  try {
    await ensureStableLink();
    const result = await createSharePoster(props.courseId);
    if (result.render_status === 'failed') {
      message.value = '海报生成失败，分享链接仍可使用';
      return;
    }
    poster.value = result;
    posterOpen.value = true;
  } catch {
    message.value = stableLink.value ? '海报生成失败，分享链接仍可使用' : '无法生成分享链接';
  } finally {
    busyAction.value = null;
  }
}
</script>

<template>
  <section
    class="share-bar"
    :class="{ 'share-bar--panel': variant === 'panel' }"
    :aria-label="`${courseTitle}的收藏与分享`"
  >
    <template v-if="variant === 'panel'">
      <div class="row">
        <el-button
          class="panel-action"
          data-action="favorite"
          :disabled="favorited"
          :loading="busyAction === 'favorite'"
          :icon="favorited ? StarFilled : Star"
          @click="favorite"
        >
          {{ favorited ? '已收藏' : '收藏' }}
        </el-button>
        <el-button
          class="panel-action"
          data-action="generate-poster"
          :disabled="busyAction !== null"
          :loading="busyAction === 'poster'"
          :icon="Picture"
          @click="generatePoster"
        >
          分享海报
        </el-button>
      </div>
      <el-button
        link
        type="primary"
        data-action="copy-link"
        :disabled="busyAction !== null"
        :loading="busyAction === 'link'"
        :icon="Link"
        @click="copyLink"
      >
        {{ copied ? '链接已复制' : '复制课程链接' }}
      </el-button>
    </template>
    <template v-else>
      <span class="share-label">收藏与分享</span>
      <div class="share-actions">
        <el-button
          data-action="favorite"
          :disabled="favorited"
          :loading="busyAction === 'favorite'"
          :icon="favorited ? StarFilled : Star"
          @click="favorite"
        >
          {{ favorited ? '已收藏' : '收藏课程' }}
        </el-button>
        <el-button
          data-action="copy-link"
          :disabled="busyAction !== null"
          :loading="busyAction === 'link'"
          :icon="Link"
          @click="copyLink"
        >
          {{ copied ? '已复制' : '复制链接' }}
        </el-button>
        <el-button
          type="primary"
          data-action="generate-poster"
          :disabled="busyAction !== null"
          :loading="busyAction === 'poster'"
          :icon="Picture"
          @click="generatePoster"
        >
          生成海报
        </el-button>
      </div>
    </template>
    <el-alert
      v-if="message"
      class="share-message"
      :title="message"
      type="error"
      :closable="false"
    />
    <p v-if="stableLink && variant !== 'panel'" class="share-result">
      <a :href="stableLink">{{ stableLink }}</a>
    </p>
    <SharePosterDialog v-model="posterOpen" :poster="poster" />
  </section>
</template>

<style scoped>
.share-bar {
  display: grid;
  grid-template-columns: auto 1fr;
  align-items: center;
  gap: 10px 18px;
  padding: 18px 0;
  border-top: 1px solid var(--line);
}

.share-bar--panel {
  grid-template-columns: 1fr;
  gap: 8px;
  padding: 0;
  border-top: 0;
}

.share-label {
  color: var(--muted);
  font-size: 0.78rem;
}

.share-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.panel-action {
  flex: 1;
  margin-left: 0;
}

.share-message,
.share-result {
  grid-column: 2;
  max-width: 100%;
  margin: 0;
  color: var(--muted);
  font-size: 0.78rem;
  overflow-wrap: anywhere;
}

.share-message :deep(.el-alert__content) {
  min-width: 0;
}

.share-bar--panel .share-message {
  grid-column: 1;
}

.share-message {
  color: #9e3f2c;
}

.share-result a {
  color: var(--pine);
}

@media (max-width: 600px) {
  .share-bar:not(.share-bar--panel) {
    grid-template-columns: 1fr;
  }

  .share-message,
  .share-result {
    grid-column: 1;
  }
}
</style>
