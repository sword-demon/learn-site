<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import type {
  LearningFactFunnelDTO,
  LearningFactFunnelSource,
  LearningFactFunnelWindowDays,
} from '@learn-site/contracts';
import { fetchLearningFactFunnel } from '@/api/learningFactFunnel';

defineOptions({ name: 'LearningFactFunnelView' });

const route = useRoute();
const courseId = computed(() => {
  const raw = route.params.id;
  if (raw === undefined || raw === null || Array.isArray(raw)) return null;
  const id = Number(raw);
  return Number.isFinite(id) && id > 0 ? id : null;
});

const windowDays = ref<LearningFactFunnelWindowDays>(30);
const source = ref<LearningFactFunnelSource>('all');
const report = ref<LearningFactFunnelDTO | null>(null);
const loading = ref(false);
const errorMsg = ref<string | null>(null);

const emptyFunnel = computed(() => (report.value?.stages[0]?.count ?? 0) === 0);

async function reload(): Promise<void> {
  if (courseId.value === null) return;
  loading.value = true;
  errorMsg.value = null;
  try {
    report.value = await fetchLearningFactFunnel(courseId.value, {
      window_days: windowDays.value,
      source: source.value,
    });
  } catch (err) {
    errorMsg.value = (err as Error).message || 'load_failed';
    report.value = null;
  } finally {
    loading.value = false;
  }
}

watch(
  [windowDays, source, courseId],
  () => {
    void reload();
  },
  { immediate: true },
);
</script>

<template>
  <main class="page">
    <header class="head">
      <h1 class="display">课程 {{ courseId ?? '—' }} · 学习事实漏斗</h1>
      <p class="muted">取得访问权之后是否开始并完成了学习</p>
    </header>

    <el-alert v-if="report" type="info" :closable="false" show-icon :title="report.disclaimer" />

    <el-form class="filters" inline>
      <el-form-item label="观察窗口">
        <el-radio-group v-model="windowDays">
          <el-radio-button :value="7">7 日</el-radio-button>
          <el-radio-button :value="30">30 日</el-radio-button>
          <el-radio-button :value="90">90 日</el-radio-button>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="访问权来源">
        <el-select v-model="source" class="filter-control" :teleported="false">
          <el-option label="全部来源" value="all" />
          <el-option label="免费加入" value="free" />
          <el-option label="支付成功" value="purchase" />
          <el-option label="激活码兑换" value="activation_code" />
        </el-select>
      </el-form-item>
    </el-form>

    <p v-if="errorMsg" class="error">
      {{ errorMsg }}
      <el-button data-action="retry-funnel" type="primary" link @click="reload">重试</el-button>
    </p>
    <p v-else-if="loading" class="muted">加载中…</p>

    <template v-else-if="report">
      <p class="muted">
        每个学员从课程访问权生效时刻起算, 到生效时刻加 {{ report.window_days }} 个自然日结束.
        访问权生效之后才计首次打开课节; 打开后产生完成课节才计有效进度; 学习记录完成才计完成课程.
        数据截止 {{ report.generated_at }} · 非实时
      </p>
      <el-empty v-if="emptyFunnel" description="窗口内没有课程访问权生效" />
      <el-row v-else :gutter="12">
        <el-col v-for="stage in report.stages" :key="stage.id" :span="6">
          <el-card>
            <el-statistic :title="stage.label" :value="stage.count" />
            <p class="muted">
              占进入队列
              {{
                stage.of_cohort_rate == null ? '—' : `${Math.round(stage.of_cohort_rate * 100)}%`
              }}
            </p>
            <p class="muted">
              占上一阶段
              {{
                stage.of_previous_rate == null
                  ? '—'
                  : `${Math.round(stage.of_previous_rate * 100)}%`
              }}
            </p>
          </el-card>
        </el-col>
      </el-row>

      <el-card class="block">
        <template #header>尚未开始</template>
        <p>{{ report.pending.in_window_label }}：{{ report.pending.in_window }}</p>
        <p>{{ report.pending.window_elapsed_label }}：{{ report.pending.window_elapsed }}</p>
        <p v-if="report.no_effective_lesson" class="muted">本课没有有效课节，后三阶段为 0。</p>
      </el-card>

      <el-row :gutter="12" class="block">
        <el-col :span="8">
          <el-card>
            <template #header>试看对照</template>
            <el-statistic title="登录学员" :value="report.trial.learners" />
            <p class="muted">{{ report.trial.note }}</p>
          </el-card>
        </el-col>
        <el-col :span="8">
          <el-card>
            <template #header>订单对照</template>
            <el-statistic title="支付成功" :value="report.orders.succeeded" />
            <p class="muted">{{ report.orders.note }}</p>
          </el-card>
        </el-col>
        <el-col :span="8">
          <el-card>
            <template #header>发布触达对照</template>
            <el-statistic title="触达人数" :value="report.publish_reach.recipient_count" />
            <p class="muted">已有访问权 {{ report.publish_reach.entitled_count }} 人</p>
            <p class="muted">{{ report.publish_reach.note }}</p>
          </el-card>
        </el-col>
      </el-row>
    </template>
  </main>
</template>

<style scoped>
.page {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.head {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.display {
  margin: 0;
  font-size: 20px;
}
.muted {
  margin: 8px 0 0;
  color: var(--el-text-color-secondary);
}
.error {
  color: var(--el-color-danger);
}
.filter-control {
  width: 180px;
}
.block {
  margin-top: 12px;
}
</style>
