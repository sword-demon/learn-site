<template>
  <section class="page dashboard">
    <header class="bar">
      <div>
        <h2>工作台</h2>
        <p class="scope">{{ scopeLabel }}</p>
      </div>
      <div class="toolbar">
        <el-select v-model="rangeDays" class="range-select" aria-label="统计范围">
          <el-option :value="7" label="最近 7 天" />
          <el-option :value="30" label="最近 30 天" />
          <el-option :value="90" label="最近 90 天" />
        </el-select>
        <el-button :loading="loading" @click="reload">刷新</el-button>
      </div>
    </header>

    <el-alert v-if="errorMessage" :title="errorMessage" type="error" show-icon :closable="false" />

    <div v-loading="loading" class="grid">
      <article v-for="tile in tiles" :key="tile.key" class="card">
        <p class="card-label">{{ tile.label }}</p>
        <p class="card-value">{{ tile.value }}</p>
        <el-button link type="primary" @click="go(tile)">打开</el-button>
      </article>
    </div>

    <section v-if="summary && summary.order_trend !== null" class="panel chart-panel">
      <div class="panel-head">
        <div>
          <h3>订单与支付趋势</h3>
          <p>订单按创建日统计，支付按成功日统计</p>
        </div>
      </div>
      <div ref="orderChartEl" class="chart" aria-label="订单与支付趋势图" />
    </section>

    <section v-if="summary" class="panel chart-panel">
      <div class="panel-head">
        <div>
          <h3>待办与课程库存</h3>
          <p>点击条目进入对应管理列表</p>
        </div>
      </div>
      <div
        v-if="hasOperationsData"
        ref="operationsChartEl"
        class="chart operations-chart"
        aria-label="待办与课程库存图"
      />
      <p v-else class="empty">当前权限范围内暂无可展示的运营模块。</p>
    </section>

    <section v-if="recentOrders !== null" class="panel">
      <h3>最近订单</h3>
      <p v-if="recentOrders.length === 0" class="empty">暂无订单记录。</p>
      <el-table v-else :data="recentOrders" stripe size="small">
        <el-table-column prop="id" label="订单号" width="100" />
        <el-table-column prop="course_title" label="课程" min-width="180" />
        <el-table-column label="金额" width="120">
          <template #default="{ row }">{{ formatAmount(row.paid_amount) }}</template>
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
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage } from 'element-plus';
import * as echarts from 'echarts';
import type { ECharts, EChartsOption } from 'echarts';
import type { DashboardSummaryDTO } from '@learn-site/contracts';
import { fetchDashboard } from '@/api/dashboard';

defineOptions({ name: 'DashboardView' });

const router = useRouter();
const route = useRoute();
const summary = ref<DashboardSummaryDTO | null>(null);
const loading = ref(false);
const errorMessage = ref('');
const rangeDays = ref<7 | 30 | 90>(30);
const orderChartEl = ref<HTMLElement | null>(null);
const operationsChartEl = ref<HTMLElement | null>(null);
let orderChart: ECharts | null = null;
let operationsChart: ECharts | null = null;
let refreshTimer: ReturnType<typeof setTimeout> | null = null;

interface DashboardTile {
  key: string;
  label: string;
  value: string;
  route: string;
  query?: Record<string, string>;
}

const scopeLabel = computed(() =>
  summary.value?.scope === 'all' ? '全站数据' : '权限与部门范围内数据',
);
const recentOrders = computed(() => summary.value?.recent_orders ?? null);
const tiles = computed<DashboardTile[]>(() => {
  const data = summary.value;
  if (!data) return [];
  const counts = data.counts;
  const items: DashboardTile[] = [];
  const add = (
    key: string,
    label: string,
    value: number | null,
    routeName: string,
    query?: Record<string, string>,
  ): void => {
    if (value === null) return;
    items.push({ key, label, value: String(value), route: routeName, ...(query ? { query } : {}) });
  };
  add('questions', '待回答问题', counts.unanswered_questions, 'qa', { status: 'pending' });
  add('reviews', '待处理评价', counts.pending_reviews, 'reviews');
  add('maps', '异常学习地图', counts.abnormal_learning_maps, 'maps');
  add('unpublished-courses', '待发布课程', counts.unpublished_courses, 'courses');
  add('pending-orders', '待支付订单', counts.pending_orders, 'orders', { status: 'pending' });
  add('succeeded-orders', '支付成功订单', counts.succeeded_orders, 'orders', {
    status: 'succeeded',
  });
  if (counts.paid_amount !== null) {
    items.push({
      key: 'paid-amount',
      label: '支付金额',
      value: formatAmount(counts.paid_amount),
      route: 'orders',
      query: { status: 'succeeded' },
    });
  }
  add('published-courses', '已发布课程', counts.published_courses, 'courses', {
    status: 'published',
  });
  return items;
});

const hasOperationsData = computed(() => {
  const content = summary.value?.operations_content;
  if (!content) return false;
  return (
    Object.values(content.operations).some((value) => value !== null) ||
    Object.values(content.course_inventory).some((value) => value !== null)
  );
});

async function reload(): Promise<void> {
  loading.value = true;
  errorMessage.value = '';
  try {
    summary.value = await fetchDashboard(rangeDays.value);
    await nextTick();
    renderCharts();
  } catch (err: unknown) {
    const e = err as { response?: { data?: { error?: { message?: string } } } };
    errorMessage.value = e.response?.data?.error?.message ?? '工作台暂时读不到，请稍后再试。';
    summary.value = null;
    disposeCharts();
  } finally {
    loading.value = false;
  }
}

function go(tile: DashboardTile): void {
  const target =
    tile.query === undefined ? { name: tile.route } : { name: tile.route, query: tile.query };
  void router.push(target).catch(() => ElMessage.warning('无法打开对应管理页面。'));
}

function renderCharts(): void {
  renderOrderChart();
  renderOperationsChart();
}

function resizeCharts(): void {
  orderChart?.resize();
  operationsChart?.resize();
}

function renderOrderChart(): void {
  const data = summary.value?.order_trend;
  if (!orderChartEl.value || data === null || data === undefined) {
    orderChart?.dispose();
    orderChart = null;
    return;
  }
  orderChart?.dispose();
  orderChart = echarts.init(orderChartEl.value);
  const dates = data.map((point) => point.date);
  const option: EChartsOption = {
    tooltip: { trigger: 'axis' },
    legend: {
      data: ['创建订单数', '支付成功订单数', '支付金额'],
      top: 4,
      left: 0,
      right: 0,
      itemGap: 16,
    },
    grid: { left: 48, right: 64, top: 56, bottom: 54, containLabel: true },
    xAxis: {
      type: 'category',
      data: dates,
      axisLabel: {
        hideOverlap: true,
        margin: 12,
        formatter: (value: string) => (value.length > 5 ? value.slice(5) : value),
      },
    },
    yAxis: [
      { type: 'value', minInterval: 1 },
      { type: 'value', min: 0, axisLabel: { formatter: '¥ {value}' } },
    ],
    series: [
      {
        name: '创建订单数',
        type: 'line',
        smooth: true,
        data: data.map((point) => point.created_orders),
      },
      {
        name: '支付成功订单数',
        type: 'line',
        smooth: true,
        data: data.map((point) => point.succeeded_orders),
      },
      {
        name: '支付金额',
        type: 'bar',
        yAxisIndex: 1,
        data: data.map((point) => point.paid_amount),
      },
    ],
  };
  orderChart.setOption(option);
  orderChart.on('click', (params) => {
    const date = typeof params.name === 'string' ? params.name : dates[params.dataIndex];
    if (!date) return;
    void router.push({ name: 'orders', query: { from: date, to: date } });
  });
}

function renderOperationsChart(): void {
  const content = summary.value?.operations_content;
  if (!operationsChartEl.value || !content || !hasOperationsData.value) {
    operationsChart?.dispose();
    operationsChart = null;
    return;
  }
  operationsChart?.dispose();
  operationsChart = echarts.init(operationsChartEl.value);
  const operationRows: Array<{
    label: string;
    count: number | null;
    route: string;
    query?: Record<string, string>;
  }> = [
    {
      label: '待回答问题',
      count: content.operations.unanswered_questions,
      route: 'qa',
      query: { status: 'pending' },
    },
    { label: '待处理评价', count: content.operations.pending_reviews, route: 'reviews' },
    { label: '异常学习地图', count: content.operations.abnormal_learning_maps, route: 'maps' },
    { label: '待发布课程', count: content.operations.unpublished_courses, route: 'courses' },
  ];
  const operations = operationRows.filter((item) => item.count !== null) as Array<{
    label: string;
    count: number;
    route: string;
    query?: Record<string, string>;
  }>;
  const inventoryRows: Array<{
    label: string;
    count: number | null;
    query: Record<string, string>;
  }> = [
    { label: '草稿', count: content.course_inventory.draft, query: { status: 'draft' } },
    { label: '已发布', count: content.course_inventory.published, query: { status: 'published' } },
    {
      label: '已下架',
      count: content.course_inventory.unpublished,
      query: { status: 'unpublished' },
    },
  ];
  const inventory = inventoryRows.filter((item) => item.count !== null) as Array<{
    label: string;
    count: number;
    query: Record<string, string>;
  }>;
  operationsChart.setOption({
    tooltip: { trigger: 'axis' },
    grid: [
      { left: 48, right: 24, top: 40, height: '31%', containLabel: true },
      { left: 48, right: 24, top: '57%', height: '31%', containLabel: true },
    ],
    xAxis: [
      {
        type: 'category',
        gridIndex: 0,
        data: operations.map((item) => item.label),
        axisLabel: { interval: 0, hideOverlap: true, margin: 12 },
      },
      {
        type: 'category',
        gridIndex: 1,
        data: inventory.map((item) => item.label),
        axisLabel: { interval: 0, hideOverlap: true, margin: 12 },
      },
    ],
    yAxis: [
      { type: 'value', gridIndex: 0, minInterval: 1 },
      { type: 'value', gridIndex: 1, minInterval: 1 },
    ],
    series: [
      {
        name: '运营待办',
        type: 'bar',
        xAxisIndex: 0,
        yAxisIndex: 0,
        data: operations.map((item) => item.count),
        barMaxWidth: 36,
      },
      {
        name: '课程库存',
        type: 'bar',
        xAxisIndex: 1,
        yAxisIndex: 1,
        data: inventory.map((item) => item.count),
        barMaxWidth: 36,
      },
    ],
  });
  operationsChart.on('click', (params) => {
    const index = params.dataIndex;
    if (params.seriesIndex === 0 && operations[index]) {
      const item = operations[index];
      go({
        key: item.label,
        label: item.label,
        value: String(item.count),
        route: item.route,
        ...(item.query ? { query: item.query } : {}),
      });
    } else if (params.seriesIndex === 1 && inventory[index]) {
      const item = inventory[index];
      go({
        key: item.label,
        label: item.label,
        value: String(item.count),
        route: 'courses',
        query: item.query,
      });
    }
  });
}

function disposeCharts(): void {
  orderChart?.dispose();
  operationsChart?.dispose();
  orderChart = null;
  operationsChart = null;
}

function scheduleRefresh(): void {
  if (refreshTimer !== null) clearTimeout(refreshTimer);
  if (document.visibilityState === 'hidden') return;
  refreshTimer = setTimeout(() => {
    void reload().finally(scheduleRefresh);
  }, 300_000);
}

function onVisibilityChange(): void {
  if (document.visibilityState === 'visible') {
    void reload();
    scheduleRefresh();
  } else if (refreshTimer !== null) {
    clearTimeout(refreshTimer);
    refreshTimer = null;
  }
}

function formatAmount(value: number): string {
  return `¥ ${value.toFixed(2)}`;
}

function orderStatusLabel(status: string): string {
  return (
    (
      {
        pending: '待支付',
        succeeded: '已支付',
        failed: '失败',
        cancelled: '已取消',
        unknown: '未知',
      } as Record<string, string>
    )[status] ?? status
  );
}

function orderStatusType(status: string): 'info' | 'success' | 'warning' | 'danger' {
  if (status === 'succeeded') return 'success';
  if (status === 'failed' || status === 'unknown') return 'danger';
  if (status === 'cancelled') return 'info';
  return 'warning';
}

watch(rangeDays, () => void reload());
onMounted(() => {
  const denied = route.query.denied;
  if (denied === 'forbidden') ElMessage.warning('您没有访问该页面的权限。');
  if (denied === 'no_permissions') ElMessage.warning('权限信息尚未加载，正在重试。');
  if (denied !== undefined) void router.replace({ path: '/', query: {} });
  document.addEventListener('visibilitychange', onVisibilityChange);
  window.addEventListener('resize', resizeCharts);
  void reload().finally(scheduleRefresh);
});
onBeforeUnmount(() => {
  document.removeEventListener('visibilitychange', onVisibilityChange);
  window.removeEventListener('resize', resizeCharts);
  if (refreshTimer !== null) clearTimeout(refreshTimer);
  disposeCharts();
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
.toolbar {
  display: flex;
  align-items: center;
  gap: 10px;
}
.range-select {
  width: 132px;
}
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(190px, 100%), 1fr));
  gap: 16px;
  min-width: 0;
}
.card,
.panel {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
}
.card {
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
  font-variant-numeric: tabular-nums;
}
.panel {
  padding: 18px;
  min-width: 0;
  overflow-x: auto;
}
.panel h3 {
  margin: 0;
}
.panel-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
}
.panel-head p {
  color: #64748b;
  margin: 4px 0 0;
  font-size: 12px;
}
.chart {
  width: 100%;
  height: 340px;
  min-width: 520px;
}
.operations-chart {
  height: 390px;
}
.empty {
  color: #94a3b8;
  margin: 12px 0 0;
}
@media (max-width: 620px) {
  .bar {
    align-items: stretch;
    flex-direction: column;
  }
  .toolbar {
    justify-content: space-between;
  }
  .chart {
    min-width: 440px;
  }
}
</style>
