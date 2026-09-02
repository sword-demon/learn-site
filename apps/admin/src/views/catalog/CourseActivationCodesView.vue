<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage, ElMessageBox } from 'element-plus';
import { CopyDocument, Delete, Plus } from '@element-plus/icons-vue';
import type { AdminActivationCodeItemDTO } from '@learn-site/contracts';
import {
  createActivationCodeBatch,
  listActivationCodes,
  voidActivationCode,
  type ActivationCodeStatus,
} from '@/api/activationCodes';
import AdminListPager from '@/components/AdminListPager.vue';

const route = useRoute();
const router = useRouter();
const courseId = computed(() => Number(route.params.id));
const items = ref<AdminActivationCodeItemDTO[]>([]);
const total = ref(0);
const loading = ref(false);
const errorMessage = ref('');
const generating = ref(false);
const voidingId = ref<number | null>(null);
const createOpen = ref(false);
const codesOpen = ref(false);
const generatedCodes = ref<string[]>([]);

const filters = reactive({
  status: '' as ActivationCodeStatus | '',
  page: 1,
  limit: 20,
});
const createForm = reactive({
  quantity: 10,
  expires_at: null as string | null,
});

function validCourseId(): number {
  if (!Number.isInteger(courseId.value) || courseId.value <= 0) {
    throw new Error('课程参数无效');
  }
  return courseId.value;
}

async function reload(): Promise<void> {
  loading.value = true;
  errorMessage.value = '';
  try {
    const params: { page: number; limit: number; status?: ActivationCodeStatus } = {
      page: filters.page,
      limit: filters.limit,
    };
    if (filters.status) params.status = filters.status;
    const result = await listActivationCodes(validCourseId(), params);
    items.value = result.items;
    total.value = result.total;
  } catch (error) {
    errorMessage.value = readError(error, '激活码列表加载失败');
  } finally {
    loading.value = false;
  }
}

async function generate(): Promise<void> {
  if (generating.value) return;
  if (
    !Number.isInteger(createForm.quantity) ||
    createForm.quantity < 1 ||
    createForm.quantity > 1000
  ) {
    ElMessage.warning('生成数量须为 1 至 1000 的整数');
    return;
  }
  generating.value = true;
  try {
    const batch = await createActivationCodeBatch(validCourseId(), {
      quantity: createForm.quantity,
      expires_at: createForm.expires_at,
    });
    generatedCodes.value = [...batch.codes];
    createOpen.value = false;
    codesOpen.value = true;
    ElMessage.success(`已生成 ${batch.quantity} 枚激活码`);
    await reload();
  } catch (error) {
    ElMessage.error(readError(error, '生成失败'));
  } finally {
    generating.value = false;
  }
}

async function copyCodes(): Promise<void> {
  if (generatedCodes.value.length === 0) return;
  try {
    await navigator.clipboard.writeText(generatedCodes.value.join('\n'));
    ElMessage.success('已复制全部激活码');
  } catch {
    ElMessage.error('复制失败，请手动选择文本');
  }
}

function closeGeneratedCodes(): void {
  codesOpen.value = false;
  // 明文只在生成成功当下展示;关闭后即从前端状态清除。
  generatedCodes.value = [];
}

async function voidCode(row: AdminActivationCodeItemDTO): Promise<void> {
  try {
    await ElMessageBox.confirm(`确定作废激活码 ${row.display_code} 吗？`, '作废激活码', {
      type: 'warning',
      confirmButtonText: '确认作废',
    });
  } catch {
    return;
  }
  voidingId.value = row.id;
  try {
    await voidActivationCode(validCourseId(), row.id);
    ElMessage.success('激活码已作废');
    await reload();
  } catch (error) {
    ElMessage.error(readError(error, '作废失败'));
  } finally {
    voidingId.value = null;
  }
}

function statusLabel(status: ActivationCodeStatus): string {
  return { unused: '未使用', redeemed: '已兑换', void: '已作废', expired: '已过期' }[status];
}

function statusType(status: ActivationCodeStatus): 'success' | 'info' | 'danger' | 'warning' {
  const types: Record<ActivationCodeStatus, 'success' | 'info' | 'danger' | 'warning'> = {
    unused: 'success',
    redeemed: 'info',
    void: 'danger',
    expired: 'warning',
  };
  return types[status];
}

function readError(error: unknown, fallback: string): string {
  const value = error as {
    domainMessage?: string;
    response?: { data?: { error?: { message?: string } } };
  };
  const code = value.domainMessage ?? value.response?.data?.error?.message;
  const messages: Record<string, string> = {
    COURSE_NOT_PUBLISHED: '仅已发布课程可生成激活码',
    COURSE_NOT_PAID: '仅收费课程可生成激活码',
    ACTIVATION_CODE_NOT_VOIDABLE: '已兑换或已作废的激活码不能作废',
    DEPARTMENT_OUT_OF_SCOPE: '你无权管理该课程的激活码',
  };
  return code ? (messages[code] ?? code) : fallback;
}

onMounted(() => {
  if (!Number.isInteger(courseId.value) || courseId.value <= 0) {
    void router.replace('/courses');
    return;
  }
  void reload();
});
</script>

<template>
  <section class="page">
    <header class="page-head">
      <div>
        <el-button link @click="router.push('/courses')">返回课程管理</el-button>
        <h1>课程激活码</h1>
      </div>
      <el-button type="primary" :icon="Plus" @click="createOpen = true">生成激活码</el-button>
    </header>

    <div class="filters">
      <el-select v-model="filters.status" clearable placeholder="全部状态" style="width: 160px">
        <el-option label="未使用" value="unused" />
        <el-option label="已兑换" value="redeemed" />
        <el-option label="已作废" value="void" />
        <el-option label="已过期" value="expired" />
      </el-select>
      <el-button
        @click="
          filters.page = 1;
          reload();
        "
        >筛选</el-button
      >
    </div>

    <el-alert v-if="errorMessage" :title="errorMessage" type="error" :closable="false" show-icon />
    <el-table v-loading="loading" :data="items" stripe empty-text="暂无激活码">
      <el-table-column prop="display_code" label="激活码" min-width="170" />
      <el-table-column label="状态" width="110">
        <template #default="{ row }">
          <el-tag :type="statusType(row.status)" effect="light">{{
            statusLabel(row.status)
          }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="expires_at" label="过期时间" width="180">
        <template #default="{ row }">{{ row.expires_at ?? '不过期' }}</template>
      </el-table-column>
      <el-table-column label="兑换学员" min-width="160">
        <template #default="{ row }">
          <span v-if="row.redeemed_by"
            >{{ row.redeemed_by.nickname }} (#{{ row.redeemed_by.account_id }})</span
          >
          <span v-else>—</span>
        </template>
      </el-table-column>
      <el-table-column prop="redeemed_at" label="兑换时间" width="180">
        <template #default="{ row }">{{ row.redeemed_at ?? '—' }}</template>
      </el-table-column>
      <el-table-column prop="created_at" label="生成时间" width="180" />
      <el-table-column label="操作" width="100" fixed="right">
        <template #default="{ row }">
          <el-button
            v-if="row.status === 'unused' || row.status === 'expired'"
            link
            type="danger"
            :icon="Delete"
            :loading="voidingId === row.id"
            @click="voidCode(row)"
            >作废</el-button
          >
        </template>
      </el-table-column>
    </el-table>

    <AdminListPager
      v-model:page="filters.page"
      v-model:page-size="filters.limit"
      :total="total"
      @change="reload"
    />

    <el-dialog v-model="createOpen" title="生成激活码" width="480px">
      <el-form label-position="top" @submit.prevent="generate">
        <el-form-item label="生成数量" required>
          <el-input-number v-model="createForm.quantity" :min="1" :max="1000" :step="1" />
        </el-form-item>
        <el-form-item label="过期时间">
          <el-date-picker
            v-model="createForm.expires_at"
            type="datetime"
            value-format="YYYY-MM-DDTHH:mm:ssZ"
            placeholder="不设置则长期有效"
            style="width: 100%"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="createOpen = false">取消</el-button>
        <el-button type="primary" :loading="generating" @click="generate">确认生成</el-button>
      </template>
    </el-dialog>

    <el-dialog
      :model-value="codesOpen"
      title="本批激活码"
      width="620px"
      :close-on-click-modal="false"
      @close="closeGeneratedCodes"
    >
      <div class="generated-head">
        <span>共 {{ generatedCodes.length }} 枚</span>
        <el-button :icon="CopyDocument" @click="copyCodes">复制全部</el-button>
      </div>
      <pre class="generated-codes">{{ generatedCodes.join('\n') }}</pre>
      <template #footer>
        <el-button type="primary" @click="closeGeneratedCodes">完成</el-button>
      </template>
    </el-dialog>
  </section>
</template>

<style scoped>
.page {
  display: grid;
  gap: 16px;
  background: #fff;
  border-radius: 8px;
  padding: 20px;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
}
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 16px;
}
.page-head h1 {
  margin: 6px 0 0;
  font-size: 22px;
}
.filters {
  display: flex;
  gap: 10px;
}
.pager {
  justify-self: end;
}
.generated-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
}
.generated-codes {
  max-height: 360px;
  overflow: auto;
  margin: 0;
  padding: 14px;
  background: #f5f7fa;
  border: 1px solid var(--el-border-color);
  border-radius: 6px;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  line-height: 1.7;
}
@media (max-width: 640px) {
  .page-head {
    align-items: stretch;
    flex-direction: column;
  }
  .filters {
    flex-wrap: wrap;
  }
}
</style>
