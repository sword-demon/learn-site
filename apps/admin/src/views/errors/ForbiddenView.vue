<template>
  <el-result icon="warning" title="无权访问" sub-title="当前账号没有访问此模块的权限">
    <template #extra>
      <el-button type="primary" @click="goHome"> 返回可用模块 </el-button>
    </template>
  </el-result>
</template>

<script setup lang="ts">
import { useRouter } from 'vue-router';
import { permissionCodes } from '@/api/http';
import { firstVisiblePath } from '@/layouts/AdminMenu';

const router = useRouter();

async function goHome(): Promise<void> {
  await router.push(firstVisiblePath(permissionCodes()) ?? '/login');
}
</script>
