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
  border-left: 2px solid var(--line);
}
.reply[data-kind='admin'] {
  border-left-color: var(--pine);
}
.reply[data-kind='system'] {
  border-left-color: var(--muted);
}
.reply-body {
  display: grid;
  gap: 6px;
  padding: 10px 12px;
  border-bottom: 1px solid var(--line);
  background: var(--surface);
}
.meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 10px;
  align-items: center;
  margin: 0;
  color: var(--muted);
  font-size: 0.82rem;
}
.status {
  padding: 2px 6px;
  border: 1px solid #bad4c1;
  border-radius: 999px;
  color: var(--pine-deep);
  background: #eef7f0;
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
  color: var(--accent);
  background: transparent;
  font: inherit;
  cursor: pointer;
}
.children summary {
  width: fit-content;
  margin-bottom: 8px;
  color: var(--muted);
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
