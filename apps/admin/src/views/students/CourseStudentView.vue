<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ElMessageBox } from 'element-plus';
import { hasPermission } from '@/api/http';
import {
  listCourseStudents,
  revokeCourseStudent,
  resetCourseStudentProgress,
  type CourseStudentDTO,
  type CourseStudentListDTO,
} from '@/api/courseStudents';
import AdminListPager from '@/components/AdminListPager.vue';

defineOptions({ name: 'CourseStudentView' });

const route = useRoute();
const courseId = computed(() => {
  const raw = route.params.id;
  if (raw === undefined || raw === null || Array.isArray(raw)) return null;
  const id = Number(raw);
  return Number.isFinite(id) && id > 0 ? id : null;
});

const list = ref<CourseStudentListDTO | null>(null);
const loading = ref(false);
const errorMsg = ref<string | null>(null);
const submittingId = ref<number | null>(null);

const filters = ref({
  status: '' as '' | 'active' | 'revoked',
  source: '' as '' | 'free' | 'purchase' | 'activation_code',
  learning_status: '' as '' | 'not_started' | 'in_progress' | 'completed',
  page: 1,
  limit: 20,
});

const total = computed(() => list.value?.total ?? 0);
const canReset = computed(() => hasPermission('course_student.reset'));
const canRevoke = computed(() => hasPermission('course_student.revoke_free'));

async function reload(): Promise<void> {
  if (courseId.value === null) return;
  loading.value = true;
  errorMsg.value = null;
  try {
    const params: {
      status?: 'active' | 'revoked';
      source?: 'free' | 'purchase' | 'activation_code';
      learning_status?: 'not_started' | 'in_progress' | 'completed';
      page: number;
      limit: number;
    } = { page: filters.value.page, limit: filters.value.limit };
    if (filters.value.status) params.status = filters.value.status;
    if (filters.value.source) params.source = filters.value.source;
    if (filters.value.learning_status) params.learning_status = filters.value.learning_status;
    list.value = await listCourseStudents(courseId.value, params);
  } catch (err) {
    errorMsg.value = (err as Error).message || 'load_failed';
  } finally {
    loading.value = false;
  }
}

watch(
  () => [route.name, route.params.id] as const,
  () => {
    if (route.name !== 'course-students' || courseId.value === null) return;
    filters.value.page = 1;
    void reload();
  },
);

async function revoke(row: CourseStudentDTO): Promise<void> {
  if (submittingId.value !== null) return;
  if (row.entitlement_status !== 'active') return;
  if (row.source !== 'free') {
    errorMsg.value = '仅免费授权可在此撤销, 付费授权走退款流程.';
    return;
  }
  let reason = '';
  try {
    const out = await ElMessageBox.prompt(`请填写撤销 ${row.login} 免费授权的原因`, '撤销授权', {
      inputPlaceholder: '撤销原因',
      inputValidator: (value) => (value.trim() ? true : '请填写撤销原因'),
      confirmButtonText: '撤销',
      cancelButtonText: '取消',
      type: 'warning',
    });
    reason = out.value.trim();
  } catch {
    return;
  }
  submittingId.value = row.account_id;
  try {
    if (courseId.value === null) return;
    await revokeCourseStudent(courseId.value, row.account_id, reason);
    await reload();
  } catch (err) {
    errorMsg.value = revokeErrorMessage(err);
  } finally {
    submittingId.value = null;
  }
}

async function resetProgress(row: CourseStudentDTO): Promise<void> {
  if (submittingId.value !== null) return;
  try {
    await ElMessageBox.confirm(`确认重置 ${row.login} 在本课程的全部学习进度？`, '重置进度', {
      type: 'warning',
      confirmButtonText: '重置',
      cancelButtonText: '取消',
    });
  } catch {
    return;
  }
  submittingId.value = row.account_id;
  try {
    if (courseId.value === null) return;
    await resetCourseStudentProgress(courseId.value, row.account_id);
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

function sourceLabel(src: CourseStudentDTO['source']): string {
  if (src === 'free') return '免费授权';
  if (src === 'activation_code') return '激活码兑换';
  return '购买';
}
function entitlementLabel(s: CourseStudentDTO['entitlement_status']): string {
  return s === 'active' ? '有效' : '已撤销';
}
function learningLabel(status: CourseStudentDTO['learning_status']): string {
  if (status === 'completed') return '已完成';
  if (status === 'in_progress') return '学习中';
  return '未开始';
}

function revokeErrorMessage(error: unknown): string {
  const apiError = (
    error as {
      response?: { data?: { error?: { code?: string; message?: string } } };
    }
  ).response?.data?.error;
  if (apiError?.code === 'FORBIDDEN' && apiError.message === 'PAID_NOT_REVOCABLE') {
    return '付费授权不能在此撤销。';
  }
  if (apiError?.message === 'REVOKE_REASON_REQUIRED') return '请填写撤销原因。';
  return apiError?.message || (error as Error).message || '撤销失败，请稍后再试。';
}
</script>

<template>
  <main class="page">
    <header class="head">
      <h1 class="display">课程 {{ courseId ?? '—' }} · 学员名单</h1>
      <p class="muted">共 {{ total }} 人</p>
    </header>

    <el-form class="filters filter-form" inline @submit.prevent="((filters.page = 1), reload())">
      <el-form-item label="授权状态">
        <el-select
          v-model="filters.status"
          class="filter-control"
          clearable
          :teleported="false"
          placeholder="全部"
        >
          <el-option label="全部" value="" />
          <el-option label="有效" value="active" />
          <el-option label="已撤销" value="revoked" />
        </el-select>
      </el-form-item>
      <el-form-item label="加入来源">
        <el-select
          v-model="filters.source"
          class="filter-control"
          clearable
          name="source"
          data-field="source"
          placeholder="全部"
          :teleported="false"
        >
          <el-option label="全部" value="" />
          <el-option label="免费加入" value="free" />
          <el-option label="付费取得" value="purchase" />
          <el-option label="激活码兑换" value="activation_code" />
        </el-select>
      </el-form-item>
      <el-form-item label="学习状态">
        <el-select
          v-model="filters.learning_status"
          class="filter-control"
          clearable
          name="learning_status"
          data-field="learning_status"
          placeholder="全部"
          :teleported="false"
        >
          <el-option label="全部" value="" />
          <el-option label="未开始" value="not_started" />
          <el-option label="学习中" value="in_progress" />
          <el-option label="已完成" value="completed" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-button
          class="btn btn-primary"
          data-action="query"
          :disabled="loading"
          native-type="submit"
        >
          查询
        </el-button>
      </el-form-item>
    </el-form>

    <p v-if="errorMsg" class="error">{{ errorMsg }}</p>
    <p v-else-if="loading" class="notice">加载中…</p>

    <el-table v-else v-loading="loading" :data="list?.items ?? []" stripe class="data">
      <el-table-column prop="login" label="账号" min-width="140" />
      <el-table-column prop="nickname" label="昵称" min-width="120" />
      <el-table-column label="账号状态" min-width="110">
        <template #default="{ row }">
          <span :data-status="row.account_status">{{ row.account_status }}</span>
        </template>
      </el-table-column>
      <el-table-column label="来源" min-width="100">
        <template #default="{ row }">{{ sourceLabel(row.source) }}</template>
      </el-table-column>
      <el-table-column label="授权" min-width="100">
        <template #default="{ row }">
          <span :data-entitlement="row.entitlement_status">{{
            entitlementLabel(row.entitlement_status)
          }}</span>
        </template>
      </el-table-column>
      <el-table-column label="进度" min-width="80">
        <template #default="{ row }">{{ row.progress_percent }}%</template>
      </el-table-column>
      <el-table-column label="学习状态" min-width="110">
        <template #default="{ row }">{{ learningLabel(row.learning_status) }}</template>
      </el-table-column>
      <el-table-column label="最近学习" min-width="170">
        <template #default="{ row }">{{ row.last_learning_at || '—' }}</template>
      </el-table-column>
      <el-table-column label="完成时间" min-width="170">
        <template #default="{ row }">{{ row.completed_at || '—' }}</template>
      </el-table-column>
      <el-table-column label="最近登录" min-width="170">
        <template #default="{ row }">{{ row.last_login_at || '—' }}</template>
      </el-table-column>
      <el-table-column prop="enrolled_at" label="授权时间" min-width="170" />
      <el-table-column label="撤销时间" min-width="170">
        <template #default="{ row }">{{ row.revoked_at || '—' }}</template>
      </el-table-column>
      <el-table-column label="撤销原因" min-width="180">
        <template #default="{ row }">{{ row.revoked_reason || '—' }}</template>
      </el-table-column>
      <el-table-column v-if="canReset || canRevoke" label="操作" min-width="180" fixed="right">
        <template #default="{ row }">
          <div class="actions">
            <el-button
              v-if="canReset"
              class="btn"
              :disabled="submittingId === row.account_id"
              @click="resetProgress(row)"
            >
              重置进度
            </el-button>
            <el-button
              v-if="canRevoke && row.entitlement_status === 'active' && row.source === 'free'"
              class="btn warn"
              data-action="revoke"
              :disabled="submittingId === row.account_id"
              @click="revoke(row)"
            >
              撤销授权
            </el-button>
          </div>
        </template>
      </el-table-column>
      <template #empty><el-empty description="还没有学员选修这门课" :image-size="88" /></template>
    </el-table>

    <AdminListPager
      v-model:page="filters.page"
      v-model:page-size="filters.limit"
      :total="total"
      @change="reload"
    />
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
.error {
  color: #b42318;
  margin: 0;
}
.notice {
  color: var(--color-text-muted, #5b6472);
  margin: 0;
}
.empty {
  color: var(--color-text-muted, #5b6472);
  margin: 0;
}
.data {
  width: 100%;
  border-collapse: collapse;
  background: #fff;
}
.data th,
.data td {
  padding: 8px 10px;
  border-bottom: 1px solid var(--color-border, #e3e6ee);
  font-size: 0.9rem;
  text-align: left;
}
.data th {
  background: var(--color-bg-soft, #fafbfd);
}
[data-status='active'] {
  color: #137a3c;
}
[data-status='disabled'] {
  color: #b42318;
}
[data-entitlement='active'] {
  color: #137a3c;
}
[data-entitlement='revoked'] {
  color: #b42318;
}
.actions {
  display: flex;
  gap: 6px;
}
.btn.warn {
  color: #b42318;
  border-color: #f3c1bb;
  background: #fff5f3;
}
.btn {
  padding: 6px 12px;
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
.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
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
  .filter-control {
    width: 100%;
  }
}
</style>
