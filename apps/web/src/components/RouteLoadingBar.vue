<template>
  <div
    class="route-loading"
    :class="{ active: loading }"
    role="progressbar"
    aria-label="页面加载中"
    :aria-hidden="!loading"
    :aria-busy="loading"
  >
    <div class="route-loading-bar" />
  </div>
</template>

<script setup lang="ts">
import { useRouteLoading } from '@/router/loading';

const { loading } = useRouteLoading();
</script>

<style scoped>
.route-loading {
  position: fixed;
  inset: 0 auto auto 0;
  z-index: 9999;
  width: 100%;
  height: 3px;
  pointer-events: none;
  opacity: 0;
  transition: opacity 0.2s ease;
}

.route-loading.active {
  opacity: 1;
}

.route-loading-bar {
  width: 100%;
  height: 100%;
  background: linear-gradient(
    90deg,
    transparent 0%,
    var(--pine) 35%,
    var(--accent) 65%,
    transparent 100%
  );
  transform: translateX(-100%);
}

.route-loading.active .route-loading-bar {
  animation: route-loading-slide 0.9s ease-in-out infinite;
}

@keyframes route-loading-slide {
  0% {
    transform: translateX(-100%);
  }

  100% {
    transform: translateX(100%);
  }
}
</style>
