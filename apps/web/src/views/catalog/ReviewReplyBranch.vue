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
        <el-tag size="small" type="success" effect="plain">公开</el-tag>
        <span v-if="node.edited">已编辑</span>
        <time :datetime="node.created_at">{{ formattedAt(node.created_at) }}</time>
      </p>
      <p class="body">{{ node.body }}</p>
      <el-button
        v-if="canReply && depth < 3"
        link
        type="primary"
        size="small"
        @click="emit('reply', node.id)"
      >
        回复
      </el-button>
    </article>

    <el-collapse v-if="node.children.length" class="children" :model-value="['children']">
      <el-collapse-item name="children">
        <template #title>{{ node.children.length }} 条回复</template>
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
      </el-collapse-item>
    </el-collapse>
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
.body {
  margin: 0;
  line-height: 1.6;
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}
.reply-body > .el-button {
  justify-self: start;
  margin-left: 0;
}
.children :deep(.el-collapse-item__header) {
  min-height: 36px;
  height: auto;
  color: var(--muted);
  font-size: 0.82rem;
  background: transparent;
}
.children ol {
  display: grid;
  gap: 10px;
  margin: 0;
  padding: 0;
  list-style: none;
}
</style>
