<script setup lang="ts">
import { ref } from 'vue';
import { Check, Key } from '@element-plus/icons-vue';
import type { RedeemActivationCodeResult } from '@/api/activationCodes';
import { activationCodeErrorMessage, redeemActivationCode } from '@/api/activationCodes';

const emit = defineEmits<{
  success: [result: RedeemActivationCodeResult];
}>();

const code = ref('');
const submitting = ref(false);
const errorMessage = ref('');
const result = ref<RedeemActivationCodeResult | null>(null);

async function submit(): Promise<void> {
  if (submitting.value) return;
  const normalized = code.value.trim();
  if (!normalized) {
    errorMessage.value = '请输入激活码。';
    return;
  }

  submitting.value = true;
  errorMessage.value = '';
  result.value = null;
  try {
    const redeemed = await redeemActivationCode(normalized);
    result.value = redeemed;
    code.value = '';
    emit('success', redeemed);
  } catch (error) {
    errorMessage.value = activationCodeErrorMessage(error);
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <form class="redeem-form" @submit.prevent="submit">
    <div class="redeem-row">
      <el-input
        v-model="code"
        :disabled="submitting"
        :prefix-icon="Key"
        autocomplete="off"
        maxlength="32"
        placeholder="XXXX-XXXX-XXXX-XXXX"
        aria-label="激活码"
      />
      <el-button type="primary" native-type="submit" :loading="submitting" :disabled="submitting">
        兑换
      </el-button>
    </div>

    <p v-if="errorMessage" class="redeem-error" role="alert">{{ errorMessage }}</p>

    <div v-if="result" class="redeem-success" role="status">
      <Check :size="18" aria-hidden="true" />
      <span>已获得「{{ result.course_title }}」的课程访问权</span>
      <router-link :to="`/courses/${result.course_id}`">去学习</router-link>
    </div>
  </form>
</template>

<style scoped>
.redeem-form {
  display: grid;
  gap: 10px;
  width: 100%;
}
.redeem-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 10px;
  max-width: 560px;
}
.redeem-error {
  margin: 0;
  color: var(--el-color-danger);
  font-size: 14px;
}
.redeem-success {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  color: var(--el-color-success);
  font-size: 14px;
}
.redeem-success a {
  color: var(--el-color-primary);
  text-decoration: none;
}
@media (max-width: 520px) {
  .redeem-row {
    grid-template-columns: 1fr;
  }
  .redeem-row :deep(.el-button) {
    width: 100%;
  }
}
</style>
