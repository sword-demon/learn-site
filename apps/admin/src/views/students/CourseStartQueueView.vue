<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage, ElMessageBox } from 'element-plus';
import { hasPermission } from '@/api/http';
import {
  listCourseStartQueue,
  sendCourseStartReminders,
  type CourseStartQueueListDTO,
} from '@/api/courseStudents';
import type { CourseStartQueueItemDTO } from '@learn-site/contracts';
import AdminListPager from '@/components/AdminListPager.vue';

defineOptions({ name: 'CourseStartQueueView' });

const route = useRoute();
const router = useRouter();
const courseId = computed(() => {
  const raw = route.params.id;
  if (raw === undefined || raw === null || Array.isArray(raw)) return null;
  const id = Number(raw);
  return Number.isFinite(id) && id > 0 ? id : null;
});

const list = ref<CourseStartQueueListDTO | null>(null);
const loading = ref(false);
const errorMsg = ref<string | null>(null);
const sending = ref(false);
const selectedIds = ref<number[]>([]);

const filters = ref({
  source: '' as '' | 'free' | 'purchase' | 'activation_code',
  startup_state: '' as '' | 'never_opened' | 'opened_zero_progress',
  sort: 'idle_hours' as 'idle_hours' | 'entitled_at' | 'source',
  order: 'desc' as 'asc' | 'desc',
  page: 1,
  limit: 20,
});

const total = computed(() => list.value?.total ?? 0);
const policy = computed(() => list.value?.policy ?? null);
const canRemind = computed(() => hasPermission('notification.manage'));
const selectedRows = computed(() => {
  const items = list.value?.items ?? [];
  return items.filter((row) => selectedIds.value.includes(row.account_id));
});
const eligibleSelected = computed(() => selectedRows.value.filter((row) => row.can_remind));
const blockedSelected = computed(() => selectedRows.value.filter((row) => !row.can_remind));

function openStudents(): void {
  if (courseId.value === null) return;
  void router.push({ name: 'course-students', params: { id: String(courseId.value) } });
}

async function reload(): Promise<void> {
  if (courseId.value === null) return;
  loading.value = true;
  errorMsg.value = null;
  try {
    const params: {
      source?: 'free' | 'purchase' | 'activation_code';
      startup_state?: 'never_opened' | 'opened_zero_progress';
      sort: 'idle_hours' | 'entitled_at' | 'source';
      order: 'asc' | 'desc';
      page: number;
      limit: number;
    } = {
      sort: filters.value.sort,
      order: filters.value.order,
      page: filters.value.page,
      limit: filters.value.limit,
    };
    if (filters.value.source) params.source = filters.value.source;
    if (filters.value.startup_state) params.startup_state = filters.value.startup_state;
    list.value = await listCourseStartQueue(courseId.value, params);
    const visible = new Set((list.value.items ?? []).map((row) => row.account_id));
    selectedIds.value = selectedIds.value.filter((id) => visible.has(id));
  } catch (err) {
    errorMsg.value = (err as Error).message || 'load_failed';
  } finally {
    loading.value = false;
  }
}

watch(
  () => [route.name, route.params.id] as const,
  () => {
    if (route.name !== 'course-start-queue' || courseId.value === null) return;
    filters.value.page = 1;
    void reload();
  },
);

function sourceLabel(src: CourseStartQueueItemDTO['source']): string {
  if (src === 'free') return '免费加入';
  if (src === 'activation_code') return '激活码兑换';
  return '付费取得';
}

function startupLabel(state: CourseStartQueueItemDTO['startup_state']): string {
  return state === 'opened_zero_progress' ? '打开过但仍为零进度' : '从未打开';
}

function blockedLabel(reason: CourseStartQueueItemDTO['reminder_blocked_reason']): string {
  if (reason === 'frequency') return '仍在冷却期内';
  if (reason === 'cap') return '已达触达次数上限';
  if (reason === 'below_threshold') return '尚未超过闲置阈值';
  if (reason === 'started') return '已产生有效进度';
  if (reason === 'completed') return '已完成课程';
  if (reason === 'no_active_entitlement') return '访问权已失效';
  if (reason === 'no_effective_lesson') return '课程没有可学习课节';
  if (reason === 'not_eligible') return '当前不符合触达条件';
  return '';
}

async function sendReminders(): Promise<void> {
  if (courseId.value === null || sending.value) return;
  if (eligibleSelected.value.length === 0) {
    errorMsg.value = blockedSelected.value.length
      ? '所选对象当前受频率或次数限制，不能发送。'
      : '请先选择符合触达条件的对象。';
    return;
  }
  try {
    await ElMessageBox.confirm(
      `将向 ${eligibleSelected.value.length} 名学员发送本课程的开始学习提醒。提醒不会改变访问权或学习事实。`,
      '发送启动提醒',
      { type: 'warning', confirmButtonText: '发送', cancelButtonText: '取消' },
    );
  } catch {
    return;
  }
  sending.value = true;
  errorMsg.value = null;
  try {
    const result = await sendCourseStartReminders(
      courseId.value,
      eligibleSelected.value.map((row) => row.account_id),
    );
    ElMessage.success(`已发送 ${result.sent_count} 条，未发送 ${result.blocked_count} 条`);
    selectedIds.value = [];
    await reload();
  } catch (err) {
    errorMsg.value = (err as Error).message || 'send_failed';
  } finally {
    sending.value = false;
  }
}

onMounted(() => {
  void reload();
});
</script>

<template>
  <main class="page">
    <header class="head">
      <div>
        <h1 class="display">课程 {{ courseId ?? '—' }} · 启动队列</h1>
        <p class="muted">
          闲置时长从访问权生效后起算，不表示学员拒绝学习。当前阈值
          {{ policy?.idle_threshold_hours ?? '—' }} 小时，触达频率
          {{ policy?.reminder_frequency_hours ?? '—' }} 小时，次数上限
          {{ policy?.reminder_cap ?? '—' }}。
        </p>
      </div>
      <el-button type="primary" link data-action="open-students" @click="openStudents">
        返回学员名单
      </el-button>
    </header>

    <el-form class="filters filter-form" inline @submit.prevent="((filters.page = 1), reload())">
      <el-form-item label="访问权来源">
        <el-select
          v-model="filters.source"
          class="filter-control"
          clearable
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
      <el-form-item label="启动状态">
        <el-select
          v-model="filters.startup_state"
          class="filter-control"
          clearable
          data-field="startup_state"
          placeholder="全部"
          :teleported="false"
        >
          <el-option label="全部" value="" />
          <el-option label="从未打开" value="never_opened" />
          <el-option label="打开过但仍为零进度" value="opened_zero_progress" />
        </el-select>
      </el-form-item>
      <el-form-item label="排序">
        <el-select
          v-model="filters.sort"
          class="filter-control"
          data-field="sort"
          :teleported="false"
        >
          <el-option label="闲置时长" value="idle_hours" />
          <el-option label="访问权生效时间" value="entitled_at" />
          <el-option label="访问权来源" value="source" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-select
          v-model="filters.order"
          class="filter-control"
          data-field="order"
          :teleported="false"
        >
          <el-option label="从长到短 / 新到旧" value="desc" />
          <el-option label="从短到长 / 旧到新" value="asc" />
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
      <el-form-item v-if="canRemind">
        <el-button
          type="primary"
          data-action="send-reminders"
          :disabled="eligibleSelected.length === 0 || sending"
          :loading="sending"
          @click="sendReminders"
        >
          发送启动提醒
        </el-button>
      </el-form-item>
    </el-form>

    <p v-if="blockedSelected.length" class="muted" data-role="blocked-reason">
      已选 {{ blockedSelected.length }} 人当前不能发送：{{
        blockedLabel(blockedSelected[0]?.reminder_blocked_reason ?? null)
      }}
    </p>
    <p v-if="errorMsg" class="error">{{ errorMsg }}</p>
    <p v-else-if="loading" class="muted">加载中…</p>

    <el-table
      v-else
      v-loading="loading"
      :data="list?.items ?? []"
      stripe
      class="data"
      @selection-change="
        (rows: CourseStartQueueItemDTO[]) => (selectedIds = rows.map((row) => row.account_id))
      "
    >
      <el-table-column
        v-if="canRemind"
        type="selection"
        width="48"
        :selectable="(row: CourseStartQueueItemDTO) => row.can_remind"
      />
      <el-table-column prop="login" label="账号" min-width="140" />
      <el-table-column prop="nickname" label="昵称" min-width="120" />
      <el-table-column label="来源" min-width="110">
        <template #default="{ row }">{{ sourceLabel(row.source) }}</template>
      </el-table-column>
      <el-table-column label="启动状态" min-width="160">
        <template #default="{ row }">{{ startupLabel(row.startup_state) }}</template>
      </el-table-column>
      <el-table-column label="闲置时长" min-width="110">
        <template #default="{ row }">{{ row.idle_hours }} 小时</template>
      </el-table-column>
      <el-table-column prop="entitled_at" label="访问权生效" min-width="170" />
      <el-table-column label="进度" min-width="80">
        <template #default="{ row }">{{ row.progress_percent }}%</template>
      </el-table-column>
      <el-table-column label="触达" min-width="160">
        <template #default="{ row }">
          <span v-if="row.can_remind">可发送（已发 {{ row.reminder_count }} 次）</span>
          <span v-else>{{ blockedLabel(row.reminder_blocked_reason) }}</span>
        </template>
      </el-table-column>
      <template #empty>
        <el-empty description="当前没有超过阈值且尚未启动的学员" :image-size="88" />
      </template>
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
  margin: 8px 0 0;
  font-size: 0.85rem;
}
.filters {
  display: flex;
  gap: 12px;
  align-items: end;
  flex-wrap: wrap;
}
.error {
  color: var(--el-color-danger);
}
</style>
