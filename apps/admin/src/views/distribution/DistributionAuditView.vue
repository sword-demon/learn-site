<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import type { DistributionAuditAction, DistributionAuditDTO } from '@learn-site/contracts';
import { fetchAudit } from '@/api/distribution';
import AdminListPager from '@/components/AdminListPager.vue';
import './distribution.css';

defineOptions({ name: 'DistributionAuditView' });

const ACTION_OPTIONS: Array<{ value: '' | DistributionAuditAction; label: string }> = [
  { value: '', label: '全部动作' },
  { value: 'config.update', label: '更新配置' },
  { value: 'course.override.update', label: '课程覆盖' },
  { value: 'commission.settle', label: '结算佣金' },
  { value: 'commission.void_admin', label: '人工撤销' },
  { value: 'commission.void_refund', label: '退款撤销' },
];

const SUBJECT_LABEL: Record<DistributionAuditDTO['subject_type'], string> = {
  config: '配置',
  course_override: '课程覆盖',
  commission: '佣金',
  share_entry: '分享入口',
};

const items = ref<DistributionAuditDTO[]>([]);
const total = ref(0);
const loading = ref(false);
const errorMsg = ref<string | null>(null);
const detail = ref<DistributionAuditDTO | null>(null);
const filters = ref({
  action: '' as '' | DistributionAuditAction,
  actor_type: '' as '' | 'admin' | 'system',
  page: 1,
  limit: 20,
});

const detailOpen = computed({
  get: () => detail.value !== null,
  set: (open: boolean) => {
    if (!open) detail.value = null;
  },
});

function actionLabel(action: string): string {
  return ACTION_OPTIONS.find((item) => item.value === action)?.label ?? action;
}

function actorLabel(row: DistributionAuditDTO): string {
  if (row.actor_type === 'system') return '系统';
  return row.actor_id !== null ? `管理员 #${row.actor_id}` : '管理员';
}

function subjectLabel(row: DistributionAuditDTO): string {
  const name = SUBJECT_LABEL[row.subject_type] ?? row.subject_type;
  return row.subject_id !== null ? `${name} #${row.subject_id}` : name;
}

function formatJson(value: unknown): string {
  if (value === null || value === undefined) return '—';
  try {
    return JSON.stringify(value, null, 2);
  } catch {
    return String(value);
  }
}

async function reload(): Promise<void> {
  loading.value = true;
  errorMsg.value = null;
  try {
    const data = await fetchAudit({
      page: filters.value.page,
      limit: filters.value.limit,
      ...(filters.value.action ? { action: filters.value.action } : {}),
      ...(filters.value.actor_type ? { actor_type: filters.value.actor_type } : {}),
    });
    items.value = data.items;
    total.value = data.total;
  } catch (error) {
    errorMsg.value = (error as Error).message || 'load_failed';
    items.value = [];
    total.value = 0;
  } finally {
    loading.value = false;
  }
}

function applyFilters(): void {
  filters.value.page = 1;
  void reload();
}

function resetFilters(): void {
  filters.value.action = '';
  filters.value.actor_type = '';
  filters.value.page = 1;
  void reload();
}

onMounted(() => void reload());
</script>

<template>
  <main class="dist-page">
    <header class="dist-head">
      <div>
        <span class="dist-kicker">分销运营 / 审计</span>
        <h1 class="dist-display">分销审计</h1>
        <p class="dist-subtitle">
          配置变更、课程覆盖和佣金撤销都落在同一本账上, 可按动作和操作者过滤.
        </p>
      </div>
      <div class="dist-metric">
        <span>记录</span>
        <strong>{{ total }}</strong>
        <span>条</span>
      </div>
    </header>

    <el-alert v-if="errorMsg" :title="errorMsg" type="error" :closable="false" show-icon />

    <el-card class="dist-panel" shadow="never">
      <el-form class="dist-filter" inline aria-label="分销审计筛选" @submit.prevent="applyFilters">
        <el-form-item label="动作">
          <el-select
            v-model="filters.action"
            class="dist-control-wide"
            clearable
            placeholder="全部动作"
            data-field="action"
          >
            <el-option
              v-for="opt in ACTION_OPTIONS"
              :key="opt.value || 'all'"
              :label="opt.label"
              :value="opt.value"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="操作者">
          <el-select
            v-model="filters.actor_type"
            class="dist-control"
            clearable
            placeholder="全部"
            data-field="actor_type"
          >
            <el-option label="全部" value="" />
            <el-option label="管理员" value="admin" />
            <el-option label="系统" value="system" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" native-type="submit" :loading="loading">查询</el-button>
          <el-button @click="resetFilters">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card class="dist-panel" shadow="never">
      <el-table v-loading="loading" :data="items" stripe empty-text="暂无审计记录">
        <el-table-column prop="created_at" label="时间" min-width="170" />
        <el-table-column label="动作" min-width="140">
          <template #default="{ row }">
            <div class="action-cell">
              <strong>{{ actionLabel(row.action) }}</strong>
              <span class="code">{{ row.action }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="操作者" min-width="120">
          <template #default="{ row }">
            <el-tag :type="row.actor_type === 'system' ? 'info' : 'warning'" size="small">
              {{ actorLabel(row) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="对象" min-width="140">
          <template #default="{ row }">{{ subjectLabel(row) }}</template>
        </el-table-column>
        <el-table-column label="原因" min-width="180">
          <template #default="{ row }">{{ row.reason || '—' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="100" fixed="right">
          <template #default="{ row }">
            <el-button text type="primary" data-action="detail" @click="detail = row"
              >详情</el-button
            >
          </template>
        </el-table-column>
        <template #empty><el-empty description="还没有分销审计记录" :image-size="88" /></template>
      </el-table>
      <AdminListPager
        v-model:page="filters.page"
        v-model:page-size="filters.limit"
        :total="total"
        @change="reload"
      />
    </el-card>

    <el-drawer
      v-model="detailOpen"
      title="审计详情"
      size="520px"
      destroy-on-close
      :append-to-body="false"
    >
      <div v-if="detail" class="audit-detail">
        <el-descriptions :column="1" border>
          <el-descriptions-item label="动作">{{ actionLabel(detail.action) }}</el-descriptions-item>
          <el-descriptions-item label="操作者">{{ actorLabel(detail) }}</el-descriptions-item>
          <el-descriptions-item label="对象">{{ subjectLabel(detail) }}</el-descriptions-item>
          <el-descriptions-item label="原因">{{ detail.reason || '—' }}</el-descriptions-item>
          <el-descriptions-item label="时间">{{ detail.created_at }}</el-descriptions-item>
        </el-descriptions>
        <section>
          <h3>变更前</h3>
          <pre class="dist-json">{{ formatJson(detail.before_json) }}</pre>
        </section>
        <section>
          <h3>变更后</h3>
          <pre class="dist-json">{{ formatJson(detail.after_json) }}</pre>
        </section>
      </div>
    </el-drawer>
  </main>
</template>

<style scoped>
.action-cell {
  display: grid;
  gap: 2px;
}

.action-cell strong {
  color: #102a43;
  font-size: 13px;
}

.action-cell .code {
  color: #829ab1;
  font-size: 11px;
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}

.audit-detail {
  display: grid;
  gap: 16px;
}

.audit-detail h3 {
  margin: 0 0 8px;
  color: #486581;
  font-size: 13px;
  font-weight: 600;
}
</style>
