<template>
  <div class="wangeditor-wrapper">
    <Toolbar
      class="toolbar-container"
      :editor="editorRef"
      :default-config="toolbarConfig"
      mode="default"
    />
    <Editor
      v-model="html"
      class="editor-wrapper"
      :default-config="editorConfig"
      mode="default"
      :style="editorStyle"
      @on-created="handleCreated"
      @on-focus="emit('focus')"
      @on-blur="emit('blur')"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import type { IDomEditor, IToolbarConfig } from '@wangeditor/editor';
// @ts-expect-error @wangeditor/editor-for-vue 缺少完整类型声明
import { Toolbar, Editor } from '@wangeditor/editor-for-vue';
import '@wangeditor/editor/dist/css/style.css';

const props = withDefaults(
  defineProps<{
    modelValue?: string;
    placeholder?: string;
    height?: string | number;
    disabled?: boolean;
  }>(),
  {
    modelValue: '',
    placeholder: '写下你今天的学习计划…',
    height: 220,
    disabled: false,
  },
);

const emit = defineEmits<{
  'update:modelValue': [value: string];
  change: [html: string];
  focus: [];
  blur: [];
}>();

const editorRef = shallowRef<IDomEditor>();
const html = ref(props.modelValue ?? '');

const editorStyle = computed(() => {
  const height = props.height;
  return {
    height: typeof height === 'number' ? `${height}px` : height,
    overflowY: 'hidden' as const,
  };
});

const toolbarConfig: Partial<IToolbarConfig> = {
  excludeKeys: [
    'fullScreen',
    'group-image',
    'group-video',
    'insertImage',
    'uploadImage',
    'insertVideo',
    'uploadVideo',
  ],
};

const editorConfig = computed(() => ({
  placeholder: props.placeholder,
  readOnly: props.disabled,
}));

function handleCreated(editor: IDomEditor) {
  editorRef.value = editor;
}

watch(
  () => props.modelValue,
  (value) => {
    const next = value ?? '';
    if (next !== html.value) {
      html.value = next;
    }
  },
);

watch(html, (value) => {
  emit('update:modelValue', value);
  emit('change', value);
});

watch(
  () => props.disabled,
  (disabled) => {
    const editor = editorRef.value;
    if (!editor) return;
    if (disabled) editor.disable();
    else editor.enable();
  },
);

onBeforeUnmount(() => {
  editorRef.value?.destroy();
});
</script>

<style scoped>
.wangeditor-wrapper {
  border: 1px solid var(--el-border-color, #dcdfe6);
  border-radius: 4px;
  background: #fff;
  font-family: var(--el-font-family);
}

.toolbar-container {
  border-bottom: 1px solid var(--el-border-color, #dcdfe6);
}

.editor-wrapper {
  min-height: 180px;
}
</style>

<style>
.w-e-toolbar {
  border-bottom: 1px solid var(--el-border-color-light, #e4e7ed) !important;
  background: #fff !important;
}

.w-e-text-container {
  border: none !important;
  background: #fff !important;
}

.w-e-text {
  min-height: 180px !important;
  padding: 12px !important;
  font-size: 14px !important;
  line-height: 1.6 !important;
  color: var(--el-text-color-regular, #303133) !important;
}
</style>
