<script setup lang="ts">
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
    <button type="button" class="btn btn-primary" @click="emit('open')">查看 PDF</button>
    <p v-if="status !== 'ready'" class="notice">资源尚未处理完成 ({{ status }}), 可能无法打开.</p>
  </section>
</template>
