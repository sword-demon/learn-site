<template>
  <li class="trail-node" :class="{ leaf: node.children.length === 0 }">
    <details :open="depth < 2 && node.children.length > 0">
      <summary class="trail-summary">
        <span class="marker" aria-hidden="true" />
        <router-link :to="`/categories/${node.id}`" class="trail-link">{{ node.name }}</router-link>
        <span v-if="node.children.length" class="branch-count"
          >{{ node.children.length }} 个子类</span
        >
      </summary>
      <ul v-if="node.children.length > 0" class="trail-children">
        <CategoryBranch
          v-for="child in node.children"
          :key="child.id"
          :node="child"
          :depth="childDepth"
        />
      </ul>
    </details>
  </li>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type { CategoryNode } from '@learn-site/contracts';

defineOptions({ name: 'CategoryBranch' });

const props = withDefaults(
  defineProps<{
    node: CategoryNode;
    depth?: number;
  }>(),
  { depth: 1 },
);

const depth = computed(() => props.depth);
const childDepth = computed(() => props.depth + 1);
</script>

<style scoped>
.trail-node {
  list-style: none;
}

.trail-summary {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 45px;
  margin: 2px 0;
  padding: 8px 10px;
  border-bottom: 1px solid rgba(212, 221, 211, 0.7);
  cursor: pointer;
  list-style: none;
  transition:
    background-color 0.2s ease,
    padding-left 0.2s ease;
}

.trail-summary::-webkit-details-marker {
  display: none;
}

.trail-summary:hover {
  padding-left: 14px;
  background: var(--surface-muted);
}

.marker {
  flex-shrink: 0;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--accent);
}

.leaf .marker {
  width: 5px;
  height: 5px;
  margin-left: 1px;
  background: var(--pine);
}

.trail-link {
  flex: 1;
  color: var(--ink);
  text-decoration: none;
  font-size: 0.92rem;
  font-weight: 500;
}

.trail-link:hover {
  color: var(--accent);
}

.branch-count {
  flex-shrink: 0;
  font-size: 0.68rem;
  color: var(--muted);
}

.trail-children {
  list-style: none;
  margin: 0;
  padding: 0 0 0 18px;
  border-left: 1px solid var(--line);
}
</style>
