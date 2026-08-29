<script setup lang="ts">
defineOptions({ name: 'MarkdownRenderer' });

defineProps<{ html: string }>();
</script>

<template>
  <!-- eslint-disable-next-line vue/no-v-html -- server-side HtmlSanitizer whitelist before persist -->
  <div class="rich-html prose" v-html="html" />
</template>

<style scoped>
.rich-html {
  color: var(--ink-soft);
  line-height: 1.9;
  word-break: break-word;
}

.rich-html :deep(:first-child) {
  margin-top: 0;
}

.rich-html :deep(:last-child) {
  margin-bottom: 0;
}

.rich-html :deep(p) {
  margin: 0 0 1em;
}

.rich-html :deep(h1),
.rich-html :deep(h2),
.rich-html :deep(h3),
.rich-html :deep(h4),
.rich-html :deep(h5),
.rich-html :deep(h6) {
  margin: 1.6em 0 0.65em;
  color: var(--pine-deep);
  font-family: var(--font-display);
  font-weight: 600;
  line-height: 1.35;
}

.rich-html :deep(h1) {
  font-size: 1.65rem;
}

.rich-html :deep(h2) {
  font-size: 1.4rem;
}

.rich-html :deep(h3) {
  font-size: 1.2rem;
}

.rich-html :deep(h4),
.rich-html :deep(h5),
.rich-html :deep(h6) {
  font-size: 1.05rem;
}

.rich-html :deep(ul),
.rich-html :deep(ol) {
  margin: 0 0 1em;
  padding-left: 1.4em;
}

.rich-html :deep(li) {
  margin: 0.35em 0;
}

.rich-html :deep(a) {
  color: var(--pine);
  text-decoration: underline;
  text-underline-offset: 2px;
}

.rich-html :deep(a:hover) {
  color: var(--accent-deep);
}

.rich-html :deep(img) {
  display: block;
  max-width: 100%;
  height: auto;
  margin: 1.2em 0;
  border: 1px solid var(--line);
  border-radius: 4px;
}

.rich-html :deep(pre) {
  overflow-x: auto;
  margin: 1.2em 0;
  padding: 15px;
  border: 1px solid #24362f;
  border-radius: 5px;
  background: #1d2a25;
  color: #eff7ef;
}

.rich-html :deep(code) {
  padding: 1px 4px;
  border-radius: 3px;
  background: var(--surface-muted);
  font-family: var(--font-mono);
  font-size: 0.9em;
}

.rich-html :deep(pre code) {
  padding: 0;
  background: transparent;
  color: inherit;
  font-size: 0.88rem;
}

.rich-html :deep(blockquote) {
  margin: 1.2em 0;
  padding: 4px 0 4px 17px;
  border-left: 3px solid var(--accent);
  color: var(--muted);
}

.rich-html :deep(hr) {
  margin: 1.6em 0;
  border: 0;
  border-top: 1px solid var(--line);
}

.rich-html :deep(table) {
  width: 100%;
  margin: 1.2em 0;
  border-collapse: collapse;
  font-size: 0.92rem;
}

.rich-html :deep(th),
.rich-html :deep(td) {
  padding: 8px 10px;
  border: 1px solid var(--line);
  text-align: left;
}

.rich-html :deep(th) {
  background: var(--surface-muted);
  color: var(--pine-deep);
  font-weight: 600;
}

.rich-html :deep(strong),
.rich-html :deep(b) {
  color: var(--pine-deep);
  font-weight: 700;
}

.rich-html :deep(em),
.rich-html :deep(i) {
  font-style: italic;
}
</style>
