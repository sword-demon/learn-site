<script setup lang="ts">
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
    <div class="empty-icon" aria-hidden="true">
      <span class="empty-icon__ring" />
      <span class="empty-icon__line" />
    </div>
    <h3 v-if="headline" class="empty-headline serif">{{ headline }}</h3>
    <p v-if="sub" class="empty-sub">{{ sub }}</p>
    <div v-if="ctaText && ctaHref" class="empty-action">
      <router-link :to="ctaHref" class="btn btn-primary btn-sm">{{ ctaText }}</router-link>
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
  padding: 64px 24px;
  color: var(--ink-3);
  text-align: center;
  border: 1px dashed var(--line-2);
  border-radius: 12px;
  background: var(--card-2);
}

.empty-icon {
  position: relative;
  width: 64px;
  height: 64px;
  margin-bottom: 20px;
}

.empty-icon__ring {
  position: absolute;
  inset: 0;
  border: 2px solid var(--line);
  border-radius: 50%;
}

.empty-icon__line {
  position: absolute;
  left: 18px;
  right: 18px;
  top: 50%;
  height: 2px;
  background: var(--line);
  transform: translateY(-50%);
}

.empty-headline {
  margin: 0 0 8px;
  font-size: 24px;
  font-weight: 600;
  color: var(--ink-2);
}

.empty-sub {
  margin: 0 0 16px;
  max-width: 28rem;
  font-size: 16px;
  line-height: 1.65;
  color: var(--ink-3);
}

.empty-action {
  margin-top: 8px;
}
</style>
