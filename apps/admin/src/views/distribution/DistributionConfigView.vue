<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { ElMessage } from 'element-plus';
import type { DistributionConfigDTO, DistributionConfigUpdateInput } from '@learn-site/contracts';
import { fetchDistributionConfig, saveDistributionConfig } from '@/api/distribution';

defineOptions({ name: 'DistributionConfigView' });

const loading = ref(false);
const submitting = ref(false);
const errorMsg = ref<string | null>(null);
const form = ref<DistributionConfigUpdateInput | null>(null);

function toInput(cfg: DistributionConfigDTO): DistributionConfigUpdateInput {
  const { updated_at: _a, updated_by: _b, ...rest } = cfg;
  return rest;
}

async function reload(): Promise<void> {
  loading.value = true;
  errorMsg.value = null;
  try {
    form.value = toInput(await fetchDistributionConfig());
  } catch (error) {
    errorMsg.value = (error as Error).message || 'load_failed';
  } finally {
    loading.value = false;
  }
}

async function save(): Promise<void> {
  if (!form.value) return;
  if (form.value.level_cap > 3 || form.value.level_cap < 1) {
    errorMsg.value = '合规硬约束';
    return;
  }
  submitting.value = true;
  errorMsg.value = null;
  try {
    form.value = toInput(await saveDistributionConfig(form.value));
    ElMessage.success('已保存');
  } catch (error) {
    errorMsg.value = (error as Error).message || '合规硬约束';
  } finally {
    submitting.value = false;
  }
}

onMounted(() => void reload());
</script>

<template>
  <main class="page">
    <header class="head">
      <h1>分销配置</h1>
    </header>
    <p v-if="errorMsg" class="error">{{ errorMsg }}</p>
    <el-form v-else-if="form" :model="form" label-position="top" @submit.prevent="save">
      <el-form-item label="开启分销">
        <el-switch v-model="form.enabled" />
      </el-form-item>
      <el-form-item label="级别上限">
        <el-input-number v-model="form.level_cap" :min="1" :max="3" />
        <el-alert title="合规硬约束" type="error" :closable="false" show-icon />
      </el-form-item>
      <el-form-item label="一级比例">
        <el-input-number v-model="form.level1_pct" :min="0" :max="1" :step="0.01" />
      </el-form-item>
      <el-form-item label="二级比例">
        <el-input-number v-model="form.level2_pct" :min="0" :max="1" :step="0.01" />
      </el-form-item>
      <el-form-item label="三级比例">
        <el-input-number v-model="form.level3_pct" :min="0" :max="1" :step="0.01" />
      </el-form-item>
      <el-form-item label="单笔封顶 (分)">
        <el-input-number v-model="form.per_order_cap_cents" :min="0" />
      </el-form-item>
      <el-form-item label="允许学员查看明细">
        <el-switch v-model="form.learner_can_view_detail" />
      </el-form-item>
      <el-button type="primary" :loading="submitting" native-type="submit">保存</el-button>
    </el-form>
  </main>
</template>

<style scoped>
.page { max-width: 640px; padding: 24px; }
.head { margin-bottom: 16px; }
.error { color: var(--el-color-danger); }
</style>
