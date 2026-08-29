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
import { ElMessage } from 'element-plus';
import type { IDomEditor, IToolbarConfig } from '@wangeditor/editor';
// @ts-expect-error @wangeditor/editor-for-vue 缺少完整类型声明
import { Toolbar, Editor } from '@wangeditor/editor-for-vue';
import '@wangeditor/editor/dist/css/style.css';
import { uploadAsset, uploadCourseCover } from '@/api/catalog';

const props = withDefaults(
  defineProps<{
    modelValue?: string;
    placeholder?: string;
    height?: string | number;
    disabled?: boolean;
  }>(),
  {
    modelValue: '',
    placeholder: '请输入课程内容...',
    height: 400,
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
  excludeKeys: ['fullScreen'],
};

const editorConfig = computed(() => ({
  placeholder: props.placeholder,
  readOnly: props.disabled,
  MENU_CONF: {
    uploadImage: {
      customUpload(file: File, insertFn: (url: string, alt?: string, href?: string) => void) {
        void handleImageUpload(file, insertFn);
      },
    },
    uploadVideo: {
      customUpload(file: File, insertFn: (url: string, poster?: string) => void) {
        void handleVideoUpload(file, insertFn);
      },
    },
  },
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

async function handleImageUpload(
  file: File,
  insertFn: (url: string, alt?: string, href?: string) => void,
): Promise<void> {
  if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
    ElMessage.warning('请上传 JPEG、PNG 或 WebP 图片');
    return;
  }
  if (file.size > 5 * 1024 * 1024) {
    ElMessage.warning('图片大小不能超过 5MB');
    return;
  }

  try {
    const result = await uploadCourseCover({ file });
    insertFn(result.url);
  } catch {
    ElMessage.error('图片上传失败，请重试');
  }
}

async function handleVideoUpload(
  file: File,
  insertFn: (url: string, poster?: string) => void,
): Promise<void> {
  if (!['video/mp4', 'video/quicktime'].includes(file.type)) {
    ElMessage.warning('请上传 MP4 或 MOV 视频');
    return;
  }
  if (file.size > 200 * 1024 * 1024) {
    ElMessage.warning('视频大小不能超过 200MB');
    return;
  }

  try {
    const result = await uploadAsset({ file, kind: 'video' });
    insertFn(`/api/media/assets/${result.id}`);
  } catch {
    ElMessage.error('视频上传失败，请重试');
  }
}

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
  min-height: 300px;
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
  min-height: 300px !important;
  padding: 12px !important;
  font-size: 14px !important;
  line-height: 1.6 !important;
  color: var(--el-text-color-regular, #303133) !important;
}

.w-e-text-dropdown {
  z-index: 2000 !important;
}
</style>
