<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import {
  kickLearner,
  listLearners,
  resetLearnerPassword,
  type LearnerAccountDTO,
  type LearnerListDTO,
} from '@/api/learners';
import AdminListPager from '@/components/AdminListPager.vue';

defineOptions({ name: 'LearnerListView' });

const router = useRouter();
const list = ref<LearnerListDTO | null>(null);
const loading = ref(false);
const errorMsg = ref<string | null>(null);
const submittingId = ref<number | null>(null);
const resetDialog = ref<{ account: LearnerAccountDTO; newPassword: string } | null>(null);

const filters = ref({
  status: '' as '' | 'active' | 'disabled',
  search: '',
  page: 1,
  limit: 20,
});

const total = computed(() => list.value?.total ?? 0);
function statusLabel(status: LearnerAccountDTO['status']): string {
  return status === 'active' ? '正常' : '已停用';
}

async function reload(): Promise<void> {
  loading.value = true;
  errorMsg.value = null;
  try {
    const params: {
      status?: 'active' | 'disabled';
      search?: string;
      page: number;
      limit: number;
    } = { page: filters.value.page, limit: filters.value.limit };
    if (filters.value.status) params.status = filters.value.status;
    if (filters.value.search) params.search = filters.value.search;
    list.value = await listLearners(params);
  } catch (err) {
    errorMsg.value = (err as Error).message || 'load_failed';
  } finally {
    loading.value = false;
  }
}

function goProgress(account: LearnerAccountDTO): void {
  void router.push({ name: 'learner-progress', params: { id: String(account.account_id) } });
}

function goRecords(account: LearnerAccountDTO): void {
  void router.push({ name: 'learner-records', params: { id: String(account.account_id) } });
}

async function doKick(account: LearnerAccountDTO): Promise<void> {
  if (submittingId.value !== null) return;
  if (!confirm(`强制下线 ${account.login} 的所有会话？此操作不可撤销。`)) return;
  submittingId.value = account.account_id;
  try {
    await kickLearner(account.account_id);
    await reload();
  } catch (err) {
    errorMsg.value = (err as Error).message || 'kick_failed';
  } finally {
    submittingId.value = null;
  }
}

function openReset(account: LearnerAccountDTO): void {
  resetDialog.value = { account, newPassword: '' };
}

async function submitReset(): Promise<void> {
  const dlg = resetDialog.value;
  if (!dlg) return;
  if (dlg.newPassword.length < 8 || dlg.newPassword.length > 72) {
    errorMsg.value = '密码长度需在 8–72 之间';
    return;
  }
  submittingId.value = dlg.account.account_id;
  try {
    await resetLearnerPassword(dlg.account.account_id, dlg.newPassword);
    resetDialog.value = null;
    await reload();
  } catch (err) {
    errorMsg.value = (err as Error).message || 'reset_failed';
  } finally {
    submittingId.value = null;
  }
}

onMounted(() => {
  void reload();
});
</script>

<template>
  <main class="page">
    <header class="head">
      <h1 class="display">学员账号</h1>
      <p class="muted">共 {{ total }} 条</p>
    </header>

    <el-form class="filters filter-form" inline @submit.prevent="((filters.page = 1), reload())">
      <el-form-item label="状态">
        <el-select
          v-model="filters.status"
          class="filter-control"
          clearable
          placeholder="全部"
          :teleported="false"
        >
          <el-option label="全部" value="" />
          <el-option label="正常" value="active" />
          <el-option label="已停用" value="disabled" />
        </el-select>
      </el-form-item>
      <el-form-item label="关键字">
        <el-input
          v-model="filters.search"
          class="filter-control filter-control--search"
          clearable
          type="search"
          placeholder="账号或姓名"
          @keyup.enter="((filters.page = 1), reload())"
        />
      </el-form-item>
      <el-form-item>
        <el-button class="btn btn-primary" :disabled="loading" native-type="submit">
          查询
        </el-button>
      </el-form-item>
    </el-form>

    <p v-if="errorMsg" class="error">{{ errorMsg }}</p>
    <p v-else-if="loading" class="notice">加载中…</p>

    <el-table v-else v-loading="loading" :data="list?.items ?? []" stripe class="learner-table">
      <el-table-column prop="login" label="账号" min-width="140" />
      <el-table-column prop="display_name" label="姓名" min-width="120" />
      <el-table-column label="状态" width="100">
        <template #default="{ row }">
          <span :data-status="row.status">{{ statusLabel(row.status) }}</span>
        </template>
      </el-table-column>
      <el-table-column label="学习摘要" min-width="170">
        <template #default="{ row }">
          {{ row.course_count }} 门 / 完成 {{ row.completed_course_count }} 门
        </template>
      </el-table-column>
      <el-table-column label="购买摘要" min-width="170">
        <template #default="{ row }">
          {{ row.successful_order_count }} 单 / ¥{{ row.total_paid_amount.toFixed(2) }}
        </template>
      </el-table-column>
      <el-table-column prop="last_login_at" label="最近登录" min-width="175">
        <template #default="{ row }">{{ row.last_login_at || '—' }}</template>
      </el-table-column>
      <el-table-column prop="created_at" label="创建时间" min-width="175" />
      <el-table-column label="操作" min-width="320" fixed="right">
        <template #default="{ row }">
          <div class="actions">
            <el-button link type="primary" @click="goProgress(row)"> 学习进度 </el-button>
            <el-button link type="primary" @click="goRecords(row)"> 学习记录 </el-button>
            <el-button
              class="btn"
              :disabled="submittingId === row.account_id"
              @click="openReset(row)"
            >
              重置密码
            </el-button>
            <el-button
              class="btn warn"
              :disabled="submittingId === row.account_id"
              @click="doKick(row)"
            >
              强制下线
            </el-button>
          </div>
        </template>
      </el-table-column>
      <template #empty><el-empty description="没有匹配的学员" :image-size="88" /></template>
    </el-table>

    <AdminListPager
      v-model:page="filters.page"
      v-model:page-size="filters.limit"
      :total="total"
      @change="reload"
    />

    <div v-if="resetDialog" class="modal-backdrop" @click.self="resetDialog = null">
      <div class="modal">
        <h2>重置 {{ resetDialog.account.login }} 的密码</h2>
        <el-form :model="resetDialog" label-position="top">
          <el-form-item label="新密码 (8–72)">
            <el-input
              v-model="resetDialog.newPassword"
              clearable
              type="text"
              minlength="8"
              maxlength="72"
            />
          </el-form-item>
        </el-form>
        <p class="muted">重置后所有会话会失效.</p>
        <div class="modal-actions">
          <el-button class="btn" @click="resetDialog = null">取消</el-button>
          <el-button
            class="btn btn-primary"
            :disabled="submittingId === resetDialog.account.account_id"
            @click="submitReset"
          >
            确认重置
          </el-button>
        </div>
      </div>
    </div>
  </main>
</template>

<style scoped>
.page {
  display: grid;
  gap: 16px;
}
.head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
}
.display {
  margin: 0;
  font-size: 1.4rem;
}
.muted {
  color: var(--color-text-muted, #5b6472);
  margin: 0;
  font-size: 0.85rem;
}
.filters {
  display: flex;
  gap: 12px;
  align-items: end;
  flex-wrap: wrap;
}
.filter-form {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0 18px;
  min-width: 0;
}
.filter-form :deep(.el-form-item) {
  margin-bottom: 0;
}
.filter-form :deep(.el-form-item__label) {
  color: #52667a;
  font-size: 13px;
  font-weight: 600;
}
.filter-control {
  width: 168px;
}
.filter-control--search {
  width: 200px;
}
.error {
  color: #b42318;
  margin: 0;
}
.notice {
  color: var(--color-text-muted, #5b6472);
  margin: 0;
}
.learner-table {
  width: 100%;
  background: #fff;
}
[data-status='active'] {
  color: #137a3c;
}
[data-status='disabled'] {
  color: #b42318;
}
.actions {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}
.btn {
  padding: 4px 10px;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
  background: #fff;
  font: inherit;
  cursor: pointer;
}
.btn-primary {
  background: var(--color-primary, #2563eb);
  color: #fff;
  border-color: transparent;
}
.btn.warn {
  color: #b42318;
  border-color: #f3c1bb;
  background: #fff5f3;
}
.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.35);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
}
.modal {
  background: #fff;
  padding: 20px 24px;
  border-radius: 8px;
  min-width: 320px;
  display: grid;
  gap: 12px;
}
.modal h2 {
  margin: 0;
  font-size: 1.1rem;
}
.modal-actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}
@media (max-width: 560px) {
  .filter-form {
    display: grid;
    grid-template-columns: 1fr;
    gap: 4px;
  }
  .filter-form :deep(.el-form-item) {
    display: grid;
    grid-template-columns: 58px minmax(0, 1fr);
    align-items: center;
    gap: 8px;
  }
  .filter-control,
  .filter-control--search {
    width: 100%;
  }
}
</style>
