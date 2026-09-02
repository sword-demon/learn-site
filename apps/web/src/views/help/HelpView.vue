<script setup lang="ts">
import MarkdownRenderer from '@/components/MarkdownRenderer.vue';
import { helpDocument } from '@/content/help';

defineOptions({ name: 'HelpView' });
</script>

<template>
  <main class="page help-page" data-view="help">
    <header class="head">
      <p class="eyebrow"><span class="eyebrow-rule" />{{ helpDocument.eyebrow }}</p>
      <h1 class="display">{{ helpDocument.title }}</h1>
      <p class="lede">{{ helpDocument.lede }}</p>
      <p class="help-page__updated mono">最后更新：{{ helpDocument.updatedAt }}</p>
    </header>

    <article class="help-page__body">
      <MarkdownRenderer :html="helpDocument.html" />
    </article>
  </main>
</template>

<style scoped>
.help-page__updated {
  margin: 8px 0 0;
  font-size: 12px;
  color: var(--ink-3);
}

.help-page__body {
  padding: 24px 28px;
  border: 1px solid var(--line);
  border-radius: var(--r);
  background: var(--card);
  box-shadow: var(--shadow);
}

@media (max-width: 640px) {
  .help-page__body {
    padding: 18px 16px;
  }
}
</style>
