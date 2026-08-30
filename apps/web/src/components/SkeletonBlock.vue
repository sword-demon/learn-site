<script setup lang="ts">
// ponytail: native CSS animation over a UI-lib skeleton primitive.
// Upgrade if rows need rounded avatars / media blocks — Element Plus el-skeleton then.
withDefaults(defineProps<{ rows?: number }>(), { rows: 3 })
</script>

<template>
  <div class="skeleton-block" role="status" aria-busy="true">
    <div
      v-for="i in rows"
      :key="i"
      class="skeleton-row"
      data-testid="skeleton-row"
    />
  </div>
</template>

<style scoped>
.skeleton-block {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px 0;
}
.skeleton-row {
  height: 16px;
  border-radius: 4px;
  background: linear-gradient(90deg, #f0f0f0 25%, #e6e6e6 50%, #f0f0f0 75%);
  background-size: 200% 100%;
  animation: shimmer 1.4s ease-in-out infinite;
}
@keyframes shimmer {
  0% {
    background-position: 200% 0;
  }
  100% {
    background-position: -200% 0;
  }
}
</style>