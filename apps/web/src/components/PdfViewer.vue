<script setup lang="ts">
import { Document } from '@element-plus/icons-vue';

defineOptions({ name: 'PdfViewer' });

defineProps<{
  url: string;
  status: 'processing' | 'ready' | 'missing' | 'broken';
}>();

const emit = defineEmits<{ (event: 'open'): void }>();
</script>

<template>
  <section class="asset-block">
    <p>这是一份 PDF 课节, 请点击下方按钮在新窗口打开.</p>
    <el-button type="primary" :icon="Document" @click="emit('open')">查看 PDF</el-button>
    <p v-if="status !== 'ready'" class="notice">资源尚未处理完成 ({{ status }}), 可能无法打开.</p>
  </section>
</template>
