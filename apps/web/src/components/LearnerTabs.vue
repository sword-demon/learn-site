<script setup lang="ts" generic="K extends string">
interface Tab {
  key: K
  label: string
}

defineProps<{
  tabs: Tab[]
  modelValue: K
}>()
const emit = defineEmits<{
  'update:modelValue': [key: K]
}>()

function select(key: K): void {
  emit('update:modelValue', key)
}
</script>

<template>
  <div class="learner-tabs">
    <div class="tab-bar" role="tablist">
      <button
        v-for="t in tabs"
        :key="t.key"
        type="button"
        role="tab"
        :aria-selected="modelValue === t.key"
        :data-tab="t.key"
        :class="['tab-trigger', { active: modelValue === t.key }]"
        @click="select(t.key)"
      >
        {{ t.label }}
      </button>
    </div>
    <div class="tab-panel">
      <slot :name="modelValue" />
    </div>
  </div>
</template>

<style scoped>
.learner-tabs {
  display: flex;
  flex-direction: column;
}
.tab-bar {
  display: flex;
  gap: 4px;
  border-bottom: 1px solid #ebeef5;
  margin-bottom: 16px;
}
.tab-trigger {
  background: transparent;
  border: none;
  padding: 10px 16px;
  cursor: pointer;
  font-size: 14px;
  color: #606266;
  border-bottom: 2px solid transparent;
  transition: color 0.2s, border-color 0.2s;
}
.tab-trigger.active {
  color: #409eff;
  border-bottom-color: #409eff;
}
.tab-panel {
  min-height: 80px;
}
</style>