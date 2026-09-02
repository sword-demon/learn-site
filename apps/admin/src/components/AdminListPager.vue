<script setup lang="ts">
import { ref } from 'vue';

defineOptions({ name: 'AdminListPager' });

const page = defineModel<number>('page', { required: true });
const pageSize = defineModel<number>('pageSize', { required: true });

withDefaults(
  defineProps<{
    total: number;
    pageSizes?: number[];
    hideWhenEmpty?: boolean;
  }>(),
  {
    pageSizes: () => [10, 20, 50],
    hideWhenEmpty: true,
  },
);

const emit = defineEmits<{
  change: [];
}>();

const sizeChanging = ref(false);

function onCurrentChange(): void {
  if (sizeChanging.value) return;
  emit('change');
}

function onSizeChange(): void {
  sizeChanging.value = true;
  page.value = 1;
  emit('change');
  queueMicrotask(() => {
    sizeChanging.value = false;
  });
}
</script>

<template>
  <el-pagination
    v-if="!hideWhenEmpty || total > 0"
    v-model:current-page="page"
    v-model:page-size="pageSize"
    class="admin-list-pager"
    :total="total"
    :page-sizes="pageSizes"
    layout="total, sizes, prev, pager, next"
    @current-change="onCurrentChange"
    @size-change="onSizeChange"
  />
</template>

<style scoped>
.admin-list-pager {
  justify-content: flex-end;
}
</style>
