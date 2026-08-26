<script setup lang="ts">
import type { ReviewReplyNode } from '@/views/catalog/reviewTreeModel';

defineOptions({ name: 'ReviewReplyBranch' });

defineProps<{
  node: ReviewReplyNode;
  depth: number;
  canReply: boolean;
}>();

const emit = defineEmits<{
  reply: [replyId: number];
}>();

function authorLabel(node: ReviewReplyNode): string {
  return node.viewer_owned ? '我' : node.author_name;
}

function formattedAt(value: string): string {
  return value ? value.replace('T', ' ').slice(0, 16) : '';
}
</script>

<template>
  <li class="reply" :data-kind="node.kind" :data-depth="depth">
    <article class="reply-body">
      <p class="meta">
        <strong>{{ authorLabel(node) }}</strong>
        <span class="status">公开</span>
        <span v-if="node.edited">已编辑</span>
        <time :datetime="node.created_at">{{ formattedAt(node.created_at) }}</time>
      </p>
      <p class="body">{{ node.body }}</p>
      <button
        v-if="canReply && depth < 3"
        type="button"
        class="link"
        @click="emit('reply', node.id)"
      >
        回复
      </button>
    </article>

    <details v-if="node.children.length" class="children" open>
      <summary>{{ node.children.length }} 条回复</summary>
      <ol>
        <ReviewReplyBranch
          v-for="child in node.children"
          :key="child.id"
          :node="child"
          :depth="depth + 1"
          :can-reply="canReply"
          @reply="emit('reply', $event)"
        />
      </ol>
    </details>
  </li>
</template>

<style scoped>
.reply {
  display: grid;
  gap: 8px;
  padding-left: 12px;
  border-left: 3px solid var(--color-border, #d0d4dc);
}
.reply[data-kind='admin'] {
  border-left-color: #2563eb;
}
.reply[data-kind='system'] {
  border-left-color: #94a3b8;
}
.reply-body {
  display: grid;
  gap: 6px;
  padding: 10px 12px;
  background: var(--color-bg-soft, #fafbfd);
  border-radius: 6px;
}
.meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 10px;
  align-items: center;
  margin: 0;
  color: var(--color-text-muted, #5b6472);
  font-size: 0.82rem;
}
.status {
  padding: 1px 6px;
  border: 1px solid #acd5bd;
  border-radius: 4px;
  color: #17683a;
  background: #edf8f1;
}
.body {
  margin: 0;
  line-height: 1.6;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
.link {
  justify-self: start;
  padding: 0;
  border: 0;
  color: var(--color-primary, #2563eb);
  background: transparent;
  font: inherit;
  cursor: pointer;
}
.children summary {
  width: fit-content;
  margin-bottom: 8px;
  color: var(--color-text-muted, #5b6472);
  font-size: 0.82rem;
  cursor: pointer;
}
.children ol {
  display: grid;
  gap: 10px;
  margin: 0;
  padding: 0;
  list-style: none;
}
</style>
