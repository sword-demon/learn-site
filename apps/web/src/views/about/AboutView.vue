<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { storeToRefs } from 'pinia';
import MarkdownRenderer from '@/components/MarkdownRenderer.vue';
import { useHomeStore } from '@/stores/home';
import { hasRichHtml } from '@/utils/richHtml';

defineOptions({ name: 'AboutView' });

const homeStore = useHomeStore();
const { intro, loading, error } = storeToRefs(homeStore);

const title = computed(() => intro.value?.title.trim() || '关于拾阶学社');
const subtitle = computed(() => intro.value?.subtitle.trim() || '拾级而上 · 日进一阶');
const showBody = computed(() => hasRichHtml(intro.value?.body_html));
const contactEmail = computed(() => intro.value?.contact_email.trim() ?? '');
const updatedAt = computed(() => intro.value?.updated_at?.trim() ?? '');

const fallbackHtml = `
<p>拾阶学社是一个专注结构化在线学习的平台。我们提供系统化的课程目录与学习地图，帮助学员拾级而上、日进一阶。</p>
<p>如有课程合作或商务咨询，请通过页脚邮箱与我们联系。</p>
`;

onMounted(() => {
  void homeStore.load();
});
</script>

<template>
  <main class="page about-page" data-view="about">
    <header class="head">
      <p class="eyebrow"><span class="eyebrow-rule" />拾阶学社</p>
      <h1 class="display">{{ title }}</h1>
      <p class="lede">{{ subtitle }}</p>
      <p v-if="updatedAt" class="about-page__updated mono">最后更新：{{ updatedAt }}</p>
    </header>

    <el-skeleton v-if="loading" animated :rows="5" />

    <el-alert
      v-else-if="error"
      title="站点介绍暂时读不到，请稍后再试。"
      type="error"
      :closable="false"
      show-icon
    />

    <article v-else class="about-page__body">
      <MarkdownRenderer v-if="showBody" :html="intro!.body_html" />
      <MarkdownRenderer v-else :html="fallbackHtml" />
      <p v-if="contactEmail" class="about-page__contact">
        课程合作：<a :href="`mailto:${contactEmail}`">{{ contactEmail }}</a>
      </p>
    </article>
  </main>
</template>

<style scoped>
.about-page__updated {
  margin: 8px 0 0;
  font-size: 12px;
  color: var(--ink-3);
}

.about-page__body {
  padding: 24px 28px;
  border: 1px solid var(--line);
  border-radius: var(--r);
  background: var(--card);
  box-shadow: var(--shadow);
}

.about-page__contact {
  margin: 1.5em 0 0;
  padding-top: 1em;
  border-top: 1px solid var(--line);
  color: var(--ink-2);
}

.about-page__contact a {
  color: var(--seal);
}

@media (max-width: 640px) {
  .about-page__body {
    padding: 18px 16px;
  }
}
</style>
