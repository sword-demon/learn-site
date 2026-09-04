<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { PaymentWhitelistCreateInput } from '@learn-site/contracts';
import type { PaymentConfig as PaymentConfigDTO } from '@learn-site/contracts';
import { fetchPaymentConfig, updatePaymentConfig } from '@/api/payment';
import {
  addPaymentWhitelist,
  listPaymentWhitelist,
  removePaymentWhitelist,
  togglePaymentWhitelist,
} from '@/api/paymentWhitelist';
import type { PaymentWhitelistEntryDTO } from '@/api/paymentWhitelist';

defineOptions({ name: 'PaymentWhitelistView' });

const config = ref<PaymentConfigDTO | null>(null);
const rows = ref<PaymentWhitelistEntryDTO[]>([]);
const total = ref(0);
const page = ref(1);
const loading = ref(false);
const dialogVisible = ref(false);
const saving = ref(false);
const errorMessage = ref('');
const form = reactive({ phone: '', enabled: true, note: '' });

async function load(): Promise<void> {
  loading.value = true;
  errorMessage.value = '';
  try {
    const [list, paymentConfig] = await Promise.all([
      listPaymentWhitelist(page.value, 20),
      fetchPaymentConfig(),
    ]);
    rows.value = list.items;
    total.value = list.total;
    config.value = paymentConfig;
  } catch (error) {
    errorMessage.value = error instanceof Error ? error.message : 'load_failed';
  } finally {
    loading.value = false;
  }
}

function openAdd(): void {
  form.phone = '';
  form.enabled = true;
  form.note = '';
  dialogVisible.value = true;
}

async function save(): Promise<void> {
  const parsed = PaymentWhitelistCreateInput.safeParse(form);
  if (!parsed.success) {
    ElMessage.error(parsed.error.issues[0]?.message ?? 'INVALID_PHONE');
    return;
  }
  saving.value = true;
  try {
    await addPaymentWhitelist(parsed.data);
    dialogVisible.value = false;
    ElMessage.success('白名单已添加');
    await load();
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : 'save_failed');
  } finally {
    saving.value = false;
  }
}

async function toggle(row: PaymentWhitelistEntryDTO, enabled: boolean): Promise<void> {
  try {
    await togglePaymentWhitelist(row.id, enabled);
    row.enabled = enabled;
    ElMessage.success(enabled ? '已启用' : '已停用');
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : 'update_failed');
    await load();
  }
}

async function remove(row: PaymentWhitelistEntryDTO): Promise<void> {
  try {
    await ElMessageBox.confirm(`确定移除 ${row.phone_masked} 吗？`, '移除白名单', {
      type: 'warning',
    });
    await removePaymentWhitelist(row.id);
    ElMessage.success('白名单已移除');
    await load();
  } catch (error) {
    if (error !== 'cancel' && error !== 'close') {
      ElMessage.error(error instanceof Error ? error.message : 'remove_failed');
    }
  }
}

async function toggleWhitelistOnly(enabled: boolean): Promise<void> {
  if (!config.value) return;
  const previous = config.value.whitelist_only;
  config.value.whitelist_only = enabled;
  try {
    config.value = await updatePaymentConfig({
      enabled: config.value.enabled,
      api_url: config.value.api_url,
      pid: config.value.pid,
      merchant_key: '',
      notify_url: config.value.notify_url,
      return_url: config.value.return_url,
      enabled_channels: config.value.enabled_channels,
      whitelist_only: enabled,
      version: config.value.version,
    });
  } catch (error) {
    config.value.whitelist_only = previous;
    ElMessage.error(error instanceof Error ? error.message : 'update_failed');
  }
}

onMounted(() => void load());
</script>

<template>
  <main class="page">
    <header class="head">
      <div>
        <h1 class="display">支付白名单</h1>
        <p class="muted">仅允许指定手机号创建支付订单。</p>
      </div>
      <el-button type="primary" data-action="add" @click="openAdd">新增</el-button>
    </header>

    <el-alert v-if="errorMessage" :title="errorMessage" type="error" :closable="false" />
    <el-table v-loading="loading" :data="rows" row-key="id" border>
      <el-table-column prop="phone_masked" label="手机号" />
      <el-table-column label="状态" width="110">
        <template #default="{ row }">
          <el-switch
            :model-value="row.enabled"
            :data-action="row.enabled ? 'enabled' : 'disabled'"
            @update:model-value="toggle(row, $event)"
          />
        </template>
      </el-table-column>
      <el-table-column prop="note" label="备注" min-width="180" />
      <el-table-column prop="created_at" label="创建时间" min-width="180" />
      <el-table-column label="操作" width="100">
        <template #default="{ row }">
          <el-button link type="danger" data-action="remove" @click="remove(row)">移除</el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination
      v-model:current-page="page"
      layout="total, prev, pager, next"
      :total="total"
      :page-size="20"
      @current-change="load"
    />

    <section class="setting-row">
      <span>仅白名单可支付</span>
      <el-switch
        v-if="config"
        :model-value="config.whitelist_only"
        data-action="whitelist-only"
        @update:model-value="toggleWhitelistOnly"
      />
    </section>

    <el-dialog v-model="dialogVisible" title="新增支付白名单" width="420px">
      <el-form :model="form" label-position="top" @submit.prevent="save">
        <el-form-item label="手机号" required>
          <el-input v-model="form.phone" name="phone" maxlength="11" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="form.note" name="note" maxlength="120" />
        </el-form-item>
        <el-form-item label="启用">
          <el-switch v-model="form.enabled" />
        </el-form-item>
        <div class="actions">
          <el-button @click="dialogVisible = false">取消</el-button>
          <el-button type="primary" :loading="saving" native-type="submit">保存</el-button>
        </div>
      </el-form>
    </el-dialog>
  </main>
</template>

<style scoped>
.page {
  display: grid;
  gap: 16px;
}
.head,
.setting-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}
.display {
  margin: 0 0 4px;
  font-size: 1.4rem;
}
.muted {
  margin: 0;
  color: var(--color-text-muted, #5b6472);
  font-size: 0.85rem;
}
.setting-row {
  padding: 14px 0;
  border-top: 1px solid var(--color-border, #e3e6ee);
}
.actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
</style>
