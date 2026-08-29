<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import type {
  ModerationAction,
  ModerationLogDTO,
  ModerationLogListDTO,
  ModerationObjectType,
  StaffDTO,
} from '@learn-site/contracts';
import {
  listModerationLogs,
  restoreModeratedContent,
  type ModerationLogListParams,
} from '@/api/audit';
import { hasPermission } from '@/api/http';
import { listStaff } from '@/api/org';

defineOptions({ name: 'AuditLogView' });

const list = ref<ModerationLogListDTO | null>(null);
const loading = ref(false);
const restoringId = ref<number | null>(null);
const errorMsg = ref<string | null>(null);
const optionPageSize = 20;
const staffOptions = ref<StaffDTO[]>([]);
const staffOptionsPage = ref(0);
const staffOptionsTotal = ref(0);
const staffOptionsLoading = ref(false);
const staffOptionsQuery = ref('');
let staffRequestId = 0;

const filters = ref({
  action: '' as ModerationAction | '',
  object_type: '' as ModerationObjectType | '',
  staff_id: null as number | null,
  page: 1,
  limit: 20,
});

const total = computed(() => list.value?.total ?? 0);
const totalPages = computed(() =>
  list.value ? Math.max(1, Math.ceil(list.value.total / list.value.limit)) : 1,
);
const canRestore = computed(() => hasPermission('review.moderate'));
const hasMoreStaff = computed(
  () => staffOptionsPage.value * optionPageSize < staffOptionsTotal.value,
);

function staffOptionLabel(staff: StaffDTO): string {
  const name = staff.display_name || staff.login;
  return `#${staff.account_id} ${name} (${staff.login})`;
}

function mergeOptions<T>(current: T[], next: T[], getId: (item: T) => number): T[] {
  const seen = new Set<number>();
  return [...current, ...next].filter((item) => {
    const id = getId(item);
    if (seen.has(id)) return false;
    seen.add(id);
    return true;
  });
}

function replaceOptions<T>(
  current: T[],
  next: T[],
  selectedId: number | null,
  getId: (item: T) => number,
): T[] {
  if (selectedId === null || next.some((item) => getId(item) === selectedId)) return next;
  const selected = current.find((item) => getId(item) === selectedId);
  return selected ? [selected, ...next] : next;
}

async function loadStaffOptions(
  query = staffOptionsQuery.value,
  page = 1,
  append = false,
): Promise<void> {
  const requestId = ++staffRequestId;
  staffOptionsLoading.value = true;
  const trimmedQuery = query.trim();
  staffOptionsQuery.value = trimmedQuery;
  try {
    const result = await listStaff({
      ...(trimmedQuery ? { search: trimmedQuery } : {}),
      page,
      limit: optionPageSize,
    });
    if (requestId !== staffRequestId) return;
    staffOptions.value = append
      ? mergeOptions(staffOptions.value, result.items, (item) => item.account_id)
      : replaceOptions(
          staffOptions.value,
          result.items,
          filters.value.staff_id,
          (item) => item.account_id,
        );
    staffOptionsPage.value = result.page;
    staffOptionsTotal.value = result.total;
  } catch {
    if (requestId !== staffRequestId) return;
    if (!append) staffOptions.value = [];
    staffOptionsPage.value = 0;
    staffOptionsTotal.value = 0;
  } finally {
    if (requestId === staffRequestId) staffOptionsLoading.value = false;
  }
}

function searchStaff(query: string): void {
  void loadStaffOptions(query);
}

function loadMoreStaff(): void {
  if (staffOptionsLoading.value || !hasMoreStaff.value) return;
  void loadStaffOptions(staffOptionsQuery.value, staffOptionsPage.value + 1, true);
}

function onStaffVisibleChange(visible: boolean): void {
  if (visible && staffOptionsPage.value === 0) void loadStaffOptions();
}

async function reload(): Promise<void> {
  loading.value = true;
  errorMsg.value = null;
  try {
    const params: ModerationLogListParams = {
      page: filters.value.page,
      limit: filters.value.limit,
    };
    if (filters.value.object_type) params.object_type = filters.value.object_type;
    if (filters.value.action) params.action = filters.value.action;
    if (filters.value.staff_id !== null && filters.value.staff_id > 0) {
      params.staff_id = filters.value.staff_id;
    }
    list.value = await listModerationLogs(params);
  } catch (error) {
    errorMsg.value = (error as Error).message || 'load_failed';
  } finally {
    loading.value = false;
  }
}

async function restore(row: ModerationLogDTO): Promise<void> {
  restoringId.value = row.id;
  errorMsg.value = null;
  try {
    await restoreModeratedContent({
      object_type: row.object_type,
      object_id: row.object_id,
    });
    await reload();
  } catch (error) {
    errorMsg.value = (error as Error).message || 'restore_failed';
  } finally {
    restoringId.value = null;
  }
}

onMounted(() => void reload());
</script>

<template>
  <main class="page">
    <header class="head">
      <h1 class="display">审核记录</h1>
      <p class="muted">共 {{ total }} 条</p>
    </header>

    <el-form
      class="filters filter-form"
      inline
      aria-label="审核记录筛选"
      @submit.prevent="((filters.page = 1), reload())"
    >
      <el-form-item label="操作">
        <el-select
          v-model="filters.action"
          class="filter-control"
          clearable
          name="action"
          data-field="action"
          placeholder="全部"
          :teleported="false"
        >
          <el-option label="全部" value="" />
          <el-option label="隐藏" value="hide" />
          <el-option label="恢复" value="restore" />
        </el-select>
      </el-form-item>
      <el-form-item label="对象">
        <el-select
          v-model="filters.object_type"
          class="filter-control"
          clearable
          name="object_type"
          data-field="object_type"
          placeholder="全部"
          :teleported="false"
        >
          <el-option label="全部" value="" />
          <el-option label="评价" value="review" />
          <el-option label="回复" value="reply" />
        </el-select>
      </el-form-item>
      <el-form-item label="操作者">
        <el-select
          v-model="filters.staff_id"
          class="filter-control option-filter"
          name="staff_id"
          filterable
          remote
          clearable
          :teleported="false"
          :loading="staffOptionsLoading"
          :remote-method="searchStaff"
          :reserve-keyword="false"
          placeholder="选择管理员"
          no-data-text="暂无管理员"
          no-match-text="无匹配管理员"
          data-field="staff_id"
          @visible-change="onStaffVisibleChange"
        >
          <el-option
            v-for="staff in staffOptions"
            :key="staff.account_id"
            :label="staffOptionLabel(staff)"
            :value="staff.account_id"
          />
          <template #footer>
            <div v-if="staffOptionsTotal > 0" class="option-footer">
              <el-button
                v-if="hasMoreStaff"
                text
                type="primary"
                size="small"
                :loading="staffOptionsLoading"
                data-action="load-more-staff"
                @click="loadMoreStaff"
              >
                加载更多
              </el-button>
              <span v-else>已加载全部</span>
            </div>
          </template>
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-button
          class="btn btn-primary"
          :disabled="loading"
          data-action="query"
          native-type="submit"
        >
          查询
        </el-button>
      </el-form-item>
    </el-form>

    <p v-if="errorMsg" class="error">{{ errorMsg }}</p>
    <p v-else-if="loading" class="notice">加载中…</p>

    <el-table v-else v-loading="loading" :data="list?.items ?? []" stripe class="data">
      <el-table-column prop="created_at" label="时间" min-width="175" />
      <el-table-column label="操作" min-width="100">
        <template #default="{ row }">{{ row.action === 'hide' ? '隐藏' : '恢复' }}</template>
      </el-table-column>
      <el-table-column label="对象" min-width="140">
        <template #default="{ row }"
          >{{ row.object_type === 'review' ? '评价' : '回复' }} #{{ row.object_id }}</template
        >
      </el-table-column>
      <el-table-column label="原因" min-width="220">
        <template #default="{ row }">{{ row.reason || '—' }}</template>
      </el-table-column>
      <el-table-column label="操作者" min-width="140">
        <template #default="{ row }">{{ row.staff_login || `#${row.staff_id}` }}</template>
      </el-table-column>
      <el-table-column label="处理" min-width="100">
        <template #default="{ row }">
          <el-button
            v-if="row.restorable && canRestore"
            class="btn"
            data-action="restore"
            :disabled="restoringId === row.id"
            @click="restore(row)"
          >
            {{ restoringId === row.id ? '恢复中…' : '恢复' }}
          </el-button>
          <span v-else>—</span>
        </template>
      </el-table-column>
      <template #empty><el-empty description="还没有审核记录" :image-size="88" /></template>
    </el-table>

    <nav v-if="list && totalPages > 1" class="pager" aria-label="分页">
      <el-button :disabled="filters.page <= 1" @click="((filters.page -= 1), reload())">
        上一页
      </el-button>
      <span>{{ filters.page }} / {{ totalPages }}</span>
      <el-button :disabled="filters.page >= totalPages" @click="((filters.page += 1), reload())">
        下一页
      </el-button>
    </nav>
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
.muted,
.notice,
.empty {
  color: var(--color-text-muted, #5b6472);
  margin: 0;
  font-size: 0.85rem;
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
.option-filter {
  width: 260px;
}
.option-footer {
  display: flex;
  min-height: 36px;
  align-items: center;
  justify-content: center;
  color: #829ab1;
  font-size: 12px;
}
.error {
  color: #b42318;
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
  font-size: 0.85rem;
  text-align: left;
  vertical-align: top;
}
.data th {
  background: var(--color-bg-soft, #fafbfd);
}
.btn {
  padding: 6px 14px;
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
.pager {
  display: flex;
  gap: 12px;
  align-items: center;
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
  .option-filter {
    width: 100%;
  }
}
</style>
