<script setup lang="ts">
import { ref } from 'vue';
import type { ReviewReplyTreeNode } from './reviewTree';

defineOptions({ name: 'ReviewReplyNode' });

defineProps<{
  node: ReviewReplyTreeNode;
  depth: number;
  canModerate: boolean;
  canReply: boolean;
  busy: boolean;
}>();

const emit = defineEmits<{
  reply: [id: number];
  hide: [id: number, reason: string];
  restore: [id: number];
}>();

const expanded = ref(true);
const askingToHide = ref(false);
const hideReason = ref('');

function submitHide(id: number): void {
  const reason = hideReason.value.trim();
  if (!reason) return;
  emit('hide', id, reason);
  askingToHide.value = false;
  hideReason.value = '';
}

const formattedAt = (value: string): string => (value ? value.replace('T', ' ').slice(0, 16) : '');
</script>

<template>
  <li
    class="reply-node"
    :data-depth="depth"
    :data-kind="node.reply.kind"
    :data-visibility="node.reply.visibility"
  >
    <article class="reply-content">
      <header class="reply-head">
        <el-button
          v-if="node.children.length"
          class="collapse-button"
          :title="expanded ? '收起子回复' : '展开子回复'"
          :aria-label="expanded ? '收起子回复' : '展开子回复'"
          @click="expanded = !expanded"
        >
          {{ expanded ? '−' : '+' }}
        </el-button>
        <strong>{{ node.reply.author_name }}</strong>
        <span v-if="node.reply.edited" class="edited">已编辑</span>
        <span class="badge" :data-visibility="node.reply.visibility">
          {{ node.reply.visibility === 'hidden' ? '已隐藏' : '正常' }}
        </span>
        <time>{{ formattedAt(node.reply.created_at) }}</time>
      </header>

      <p class="reply-body">{{ node.reply.body }}</p>
      <p v-if="node.reply.hidden_reason" class="reason">隐藏原因: {{ node.reply.hidden_reason }}</p>

      <div class="reply-tools">
        <el-button
          v-if="canReply && node.reply.visibility === 'public'"
          class="link"
          @click="emit('reply', node.reply.id)"
        >
          回复
        </el-button>
        <el-button
          v-if="canModerate && node.reply.visibility === 'public'"
          class="link danger"
          data-action="hide-reply"
          :disabled="busy"
          @click="askingToHide = true"
        >
          隐藏回复
        </el-button>
        <el-button
          v-if="canModerate && node.reply.visibility === 'hidden'"
          class="link"
          data-action="restore-reply"
          :disabled="busy"
          @click="emit('restore', node.reply.id)"
        >
          恢复回复
        </el-button>
      </div>

      <el-form
        v-if="askingToHide"
        class="hide-reply-form"
        data-role="reply-hide-form"
        @submit.prevent="submitHide(node.reply.id)"
      >
        <el-form-item label="隐藏原因" required>
          <el-input v-model="hideReason" clearable data-role="reply-hide-reason" maxlength="255" />
        </el-form-item>
        <div class="form-actions">
          <el-button class="btn" @click="askingToHide = false">取消</el-button>
          <el-button
            native-type="submit"
            class="btn btn-danger"
            :disabled="busy || !hideReason.trim()"
          >
            确认隐藏
          </el-button>
        </div>
      </el-form>
    </article>

    <ol v-if="expanded && node.children.length" class="reply-children">
      <ReviewReplyNode
        v-for="child in node.children"
        :key="child.reply.id"
        :node="child"
        :depth="depth + 1"
        :can-moderate="canModerate"
        :can-reply="canReply"
        :busy="busy"
        @reply="(id) => emit('reply', id)"
        @hide="(id, reason) => emit('hide', id, reason)"
        @restore="(id) => emit('restore', id)"
      />
    </ol>
  </li>
</template>

<style scoped>
.reply-node {
  border-left: 3px solid var(--color-border, #d0d4dc);
  padding-left: 10px;
}
.reply-node[data-kind='admin'] {
  border-left-color: #2563eb;
}
.reply-node[data-visibility='hidden'] {
  border-left-color: #98a2b3;
}
.reply-content {
  display: grid;
  gap: 7px;
  padding: 10px 12px;
  background: var(--color-bg-soft, #fafbfd);
  border-radius: 6px;
}
.reply-head {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  color: var(--color-text-muted, #5b6472);
  font-size: 0.85rem;
}
.reply-head strong {
  color: var(--color-text, #1f2937);
}
.reply-body,
.reason {
  margin: 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.reason {
  color: #b42318;
  font-size: 0.85rem;
}
.edited {
  color: #667085;
  font-size: 0.78rem;
}
.badge {
  padding: 2px 7px;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 999px;
  font-size: 0.75rem;
}
.badge[data-visibility='public'] {
  background: #e7f7ee;
  border-color: #2bb673;
}
.badge[data-visibility='hidden'] {
  background: #f0f1f3;
  border-color: #c5c8d0;
}
.collapse-button {
  width: 24px;
  height: 24px;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 4px;
  background: #fff;
  cursor: pointer;
  line-height: 1;
}
.reply-tools,
.form-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
}
.link {
  background: transparent;
  border: 0;
  padding: 0;
  color: var(--color-primary, #2563eb);
  cursor: pointer;
  font: inherit;
}
.link.danger {
  color: #b42318;
}
.link:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.hide-reply-form {
  display: grid;
  gap: 8px;
  padding-top: 4px;
}
.hide-reply-form label {
  display: grid;
  gap: 4px;
  font-size: 0.85rem;
}
.hide-reply-form input {
  width: 100%;
  padding: 6px 8px;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
  font: inherit;
}
.form-actions {
  justify-content: flex-end;
}
.btn {
  padding: 6px 12px;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
  background: #fff;
  font: inherit;
  cursor: pointer;
}
.btn-danger {
  background: #b42318;
  color: #fff;
  border-color: transparent;
}
.reply-children {
  list-style: none;
  padding: 0;
  margin: 8px 0 0 12px;
  display: grid;
  gap: 8px;
}
@media (max-width: 600px) {
  .reply-children {
    margin-left: 6px;
  }
}
</style>
