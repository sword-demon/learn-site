<template>
  <!-- 分类树节点：小径标记样式 -->
  <li class="trail-node" :class="{ leaf: node.children.length === 0 }">
    <details :open="depth < 2 && node.children.length > 0">
      <summary class="trail-summary">
        <span class="marker" aria-hidden="true" />
        <router-link :to="`/categories/${node.id}`" class="trail-link">{{ node.name }}</router-link>
        <span v-if="node.children.length" class="branch-count latin">{{ node.children.length }}</span>
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
import { computed } from 'vue'
import type { CategoryNode } from '@learn-site/contracts'

defineOptions({ name: 'CategoryBranch' })

const props = withDefaults(
  defineProps<{
    node: CategoryNode
    depth?: number
  }>(),
  { depth: 1 },
)

const depth = computed(() => props.depth)
const childDepth = computed(() => props.depth + 1)
</script>

<style scoped>
.trail-node {
  list-style: none;
}

.trail-summary {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  margin: 2px 0;
  border-radius: 12px;
  cursor: pointer;
  list-style: none;
  transition: background 0.2s ease;
}

.trail-summary::-webkit-details-marker {
  display: none;
}

.trail-summary:hover {
  background: rgba(31, 157, 108, 0.08);
}

.marker {
  flex-shrink: 0;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: var(--leaf);
  box-shadow: 0 0 0 3px rgba(31, 157, 108, 0.18);
}

.leaf .marker {
  width: 8px;
  height: 8px;
  background: var(--cyan);
  box-shadow: 0 0 0 2px rgba(18, 196, 200, 0.2);
}

.trail-link {
  flex: 1;
  color: var(--ink);
  text-decoration: none;
  font-size: 0.92rem;
  font-weight: 500;
}

.trail-link:hover {
  color: var(--leaf);
}

.branch-count {
  font-size: 0.68rem;
  letter-spacing: 0.1em;
  color: var(--muted);
  background: rgba(255, 255, 255, 0.7);
  border: 1px solid var(--line);
  padding: 1px 6px;
  border-radius: 999px;
}

.trail-children {
  list-style: none;
  margin: 0;
  padding: 0 0 0 18px;
  border-left: 1px dashed rgba(31, 157, 108, 0.35);
}
</style>
