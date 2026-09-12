<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ElMessage } from 'element-plus';
import type { CourseDTO, DistributionCourseOverrideDTO } from '@learn-site/contracts';
import { fetchCourseOverrides, saveCourseOverride } from '@/api/distribution';
import { listCourses, type ListCoursesParams } from '@/api/catalog';
import AdminListPager from '@/components/AdminListPager.vue';
import './distribution.css';

defineOptions({ name: 'DistributionCourseOverridesView' });

const rows = ref<DistributionCourseOverrideDTO[]>([]);
const total = ref(0);
const loading = ref(false);
const adding = ref(false);
const errorMsg = ref<string | null>(null);
const page = ref(1);
const limit = ref(20);
const newCourseId = ref<number | null>(null);
const newEnabled = ref(true);
const courseOptions = ref<CourseDTO[]>([]);
const courseOptionsPage = ref(0);
const courseOptionsTotal = ref(0);
const courseOptionsLoading = ref(false);
const courseOptionsQuery = ref('');
const togglingId = ref<number | null>(null);
let courseRequestId = 0;
const optionPageSize = 20;

const hasMoreCourses = computed(
  () => courseOptionsPage.value * optionPageSize < courseOptionsTotal.value,
);

function courseTitle(courseId: number): string {
  const hit = courseOptions.value.find((item) => item.id === courseId);
  return hit ? hit.title : `课程 #${courseId}`;
}

function formatPct(value: number | null): string {
  if (value === null) return '跟随全局';
  return `${Math.round(value * 10000) / 100}%`;
}

function formatYuan(cents: number | null): string {
  if (cents === null) return '跟随全局';
  return `¥ ${(cents / 100).toFixed(2)}`;
}

async function loadCourseOptions(
  query = courseOptionsQuery.value,
  nextPage = 1,
  append = false,
): Promise<void> {
  const requestId = ++courseRequestId;
  courseOptionsLoading.value = true;
  const trimmedQuery = query.trim();
  courseOptionsQuery.value = trimmedQuery;
  try {
    const params: ListCoursesParams = { page: nextPage, limit: optionPageSize };
    if (trimmedQuery) params.q = trimmedQuery;
    const data = await listCourses(params);
    if (requestId !== courseRequestId) return;
    courseOptions.value = append ? [...courseOptions.value, ...data.items] : data.items;
    courseOptionsPage.value = data.page;
    courseOptionsTotal.value = data.total;
  } catch {
    if (requestId !== courseRequestId) return;
    if (!append) courseOptions.value = [];
    courseOptionsPage.value = 0;
    courseOptionsTotal.value = 0;
  } finally {
    if (requestId === courseRequestId) courseOptionsLoading.value = false;
  }
}

async function reload(): Promise<void> {
  loading.value = true;
  errorMsg.value = null;
  try {
    const data = await fetchCourseOverrides(page.value, limit.value);
    rows.value = data.items;
    total.value = data.total;
  } catch (error) {
    errorMsg.value = (error as Error).message || 'load_failed';
    rows.value = [];
    total.value = 0;
  } finally {
    loading.value = false;
  }
}

async function toggle(row: DistributionCourseOverrideDTO): Promise<void> {
  togglingId.value = row.course_id;
  try {
    await saveCourseOverride(row.course_id, { enabled: !row.enabled });
    ElMessage.success('已更新');
    await reload();
  } catch (error) {
    errorMsg.value = (error as Error).message || 'save_failed';
  } finally {
    togglingId.value = null;
  }
}

async function addOverride(): Promise<void> {
  const courseId = newCourseId.value;
  if (courseId === null || !Number.isInteger(courseId) || courseId <= 0) {
    errorMsg.value = '请选择课程';
    return;
  }
  adding.value = true;
  errorMsg.value = null;
  try {
    await saveCourseOverride(courseId, { enabled: newEnabled.value });
    ElMessage.success('已添加覆盖');
    newCourseId.value = null;
    await reload();
  } catch (error) {
    errorMsg.value = (error as Error).message || 'save_failed';
  } finally {
    adding.value = false;
  }
}

function searchCourses(query: string): void {
  void loadCourseOptions(query);
}

function loadMoreCourses(): void {
  if (courseOptionsLoading.value || !hasMoreCourses.value) return;
  void loadCourseOptions(courseOptionsQuery.value, courseOptionsPage.value + 1, true);
}

function onCourseVisibleChange(visible: boolean): void {
  if (visible && courseOptionsPage.value === 0) void loadCourseOptions();
}

onMounted(() => void reload());
</script>

<template>
  <main class="dist-page">
    <header class="dist-head">
      <div>
        <span class="dist-kicker">分销运营 / 课程覆盖</span>
        <h1 class="dist-display">课程分销覆盖</h1>
        <p class="dist-subtitle">按课程覆盖全局开关. 未配置的课程跟随站点分销配置.</p>
      </div>
      <div class="dist-metric">
        <span>覆盖</span>
        <strong>{{ total }}</strong>
        <span>门课程</span>
      </div>
    </header>

    <el-alert v-if="errorMsg" :title="errorMsg" type="error" :closable="false" show-icon />

    <el-card class="dist-panel" shadow="never">
      <el-form class="dist-filter" inline aria-label="新增课程覆盖" @submit.prevent="addOverride">
        <el-form-item label="课程">
          <el-select
            v-model="newCourseId"
            class="dist-control-wide"
            filterable
            remote
            clearable
            :loading="courseOptionsLoading"
            :remote-method="searchCourses"
            placeholder="选择要覆盖的课程"
            data-field="course_id"
            @visible-change="onCourseVisibleChange"
          >
            <el-option
              v-for="course in courseOptions"
              :key="course.id"
              :label="`#${course.id} ${course.title}`"
              :value="course.id"
            />
            <template #footer>
              <div v-if="courseOptionsTotal > 0" class="option-footer">
                <el-button
                  v-if="hasMoreCourses"
                  text
                  type="primary"
                  size="small"
                  :loading="courseOptionsLoading"
                  @click="loadMoreCourses"
                >
                  加载更多
                </el-button>
                <span v-else>已加载全部</span>
              </div>
            </template>
          </el-select>
        </el-form-item>
        <el-form-item label="覆盖开关">
          <el-switch v-model="newEnabled" active-text="开启" inactive-text="关闭" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" native-type="submit" :loading="adding">添加覆盖</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card class="dist-panel" shadow="never">
      <el-table v-loading="loading" :data="rows" stripe empty-text="还没有课程覆盖">
        <el-table-column label="课程" min-width="180">
          <template #default="{ row }">{{ courseTitle(row.course_id) }}</template>
        </el-table-column>
        <el-table-column label="开关" width="100">
          <template #default="{ row }">
            <el-tag :type="row.enabled ? 'success' : 'info'">{{
              row.enabled ? '开启' : '关闭'
            }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="一级" width="110">
          <template #default="{ row }">{{ formatPct(row.level1_pct) }}</template>
        </el-table-column>
        <el-table-column label="二级" width="110">
          <template #default="{ row }">{{ formatPct(row.level2_pct) }}</template>
        </el-table-column>
        <el-table-column label="三级" width="110">
          <template #default="{ row }">{{ formatPct(row.level3_pct) }}</template>
        </el-table-column>
        <el-table-column label="单笔封顶" width="120">
          <template #default="{ row }">{{ formatYuan(row.per_order_cap_cents) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="120" fixed="right">
          <template #default="{ row }">
            <el-button
              text
              data-action="toggle"
              :loading="togglingId === row.course_id"
              @click="toggle(row)"
            >
              切换
            </el-button>
          </template>
        </el-table-column>
        <template #empty><el-empty description="还没有课程分销覆盖" :image-size="88" /></template>
      </el-table>
      <AdminListPager
        v-model:page="page"
        v-model:page-size="limit"
        :total="total"
        @change="reload"
      />
    </el-card>
  </main>
</template>

<style scoped>
.option-footer {
  display: flex;
  min-height: 36px;
  align-items: center;
  justify-content: center;
  color: #829ab1;
  font-size: 12px;
}
</style>
