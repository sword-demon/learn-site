<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { ElMessage } from 'element-plus';
import { PaymentConfigUpdateInput } from '@learn-site/contracts';
import type {
  PaymentConfig as PaymentConfigDTO,
  PaymentConfigUpdateInput as PaymentConfigInput,
} from '@learn-site/contracts';
import { fetchPaymentConfig, updatePaymentConfig } from '@/api/payment';

defineOptions({ name: 'PaymentConfigView' });

const config = ref<PaymentConfigDTO | null>(null);
const loading = ref(false);
const submitting = ref(false);
const errorMessage = ref('');

const draft = reactive<PaymentConfigInput>({
  enabled: false,
  api_url: 'https://z-pay.cn/',
  pid: '',
  merchant_key: '',
  notify_url: '',
  return_url: '',
  enabled_channels: ['wxpay'],
  whitelist_only: false,
});

async function reload(): Promise<void> {
  loading.value = true;
  errorMessage.value = '';
  try {
    config.value = await fetchPaymentConfig();
    if (config.value) {
      draft.enabled = config.value.enabled;
      draft.api_url = config.value.api_url;
      draft.pid = config.value.pid;
      draft.notify_url = config.value.notify_url;
      draft.return_url = config.value.return_url;
      draft.enabled_channels = [...config.value.enabled_channels];
      draft.whitelist_only = config.value.whitelist_only;
      draft.version = config.value.version;
      draft.merchant_key = '';
    }
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'load_failed';
  } finally {
    loading.value = false;
  }
}

async function save(): Promise<void> {
  const parsed = PaymentConfigUpdateInput.safeParse(draft);
  if (!parsed.success) {
    ElMessage.error(parsed.error.issues[0]?.message ?? '支付配置无效');
    return;
  }
  submitting.value = true;
  errorMessage.value = '';
  try {
    config.value = await updatePaymentConfig(parsed.data);
    draft.merchant_key = '';
    ElMessage.success('支付配置已保存');
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'save_failed';
  } finally {
    submitting.value = false;
  }
}

onMounted(() => void reload());
</script>

<template>
  <main class="page">
    <header class="head">
      <h1 class="display">支付配置</h1>
      <p class="muted">配置保存后，商户密钥只以掩码形式展示。</p>
    </header>

    <el-alert v-if="errorMessage" :title="errorMessage" type="error" :closable="false" />
    <p v-else-if="loading" class="notice">加载中…</p>

    <el-form v-else :model="draft" class="form" label-position="top" @submit.prevent="save">
      <el-form-item label="启用支付">
        <el-switch v-model="draft.enabled" />
      </el-form-item>
      <el-form-item label="API 地址" required>
        <el-input v-model="draft.api_url" name="api_url" placeholder="https://z-pay.cn/" />
      </el-form-item>
      <el-form-item label="商户 ID" required>
        <el-input v-model="draft.pid" name="pid" />
      </el-form-item>
      <el-form-item label="商户密钥" required>
        <el-input v-model="draft.merchant_key" name="merchant_key" type="password" show-password />
        <span v-if="config?.merchant_key_masked" class="muted"
          >当前密钥：{{ config.merchant_key_masked }}</span
        >
      </el-form-item>
      <el-form-item label="异步通知地址" required>
        <el-input v-model="draft.notify_url" name="notify_url" />
      </el-form-item>
      <el-form-item label="同步跳转地址" required>
        <el-input v-model="draft.return_url" name="return_url" />
      </el-form-item>
      <el-form-item label="支付通道" required>
        <el-checkbox-group v-model="draft.enabled_channels">
          <el-checkbox value="wxpay">微信支付</el-checkbox>
          <el-checkbox value="alipay">支付宝</el-checkbox>
        </el-checkbox-group>
      </el-form-item>
      <el-form-item label="仅白名单可支付">
        <el-switch v-model="draft.whitelist_only" />
      </el-form-item>
      <div class="actions">
        <el-button type="primary" native-type="submit" :loading="submitting">保存配置</el-button>
      </div>
    </el-form>
  </main>
</template>

<style scoped>
.page {
  display: grid;
  gap: 16px;
  max-width: 860px;
}
.head {
  display: grid;
  gap: 4px;
}
.display {
  margin: 0;
  font-size: 1.4rem;
}
.muted,
.notice {
  margin: 0;
  color: var(--color-text-muted, #5b6472);
  font-size: 0.85rem;
}
.form {
  display: grid;
  gap: 14px;
  padding: 16px;
  border: 1px solid var(--color-border, #e3e6ee);
  border-radius: 8px;
  background: #fff;
}
.actions {
  display: flex;
  justify-content: flex-end;
}
</style>
