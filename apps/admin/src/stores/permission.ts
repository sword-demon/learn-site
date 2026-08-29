import { computed } from 'vue';
import { defineStore } from 'pinia';

import { hasPermission, permissionCodes } from '@/api/http';

export const usePermissionStore = defineStore('permission', () => {
  const codes = computed(() => permissionCodes());
  const isSuperAdmin = computed(() => codes.value.includes('*'));

  function can(code: string): boolean {
    return hasPermission(code);
  }

  return { codes, isSuperAdmin, can };
});
