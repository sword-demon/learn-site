<template>
  <div class="app-shell">
    <RouteLoadingBar />
    <div class="app-content">
      <router-view />
    </div>
    <SiteFooter />
  </div>
</template>

<script setup lang="ts">
import RouteLoadingBar from '@/components/RouteLoadingBar.vue';
import SiteFooter from '@/components/SiteFooter.vue';
</script>

<style scoped>
.app-shell {
  display: grid;
  grid-template-rows: minmax(0, 1fr) auto;
  min-height: 100dvh;
}

.app-content {
  min-width: 0;
}
</style>
