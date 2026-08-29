<template>
  <el-breadcrumb separator="/" class="admin-breadcrumb">
    <el-breadcrumb-item
      v-for="(item, index) in items"
      :key="`${item.title}-${index}`"
      :to="isClickable(item) ? item.path : undefined"
    >
      <span
        v-if="isClickable(item)"
        class="crumb-link"
        role="link"
        @click.prevent="onNavigate(item.path!)"
      >
        {{ item.title }}
      </span>
      <span v-else>{{ item.title }}</span>
    </el-breadcrumb-item>
  </el-breadcrumb>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { permissionCodes } from '@/api/http';
import { visibleEntries } from '@/layouts/AdminMenu';
import { useAdminBreadcrumb, type BreadcrumbItem } from '@/composables/useAdminBreadcrumb';

const route = useRoute();
const router = useRouter();

const menuEntries = computed(() => visibleEntries(permissionCodes()));
const items = useAdminBreadcrumb(route, menuEntries);

function isClickable(item: BreadcrumbItem): boolean {
  return typeof item.path === 'string' && item.path.length > 0 && item.path !== route.path;
}

function onNavigate(path: string): void {
  void router.push(path);
}
</script>

<style scoped>
.admin-breadcrumb {
  padding: 8px 16px 12px;
  background: #ffffff;
  border-bottom: 1px solid #e2e8f0;
}

.crumb-link {
  color: #38bdf8;
  cursor: pointer;
}

.crumb-link:hover {
  text-decoration: underline;
}
</style>
