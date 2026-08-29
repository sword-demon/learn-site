<script setup lang="ts">
import { computed } from 'vue';

defineOptions({ name: 'LessonEditor' });

export interface LessonEditorValue {
  title: string;
  content_type: 'markdown' | 'pdf' | 'video';
  body_markdown: string;
  asset_id: number | null;
  duration_seconds: number;
  is_preview: boolean;
  status: 'enabled' | 'disabled';
}

const props = defineProps<{
  modelValue: LessonEditorValue;
  assetLabel?: string;
}>();

const emit = defineEmits<{
  (eventName: 'update:modelValue', value: LessonEditorValue): void;
  (eventName: 'upload', kind: 'pdf' | 'video'): void;
}>();

const draft = computed({
  get: () => props.modelValue,
  set: (value: LessonEditorValue) => emit('update:modelValue', value),
});

function patch(value: Partial<LessonEditorValue>): void {
  draft.value = { ...draft.value, ...value };
}
</script>

<template>
  <div class="lesson-editor">
    <el-form :model="draft" label-position="top">
      <el-form-item label="课节标题" required>
        <el-input
          :model-value="draft.title"
          clearable
          maxlength="128"
          @update:model-value="patch({ title: $event })"
        />
      </el-form-item>
      <el-form-item label="内容类型" required>
        <el-radio-group
          :model-value="draft.content_type"
          @update:model-value="patch({ content_type: $event })"
        >
          <el-radio-button value="markdown">Markdown</el-radio-button>
          <el-radio-button value="pdf">PDF</el-radio-button>
          <el-radio-button value="video">视频</el-radio-button>
        </el-radio-group>
      </el-form-item>
      <el-form-item v-if="draft.content_type === 'markdown'" label="正文" required>
        <el-input
          :model-value="draft.body_markdown"
          clearable
          type="textarea"
          :rows="8"
          @update:model-value="patch({ body_markdown: $event })"
        />
      </el-form-item>
      <el-form-item v-else label="资源">
        <el-button type="primary" @click="emit('upload', draft.content_type)">
          {{
            draft.asset_id ? '替换资源' : `上传${draft.content_type === 'pdf' ? ' PDF' : '视频'}`
          }}
        </el-button>
        <span v-if="draft.asset_id" class="asset-label">{{
          assetLabel || `资源 #${draft.asset_id}`
        }}</span>
      </el-form-item>
      <el-form-item v-if="draft.content_type === 'video'" label="视频时长（秒）">
        <el-input-number
          :model-value="draft.duration_seconds"
          :min="0"
          @update:model-value="patch({ duration_seconds: $event ?? 0 })"
        />
      </el-form-item>
      <el-form-item label="课节状态">
        <el-switch
          :model-value="draft.status === 'enabled'"
          @update:model-value="patch({ status: $event ? 'enabled' : 'disabled' })"
        />
      </el-form-item>
      <el-checkbox
        :model-value="draft.is_preview"
        @update:model-value="patch({ is_preview: $event })"
        >允许试看</el-checkbox
      >
    </el-form>
  </div>
</template>

<style scoped>
.lesson-editor {
  display: grid;
  gap: 14px;
}
.asset-label {
  margin-left: 10px;
  color: var(--el-text-color-secondary);
  font-size: 13px;
}
</style>
