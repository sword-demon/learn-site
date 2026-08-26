<template>
  <section class="page dashboard">
    <header class="bar">
      <div>
        <h2>工作台</h2>
        <p class="scope">{{ scopeLabel }}</p>
      </div>
      <el-button :loading="loading" @click="reload"> 刷新 </el-button>
    </header>

    <el-alert v-if="errorMessage" :title="errorMessage" type="error" show-icon :closable="false" />

    <div v-loading="loading" class="grid">
      <article v-for="tile in tiles" :key="tile.key" class="card">
        <p class="card-label">{{ tile.label }}</p>
        <p class="card-value">{{ tile.count }}</p>
        <el-button link type="primary" @click="go(tile)"> 打开 </el-button>
      </article>
    </div>

    <section v-if="recentOrders !== null" class="panel">
      <h3>最近订单</h3>
      <p v-if="recentOrders.length === 0" class="empty">暂无订单记录。</p>
      <el-table v-else :data="recentOrders" stripe size="small">
        <el-table-column prop="id" label="订单号" width="100" />
        <el-table-column prop="course_title" label="课程" min-width="180" />
        <el-table-column label="金额" width="120">
          <template #default="{ row }">
            {{ formatAmount(row.paid_amount) }}
          </template>
        </el-table-column>
        <el-table-column label="状态" width="120">
          <template #default="{ row }">
            <el-tag :type="orderStatusType(row.status)" effect="light" size="small">
              {{ orderStatusLabel(row.status) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="创建时间" min-width="180" />
      </el-table>
    </section>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';
import type { DashboardSummaryDTO } from '@learn-site/contracts';
import { fetchDashboard } from '@/api/dashboard';

defineOptions({ name: 'DashboardView' });

const router = useRouter();
const route = useRoute();

const summary = ref<DashboardSummaryDTO | null>(null);
const loading = ref(false);
const errorMessage = ref('');

interface DashboardTile {
  key: string;
  label: string;
  count: number;
  route: string;
  query?: Record<string, string>;
}

const tiles = computed<DashboardTile[]>(() => {
  const data = summary.value;
  if (!data) return [];
  const items: DashboardTile[] = [];
  const add = (
    key: string,
    label: string,
    count: number | null,
    routeName: string,
    query?: Record<string, string>,
  ): void => {
    if (count === null) return;
    const item: DashboardTile = { key, label, count, route: routeName };
    if (query !== undefined) item.query = query;
    items.push(item);
  };
  add('questions', '待回答问题', data.counts.unanswered_questions, 'qa', { status: 'pending' });
  add('reviews', '待处理评价', data.counts.pending_reviews, 'reviews');
  add('maps', '异常学习地图', data.counts.abnormal_learning_maps, 'maps');
  add('courses', '未发布课程', data.counts.unpublished_courses, 'courses');
  add('orders', '最近订单', data.recent_orders?.length ?? null, 'orders');
  return items;
});
const recentOrders = computed(() => summary.value?.recent_orders ?? null);
const scopeLabel = computed(() =>
  summary.value?.scope === 'all' ? '全站数据' : '权限与部门范围内数据',
);

async function reload(): Promise<void> {
  loading.value = true;
  errorMessage.value = '';
  try {
    summary.value = await fetchDashboard();
  } catch (err: unknown) {
    const e = err as { response?: { data?: { error?: { message?: string } } } };
    errorMessage.value = e.response?.data?.error?.message ?? '工作台暂时读不到，请稍后再试。';
    summary.value = null;
  } finally {
    loading.value = false;
  }
}

function go(tile: DashboardTile): void {
  const target =
    tile.query === undefined ? { name: tile.route } : { name: tile.route, query: tile.query };
  void router.push(target).catch(() => {
    ElMessage.warning('无法打开对应管理页面。');
  });
}

function formatAmount(v: number): string {
  return `¥ ${v.toFixed(2)}`;
}

function orderStatusLabel(s: string): string {
  switch (s) {
    case 'pending':
      return '待支付';
    case 'succeeded':
      return '已支付';
    case 'failed':
      return '失败';
    case 'cancelled':
      return '已取消';
    case 'unknown':
      return '未知';
    default:
      return s;
  }
}

function orderStatusType(s: string): 'info' | 'success' | 'warning' | 'danger' {
  switch (s) {
    case 'succeeded':
      return 'success';
    case 'failed':
    case 'unknown':
      return 'danger';
    case 'cancelled':
      return 'info';
    case 'pending':
    default:
      return 'warning';
  }
}

onMounted(() => {
  // US13 / T060 — the router guard redirects unauthorized URL hits back to
  // "/" with a `denied` query so the user understands the bounce.
  const denied = route.query.denied;
  if (denied === 'forbidden') {
    ElMessage.warning('您没有访问该页面的权限。');
  } else if (denied === 'no_permissions') {
    ElMessage.warning('权限信息尚未加载，正在重试。');
  }
  if (denied !== undefined) {
    // Strip the query so a hard refresh doesn't re-toast.
    void router.replace({ path: '/', query: {} });
  }
  void reload();
});
</script>

<style scoped>
.dashboard {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 20px;
  min-width: 0;
}
.bar {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}
.bar h2 {
  margin: 0;
}
.scope {
  color: #64748b;
  margin: 4px 0 0;
  font-size: 13px;
}
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(220px, 100%), 1fr));
  gap: 16px;
  min-width: 0;
}
.card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 18px;
  display: grid;
  gap: 6px;
}
.card-label {
  margin: 0;
  font-size: 13px;
  color: #64748b;
}
.card-value {
  margin: 4px 0 6px;
  font-size: 28px;
  font-weight: 600;
  color: #0f172a;
}
.panel {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 18px;
  min-width: 0;
  overflow-x: auto;
}
.panel h3 {
  margin: 0 0 12px;
}
.empty {
  color: #94a3b8;
  margin: 0;
}
</style>
