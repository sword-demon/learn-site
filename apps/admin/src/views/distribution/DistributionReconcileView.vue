<script setup lang="ts">
import { computed, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { fetchReconcileByOrder, voidCommission } from '@/api/distribution';
import type { AdminCommissionByOrderDTO } from '@learn-site/contracts';

defineOptions({ name: 'DistributionReconcileView' });

const orderId = ref<number | null>(null);
const result = ref<AdminCommissionByOrderDTO | null>(null);
const errorMsg = ref<string | null>(null);

const tooMany = computed(() => (result.value?.receivers.length ?? 0) > 3);

async function search(): Promise<void> {
  if (!orderId.value) return;
  errorMsg.value = null;
  try {
    result.value = await fetchReconcileByOrder(orderId.value);
    if ((result.value.receivers.length ?? 0) > 3) {
      errorMsg.value = '数据完整性硬约束';
    }
  } catch (error) {
    errorMsg.value = (error as Error).message;
  }
}

async function voidOne(id: number): Promise<void> {
  try {
    const { value } = await ElMessageBox.prompt('撤销原因 (至少 5 字)', '撤销佣金', {
      type: 'warning',
      inputValidator: (v) => (v && v.trim().length >= 5 ? true : '原因至少 5 个字符'),
    });
    await voidCommission(id, value);
    ElMessage.success('已撤销');
    await search();
  } catch {
    return;
  }
}
</script>

<template>
  <main class="page">
    <h1>分销对账</h1>
    <el-form inline @submit.prevent="search">
      <el-form-item label="订单号">
        <el-input-number v-model="orderId" :min="1" />
      </el-form-item>
      <el-button type="primary" native-type="submit">查询</el-button>
    </el-form>
    <p v-if="errorMsg" class="error">{{ errorMsg }}</p>
    <el-table v-if="result" :data="result.receivers">
      <el-table-column prop="referrer_masked_phone" label="接收人" />
      <el-table-column prop="level" label="级别" />
      <el-table-column prop="amount_cents" label="金额(分)" />
      <el-table-column prop="status" label="状态" />
      <el-table-column label="操作" width="120">
        <template #default="{ row }">
          <el-button
            text
            type="danger"
            :disabled="tooMany || row.status === 'voided'"
            @click="voidOne(row.id)"
          >
            撤销
          </el-button>
        </template>
      </el-table-column>
    </el-table>
  </main>
</template>

<style scoped>
.page {
  padding: 24px;
}
.error {
  color: var(--el-color-danger);
}
</style>
