<template>
  <li class="tree-node">
    <details :open="node.children.length > 0">
      <summary>
        <router-link :to="`/categories/${node.id}`">{{ node.name }}</router-link>
      </summary>
      <ul v-if="node.children.length > 0">
        <CategoryBranch v-for="child in node.children" :key="child.id" :node="child" />
      </ul>
    </details>
  </li>
</template>

<script setup lang="ts">
import type { CategoryNode } from '@learn-site/contracts'

defineOptions({ name: 'CategoryBranch' })
defineProps<{ node: CategoryNode }>()
</script>
