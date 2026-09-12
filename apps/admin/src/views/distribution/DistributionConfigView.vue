<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ElMessage } from 'element-plus';
import type { DistributionConfigDTO, DistributionConfigUpdateInput } from '@learn-site/contracts';
import { fetchDistributionConfig, saveDistributionConfig } from '@/api/distribution';
import './distribution.css';

defineOptions({ name: 'DistributionConfigView' });

const BASE_OPTIONS = [
  { value: 'order_paid', label: '实付金额' },
  { value: 'list_price', label: '划线价' },
  { value: 'sale_price', label: '售价' },
] as const;

const loading = ref(false);
const submitting = ref(false);
const errorMsg = ref<string | null>(null);
const form = ref<DistributionConfigUpdateInput | null>(null);
const pct = ref({ level1: 0, level2: 0, level3: 0 });
const capsYuan = ref({
  per_order: 0,
  per_learner_course: 0,
  per_learner_total: null as number | null,
});
const hasTotalCap = ref(false);

const levelCapIllegal = computed(() => {
  const cap = form.value?.level_cap ?? 0;
  return cap < 1 || cap > 3;
});

function toPct(ratio: number): number {
  return Math.round(ratio * 10000) / 100;
}

function toRatio(percent: number): number {
  return Math.round(percent * 100) / 10000;
}

function yuanToCents(yuan: number): number {
  return Math.max(0, Math.round(yuan * 100));
}

function hydrate(cfg: DistributionConfigDTO): void {
  const { updated_at: _updatedAt, updated_by: _updatedBy, ...rest } = cfg;
  form.value = rest;
  pct.value = {
    level1: toPct(cfg.level1_pct),
    level2: toPct(cfg.level2_pct),
    level3: toPct(cfg.level3_pct),
  };
  capsYuan.value = {
    per_order: cfg.per_order_cap_cents / 100,
    per_learner_course: cfg.per_learner_course_cap_cents / 100,
    per_learner_total:
      cfg.per_learner_total_cap_cents === null ? null : cfg.per_learner_total_cap_cents / 100,
  };
  hasTotalCap.value = cfg.per_learner_total_cap_cents !== null;
}

function toPayload(): DistributionConfigUpdateInput | null {
  if (!form.value) return null;
  return {
    ...form.value,
    level1_pct: toRatio(pct.value.level1),
    level2_pct: toRatio(pct.value.level2),
    level3_pct: toRatio(pct.value.level3),
    per_order_cap_cents: yuanToCents(capsYuan.value.per_order),
    per_learner_course_cap_cents: yuanToCents(capsYuan.value.per_learner_course),
    per_learner_total_cap_cents: hasTotalCap.value
      ? yuanToCents(capsYuan.value.per_learner_total ?? 0)
      : null,
  };
}

async function reload(): Promise<void> {
  loading.value = true;
  errorMsg.value = null;
  try {
    hydrate(await fetchDistributionConfig());
  } catch (error) {
    errorMsg.value = (error as Error).message || 'load_failed';
  } finally {
    loading.value = false;
  }
}

async function save(): Promise<void> {
  const payload = toPayload();
  if (!payload) return;
  if (payload.level_cap > 3 || payload.level_cap < 1) {
    errorMsg.value = '合规硬约束';
    return;
  }
  submitting.value = true;
  errorMsg.value = null;
  try {
    hydrate(await saveDistributionConfig(payload));
    ElMessage.success('已保存');
  } catch (error) {
    errorMsg.value = (error as Error).message || '合规硬约束';
  } finally {
    submitting.value = false;
  }
}

onMounted(() => void reload());
</script>

<template>
  <main class="dist-page config-page">
    <header class="dist-head">
      <div>
        <span class="dist-kicker">分销运营 / 配置</span>
        <h1 class="dist-display">分销配置</h1>
        <p class="dist-subtitle">级别上限不超过三级, 保存前会走合规硬约束.</p>
      </div>
    </header>

    <el-alert v-if="errorMsg" :title="errorMsg" type="error" :closable="false" show-icon />
    <el-skeleton v-else-if="loading || !form" :rows="8" animated />

    <el-form v-else :model="form" class="config-form" label-position="top" @submit.prevent="save">
      <el-card class="dist-panel" shadow="never">
        <template #header>
          <strong>总开关</strong>
        </template>
        <div class="switch-row">
          <el-form-item label="开启分销">
            <el-switch v-model="form.enabled" />
          </el-form-item>
          <el-form-item label="允许学员查看明细">
            <el-switch v-model="form.learner_can_view_detail" />
          </el-form-item>
        </div>
      </el-card>

      <el-card class="dist-panel" shadow="never">
        <template #header>
          <strong>分成层级</strong>
        </template>
        <el-form-item label="级别上限">
          <el-radio-group v-model="form.level_cap">
            <el-radio-button :value="1">一级</el-radio-button>
            <el-radio-button :value="2">二级</el-radio-button>
            <el-radio-button :value="3">三级</el-radio-button>
          </el-radio-group>
        </el-form-item>
        <el-alert title="合规硬约束" type="error" :closable="false" show-icon />
        <p v-if="levelCapIllegal" class="dist-muted">级别上限只能是 1 到 3.</p>
        <div class="pct-grid">
          <el-form-item label="一级比例">
            <el-input-number v-model="pct.level1" :min="0" :max="100" :step="0.1" :precision="2" />
            <span class="unit">%</span>
          </el-form-item>
          <el-form-item label="二级比例">
            <el-input-number v-model="pct.level2" :min="0" :max="100" :step="0.1" :precision="2" />
            <span class="unit">%</span>
          </el-form-item>
          <el-form-item label="三级比例">
            <el-input-number v-model="pct.level3" :min="0" :max="100" :step="0.1" :precision="2" />
            <span class="unit">%</span>
          </el-form-item>
        </div>
      </el-card>

      <el-card class="dist-panel" shadow="never">
        <template #header>
          <strong>金额封顶</strong>
        </template>
        <div class="pct-grid">
          <el-form-item label="单笔封顶">
            <el-input-number v-model="capsYuan.per_order" :min="0" :step="1" :precision="2" />
            <span class="unit">元</span>
          </el-form-item>
          <el-form-item label="单学员单课封顶">
            <el-input-number
              v-model="capsYuan.per_learner_course"
              :min="0"
              :step="1"
              :precision="2"
            />
            <span class="unit">元</span>
          </el-form-item>
          <el-form-item label="单学员累计封顶">
            <el-switch v-model="hasTotalCap" active-text="限制" inactive-text="不限" />
            <el-input-number
              v-if="hasTotalCap"
              v-model="capsYuan.per_learner_total"
              :min="0"
              :step="1"
              :precision="2"
            />
            <span v-if="hasTotalCap" class="unit">元</span>
          </el-form-item>
        </div>
      </el-card>

      <el-card class="dist-panel" shadow="never">
        <template #header>
          <strong>结算规则</strong>
        </template>
        <el-form-item label="分成基数">
          <el-select v-model="form.base" class="dist-control-wide">
            <el-option
              v-for="opt in BASE_OPTIONS"
              :key="opt.value"
              :label="opt.label"
              :value="opt.value"
            />
          </el-select>
        </el-form-item>
        <dl class="locked-rules">
          <div>
            <dt>结算时点</dt>
            <dd>退款窗口关闭后结算</dd>
          </div>
          <div>
            <dt>退款规则</dt>
            <dd>整单撤销</dd>
          </div>
          <div>
            <dt>打款形式</dt>
            <dd>仅记现金账, 不进余额</dd>
          </div>
        </dl>
      </el-card>

      <div class="actions">
        <el-button type="primary" :loading="submitting" native-type="submit">保存</el-button>
      </div>
    </el-form>
  </main>
</template>

<style scoped>
.config-page {
  max-width: 860px;
}

.switch-row,
.pct-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 8px 24px;
}

.unit {
  margin-left: 8px;
  color: #6b7c93;
  font-size: 13px;
}

.locked-rules {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
  margin: 0;
}

.locked-rules dt {
  color: #829ab1;
  font-size: 12px;
}

.locked-rules dd {
  margin: 4px 0 0;
  color: #102a43;
  font-size: 14px;
}

.actions {
  display: flex;
  justify-content: flex-end;
}
</style>
