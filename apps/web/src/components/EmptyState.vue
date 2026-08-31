<script setup lang="ts">
// ponytail: native SVG over an icon lib import.
import { RouterLink } from 'vue-router';

defineProps<{
  headline?: string;
  sub?: string;
  ctaText?: string;
  ctaHref?: string;
}>();
</script>

<template>
  <div class="empty-state" data-testid="empty-state">
    <svg class="empty-icon" viewBox="0 0 64 64" width="64" height="64" aria-hidden="true">
      <circle cx="32" cy="32" r="28" fill="none" stroke="#dcdfe6" stroke-width="2" />
      <path d="M22 36 L42 36" stroke="#dcdfe6" stroke-width="2" stroke-linecap="round" />
    </svg>
    <h3 v-if="headline" class="empty-headline">{{ headline }}</h3>
    <p v-if="sub" class="empty-sub">{{ sub }}</p>
    <div v-if="ctaText && ctaHref" class="empty-action">
      <router-link :to="ctaHref" class="empty-cta">{{ ctaText }}</router-link>
    </div>
    <div v-else-if="$slots.action" class="empty-action">
      <slot name="action" />
    </div>
  </div>
</template>

<style scoped>
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px 16px;
  color: #909399;
  text-align: center;
}
.empty-icon {
  margin-bottom: 16px;
}
.empty-headline {
  margin: 0 0 8px;
  font-size: 16px;
  font-weight: 500;
  color: #606266;
}
.empty-sub {
  margin: 0 0 16px;
  font-size: 14px;
  line-height: 1.6;
}
.empty-action {
  margin-top: 8px;
}
.empty-cta {
  display: inline-block;
  padding: 8px 18px;
  background: #409eff;
  color: #fff;
  border-radius: 4px;
  text-decoration: none;
  font-size: 14px;
}
</style>
