<template>
  <li :class="{ closed: !expanded && node.children.length > 0 }">
    <button
      type="button"
      class="tree-row"
      :class="{ on: selectedId === node.id }"
      @click="onRowClick"
    >
      <span
        v-if="node.children.length"
        class="caret"
        aria-hidden="true"
        @click.stop="expanded = !expanded"
        >▾</span
      >
      <span v-else class="caret" style="visibility: hidden" aria-hidden="true">·</span>
      <span>{{ node.name }}</span>
      <span v-if="courseCount != null" class="cnt">{{ courseCount }}</span>
    </button>
    <ul v-if="node.children.length" class="tree-branch">
      <CategoryBranch
        v-for="child in node.children"
        :key="child.id"
        :node="child"
        :selected-id="selectedId"
        :count-under="countUnder"
        @select="$emit('select', $event)"
      />
    </ul>
  </li>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import type { CategoryNode } from '@learn-site/contracts';

defineOptions({ name: 'CategoryBranch' });

const props = defineProps<{
  node: CategoryNode;
  selectedId: number | null;
  countUnder: (id: number) => number;
}>();

const emit = defineEmits<{
  select: [id: number];
}>();

const expanded = ref(true);

const courseCount = computed(() => props.countUnder(props.node.id));

function onRowClick(): void {
  emit('select', props.node.id);
}
</script>
