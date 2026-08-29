<template>
  <div class="app-shell" :class="{ 'app-shell--auth': hideFooter }">
    <RouteLoadingBar />
    <div class="app-content">
      <router-view />
    </div>
    <SiteFooter v-if="!hideFooter" />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import RouteLoadingBar from '@/components/RouteLoadingBar.vue';
import SiteFooter from '@/components/SiteFooter.vue';

const route = useRoute();
const hideFooter = computed(() => route.meta.hideFooter === true);
</script>

<style scoped>
.app-shell {
  display: grid;
  grid-template-rows: minmax(0, 1fr) auto;
  min-height: 100dvh;
}

.app-shell--auth {
  grid-template-rows: minmax(0, 1fr);
}

.app-content {
  min-width: 0;
}
</style>
