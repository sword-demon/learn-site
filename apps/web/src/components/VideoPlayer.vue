<script setup lang="ts">
defineOptions({ name: 'VideoPlayer' });

defineProps<{
  url: string;
  status: 'processing' | 'ready' | 'missing' | 'broken';
}>();

const emit = defineEmits<{
  (eventName: 'timeupdate', payload: Event): void;
  (eventName: 'ended', payload: Event): void;
}>();
</script>

<template>
  <section class="asset-block">
    <video
      controls
      preload="metadata"
      :src="url"
      poster="/assets/stitch-lesson-hero.jpg"
      class="player"
      @timeupdate="emit('timeupdate', $event)"
      @ended="emit('ended', $event)"
    />
    <p v-if="status !== 'ready'" class="notice">资源尚未处理完成 ({{ status }}), 可能无法播放.</p>
  </section>
</template>
