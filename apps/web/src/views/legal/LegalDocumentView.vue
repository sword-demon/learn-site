<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import MarkdownRenderer from '@/components/MarkdownRenderer.vue';
import { isLegalDocumentKey, legalDocuments } from '@/content/legal';

defineOptions({ name: 'LegalDocumentView' });

const route = useRoute();

const documentKey = computed(() => {
  const key = route.meta.legalKey;
  return typeof key === 'string' && isLegalDocumentKey(key) ? key : null;
});

const document = computed(() =>
  documentKey.value === null ? null : legalDocuments[documentKey.value],
);
</script>

<template>
  <main v-if="document" class="page legal-page" data-view="legal-document">
    <header class="head">
      <p class="eyebrow"><span class="eyebrow-rule" />{{ document.eyebrow }}</p>
      <h1 class="display">{{ document.title }}</h1>
      <p class="lede">{{ document.lede }}</p>
      <p class="legal-page__updated mono">最后更新：{{ document.updatedAt }}</p>
    </header>

    <article class="legal-page__body">
      <MarkdownRenderer :html="document.html" />
    </article>
  </main>

  <main v-else class="page legal-page" data-view="legal-document-missing">
    <el-empty description="页面不存在">
      <router-link to="/">
        <el-button type="primary">返回首页</el-button>
      </router-link>
    </el-empty>
  </main>
</template>

<style scoped>
.legal-page__updated {
  margin: 8px 0 0;
  font-size: 12px;
  color: var(--ink-3);
}

.legal-page__body {
  padding: 24px 28px;
  border: 1px solid var(--line);
  border-radius: var(--r);
  background: var(--card);
  box-shadow: var(--shadow);
}

@media (max-width: 640px) {
  .legal-page__body {
    padding: 18px 16px;
  }
}
</style>
