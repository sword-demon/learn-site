<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { useRoute, useRouter } from 'vue-router';
import {
  OpsSourceTypeSchema,
  type OpsException,
  type OpsInboxListRequest,
  type OpsSourceType,
  type OpsState,
} from '@contracts/opsInbox';
import type { StaffDTO } from '@learn-site/contracts';
import AdminListPager from '@/components/AdminListPager.vue';
import { formatDateTime, parseDateTime, toIso8601 } from '@/utils/datetime';
import { listStaff } from '@/api/org';
import { transitionOpsInbox } from '@/api/opsInbox';
import { useOpsInboxPolling } from '@/composables/useOpsInboxPolling';
import ContentTodoDrawer from './ContentTodoDrawer.vue';

defineOptions({ name: 'OpsInboxView' });

const router = useRouter();
const route = useRoute();
const contentTodoId = ref<number | null>(null);
const contentTodoVisible = ref(false);
const assignOpen = ref(false);
const assignTarget = ref<OpsException | null>(null);
const assignStaffId = ref<number | null>(null);
const assigning = ref(false);
const optionPageSize = 20;
const staffOptions = ref<StaffDTO[]>([]);
const staffOptionsPage = ref(0);
const staffOptionsTotal = ref(0);
const staffOptionsLoading = ref(false);
const staffOptionsQuery = ref('');
let staffRequestId = 0;
const hasMoreStaff = computed(
  () => staffOptionsPage.value * optionPageSize < staffOptionsTotal.value,
);

function parsePositiveInt(value: unknown): number | null {
  const raw = Array.isArray(value) ? value[0] : value;
  if (raw === null || raw === undefined || raw === '') return null;
  const parsed = typeof raw === 'number' ? raw : Number(String(raw));
  if (!Number.isFinite(parsed) || parsed <= 0) return null;
  return Math.trunc(parsed);
}

watch(
  () => route.query.content_todo_id,
  (value) => {
    const id = parsePositiveInt(value);
    if (id === null) return;
    contentTodoId.value = id;
    contentTodoVisible.value = true;
  },
  { immediate: true },
);
const filters = reactive<{
  source_type: OpsSourceType | '';
  state: OpsState;
  age_min_hours: number | undefined;
  sort_by: 'weight' | 'age_seconds';
  sort_dir: 'asc' | 'desc';
  page: number;
  limit: number;
  content_only: boolean;
}>({
  source_type: '',
  state: 'open',
  age_min_hours: undefined,
  sort_by: 'weight',
  sort_dir: 'desc',
  page: 1,
  limit: 20,
  content_only: false,
});

function request(): OpsInboxListRequest {
  const value: OpsInboxListRequest = {
    state: filters.state,
    sort_by: filters.sort_by,
    sort_dir: filters.sort_dir,
    page: filters.page,
    limit: filters.limit,
  };
  if (filters.source_type) value.source_type = filters.source_type;
  if (filters.age_min_hours !== undefined) value.age_min_hours = filters.age_min_hours;
  if (filters.content_only) value.content_only = true;
  return value;
}

const { items, total, counts_by_source, loading, listError, degraded, reload } =
  useOpsInboxPolling(request);
const visibleCounts = computed(() =>
  Object.entries(counts_by_source.value).filter(([, count]) => count > 0),
);

function sourceLabel(source: OpsSourceType): string {
  return {
    course_unpublished: '未发布课程',
    map_anomaly: '学习地图',
    question_pending: '待答问题',
    feedback_pending: '待处理课程意见反馈',
    payment_unknown: '支付未知',
    queue_failed: '队列失败',
    long_pending: '长期积压',
  }[source];
}
function stateLabel(state: OpsState): string {
  return {
    open: '待处理',
    retrying: '重试中',
    snoozed: '已搁置',
    resolved: '已处理',
    assigned: '已指派',
  }[state];
}
function impactLabel(row: OpsException): string {
  const parts = [`${row.impact.learners} 个学员`];
  if (row.impact.courses !== undefined) parts.push(`${row.impact.courses} 门课程`);
  if (row.impact.replies !== undefined) parts.push(`${row.impact.replies} 条回复`);
  if (row.impact.orders_amount_cents !== undefined)
    parts.push(`${(row.impact.orders_amount_cents / 100).toFixed(2)} 元`);
  return parts.join(' · ');
}
function tagType(severity: OpsException['severity']): 'info' | 'warning' | 'danger' {
  return severity === 'critical' ? 'danger' : severity === 'warning' ? 'warning' : 'info';
}
async function go(row: OpsException): Promise<void> {
  if (row.content_todo_id) {
    contentTodoId.value = row.content_todo_id;
    contentTodoVisible.value = true;
    return;
  }
  try {
    await router.push({
      name: row.deep_link.name,
      ...(row.deep_link.query ? { query: row.deep_link.query } : {}),
    });
  } catch {
    ElMessage.error('跳转失败');
    await reload();
  }
}
async function acknowledge(row: OpsException): Promise<void> {
  const index = items.value.findIndex((item) => item.id === row.id);
  if (index >= 0) items.value.splice(index, 1);
  try {
    await transitionOpsInbox(row.id, { to_state: 'resolved' });
    ElMessage.success('已标记为已处理');
  } catch {
    if (index >= 0) items.value.splice(index, 0, row);
    ElMessage.error('状态更新失败');
  }
}
async function snooze(row: OpsException): Promise<void> {
  try {
    const result = await ElMessageBox.prompt(
      '请输入到期时间（北京时间, 例 2026-09-14 15:30:00）',
      '搁置事项',
      {
        inputValue: formatDateTime(Date.now() + 86_400_000),
        inputValidator: (value) => {
          const time = parseDateTime(value);
          return Number.isFinite(time) && time > Date.now() + 60000
            ? true
            : '到期时间需晚于当前时间 60 秒';
        },
        type: 'warning',
      },
    );
    await transitionOpsInbox(row.id, {
      to_state: 'snoozed',
      snooze_until: toIso8601(result.value),
    });
    items.value = items.value.filter((item) => item.id !== row.id);
    ElMessage.success('已搁置');
  } catch {
    return;
  }
}
function staffOptionLabel(staff: StaffDTO): string {
  const name = staff.display_name || staff.login;
  return `#${staff.account_id} ${name}（${staff.login}）`;
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
      status: 'active',
      ...(trimmedQuery ? { search: trimmedQuery } : {}),
      page,
      limit: optionPageSize,
    });
    if (requestId !== staffRequestId) return;
    staffOptions.value = append ? [...staffOptions.value, ...result.items] : result.items;
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

function openAssign(row: OpsException): void {
  assignTarget.value = row;
  assignStaffId.value = null;
  staffOptions.value = [];
  staffOptionsPage.value = 0;
  staffOptionsTotal.value = 0;
  staffOptionsQuery.value = '';
  assignOpen.value = true;
  void loadStaffOptions('', 1);
}

function closeAssign(): void {
  assignOpen.value = false;
  assignTarget.value = null;
  assignStaffId.value = null;
}

async function confirmAssign(): Promise<void> {
  const row = assignTarget.value;
  const staffId = assignStaffId.value;
  if (!row || staffId === null || staffId <= 0) {
    ElMessage.warning('请选择要指派的员工');
    return;
  }
  assigning.value = true;
  try {
    await transitionOpsInbox(row.id, { to_state: 'assigned', assignee_id: staffId });
    items.value = items.value.filter((item) => item.id !== row.id);
    ElMessage.success('已指派');
    closeAssign();
  } catch {
    ElMessage.error('指派失败');
  } finally {
    assigning.value = false;
  }
}
async function onCommand(command: string, row: OpsException): Promise<void> {
  if (
    command === 'resolve' &&
    (row.source_type === 'question_pending' || row.source_type === 'feedback_pending')
  ) {
    if (row.content_todo_id) {
      contentTodoId.value = row.content_todo_id;
      contentTodoVisible.value = true;
      return;
    }
    ElMessage.warning('请从内容待办处理, 不能用来源已处理代替内容结果');
    return;
  }
  if (command === 'resolve') await acknowledge(row);
  else if (command === 'snooze') await snooze(row);
  else if (command === 'assign') openAssign(row);
}

defineExpose({ assignStaffId, confirmAssign });
function applyFilter(): void {
  filters.page = 1;
  void reload();
}
function filterSource(source: string): void {
  const parsed = OpsSourceTypeSchema.safeParse(source);
  if (!parsed.success) return;
  filters.source_type = parsed.data;
  filters.page = 1;
  void router.replace({ query: { source_type: parsed.data } });
  void reload();
}
</script>

<template>
  <div class="page ops-inbox-page">
    <el-form class="filter-form" inline @submit.prevent>
      <el-form-item label="异常类型">
        <el-select
          v-model="filters.source_type"
          class="filter-control"
          clearable
          placeholder="全部类型"
          @change="applyFilter"
        >
          <el-option
            v-for="source in OpsSourceTypeSchema.options"
            :key="source"
            :label="sourceLabel(source)"
            :value="source"
          />
        </el-select>
      </el-form-item>
      <el-form-item label="状态">
        <el-select
          v-model="filters.state"
          class="filter-control"
          placeholder="状态"
          @change="applyFilter"
        >
          <el-option
            v-for="state in ['open', 'retrying', 'snoozed', 'assigned', 'resolved']"
            :key="state"
            :label="stateLabel(state as OpsState)"
            :value="state"
          />
        </el-select>
      </el-form-item>
      <el-form-item label="最少积压">
        <el-input-number
          v-model="filters.age_min_hours"
          class="filter-control"
          :min="0"
          :max="720"
          controls-position="right"
          placeholder="小时"
          @change="applyFilter"
        />
      </el-form-item>
      <el-form-item label="排序">
        <el-select v-model="filters.sort_by" class="filter-control" @change="applyFilter">
          <el-option label="业务权重" value="weight" />
          <el-option label="积压年龄" value="age_seconds" />
        </el-select>
      </el-form-item>
      <el-form-item label="方向">
        <el-select v-model="filters.sort_dir" class="filter-control-sm" @change="applyFilter">
          <el-option label="降序" value="desc" />
          <el-option label="升序" value="asc" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-checkbox v-model="filters.content_only" @change="applyFilter">仅内容待办</el-checkbox>
      </el-form-item>
    </el-form>
    <el-alert v-if="listError" :title="listError" type="error" show-icon class="mb-3" />
    <el-alert
      v-if="degraded"
      title="部分异常源暂时不可用，列表可能不完整"
      type="warning"
      show-icon
      class="mb-3"
    />
    <div v-if="total > 50" class="source-chips">
      <el-button
        v-for="[source, count] in visibleCounts"
        :key="source"
        size="small"
        @click="filterSource(source)"
        >{{ sourceLabel(source as OpsSourceType) }} {{ count }}</el-button
      >
    </div>
    <el-table v-loading="loading" :data="items" row-key="id">
      <el-table-column prop="title" label="事项" min-width="250" />
      <el-table-column label="类型" width="130"
        ><template #default="{ row }">{{ sourceLabel(row.source_type) }}</template></el-table-column
      >
      <el-table-column label="级别" width="90"
        ><template #default="{ row }"
          ><el-tag :type="tagType(row.severity)">{{ row.severity }}</el-tag></template
        ></el-table-column
      >
      <el-table-column prop="age_label" label="积压" width="100" />
      <el-table-column label="影响范围" min-width="180"
        ><template #default="{ row }">{{ impactLabel(row) }}</template></el-table-column
      >
      <el-table-column prop="suggested_action" label="建议动作" min-width="160" />
      <el-table-column label="状态" width="100"
        ><template #default="{ row }">{{ stateLabel(row.state) }}</template></el-table-column
      >
      <el-table-column label="内容结果" width="130"
        ><template #default="{ row }">{{
          row.content_workflow_status ?? '—'
        }}</template></el-table-column
      >
      <el-table-column label="内容标签" width="110"
        ><template #default="{ row }">{{ row.content_label ?? '—' }}</template></el-table-column
      >
      <el-table-column label="操作" width="180" fixed="right"
        ><template #default="{ row }"
          ><div class="row-actions">
            <el-button link type="primary" @click="go(row)">跳转处理</el-button
            ><el-dropdown trigger="click" @command="(command: string) => onCommand(command, row)"
              ><el-button link>操作</el-button
              ><template #dropdown
                ><el-dropdown-menu
                  ><el-dropdown-item command="resolve">标记为已处理</el-dropdown-item
                  ><el-dropdown-item command="snooze">搁置到...</el-dropdown-item
                  ><el-dropdown-item command="assign">指派给...</el-dropdown-item></el-dropdown-menu
                ></template
              ></el-dropdown
            >
          </div></template
        ></el-table-column
      >
    </el-table>
    <ContentTodoDrawer
      v-model:visible="contentTodoVisible"
      :todo-id="contentTodoId"
      @changed="reload"
    />
    <el-empty v-if="!loading && items.length === 0" description="暂无待处理事项" />
    <AdminListPager
      v-model:page="filters.page"
      v-model:page-size="filters.limit"
      :total="total"
      @change="reload"
    />
    <el-dialog
      v-model="assignOpen"
      title="指派事项"
      width="480px"
      destroy-on-close
      @closed="closeAssign"
    >
      <p class="assign-hint">从在职员工列表中选择处理人，支持搜索姓名或登录名。</p>
      <el-select
        v-model="assignStaffId"
        class="assign-select"
        filterable
        remote
        clearable
        :loading="staffOptionsLoading"
        :remote-method="searchStaff"
        placeholder="选择员工"
        data-field="assignee_id"
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
      <template #footer>
        <el-button @click="closeAssign">取消</el-button>
        <el-button
          type="primary"
          :loading="assigning"
          data-action="confirm-assign"
          @click="confirmAssign"
        >
          确定
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
/* 行内操作按钮:dropdown 包裹会破坏 el-button 的兄弟间距与基线对齐,统一用 flex。 */
.filter-form {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0 16px;
  min-width: 0;
}

.filter-form :deep(.el-form-item) {
  margin-bottom: 12px;
}

.filter-form :deep(.el-form-item__label) {
  color: #52667a;
  font-size: 13px;
  font-weight: 600;
}

.filter-control {
  width: 168px;
}

.filter-control-sm {
  width: 112px;
}

.row-actions {
  display: flex;
  align-items: center;
  gap: 12px;
}

.assign-hint {
  margin: 0 0 12px;
  color: #6b7c93;
  font-size: 13px;
}

.assign-select {
  width: 100%;
}

.option-footer {
  display: flex;
  min-height: 36px;
  align-items: center;
  justify-content: center;
  color: #829ab1;
  font-size: 12px;
}
</style>
