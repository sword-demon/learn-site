<template>
  <section class="page">
    <header class="bar">
      <h2>课程管理</h2>
      <div class="actions">
        <el-button type="primary" @click="goCreate"> 新建课程 </el-button>
      </div>
    </header>

    <div class="filters">
      <el-select v-model="filters.status" placeholder="状态" clearable style="width: 140px">
        <el-option label="草稿" value="draft" />
        <el-option label="已发布" value="published" />
        <el-option label="已下架" value="unpublished" />
      </el-select>
      <el-input
        v-model="filters.q"
        placeholder="按标题/讲师搜索"
        clearable
        style="width: 260px"
        @keyup.enter="reload"
      />
      <el-button @click="reload"> 搜索 </el-button>
    </div>

    <el-alert
      v-if="status === 'error'"
      :title="errorMessage"
      type="error"
      show-icon
      :closable="false"
    />

    <el-table v-loading="loading" :data="rows" stripe class="table">
      <el-table-column prop="id" label="ID" width="80" />
      <el-table-column prop="title" label="标题" min-width="220" />
      <el-table-column prop="teacher_name" label="讲师" width="120" />
      <el-table-column label="价格" width="140">
        <template #default="{ row }">
          <span>{{ formatPrice(row) }}</span>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="120">
        <template #default="{ row }">
          <el-tag :type="statusType(row.status)" effect="light">
            {{ statusLabel(row.status) }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="updated_at" label="更新于" width="180" />
      <el-table-column label="操作" width="430" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="goEdit(row.id)"> 编辑 </el-button>
          <el-button link type="primary" @click="goStudents(row.id)"> 学员名单 </el-button>
          <el-button
            v-if="canManageActivationCodes"
            link
            type="primary"
            @click="goActivationCodes(row.id)"
            >激活码</el-button
          >
          <el-button v-if="canManageFeedback" link type="primary" @click="goFeedback(row.id)"
            >意见反馈</el-button
          >
          <el-button link type="primary" @click="goPreview(row.id)"> 预览 </el-button>
          <el-button v-if="row.status !== 'published'" link type="success" @click="onPublish(row)">
            发布
          </el-button>
          <el-button v-else link type="warning" @click="onUnpublish(row)"> 下架 </el-button>
          <el-button v-if="row.status !== 'published'" link type="danger" @click="onDelete(row)">
            删除
          </el-button>
        </template>
      </el-table-column>
    </el-table>

    <AdminListPager
      v-model:page="filters.page"
      v-model:page-size="filters.limit"
      :total="total"
      @change="reload"
    />
  </section>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { ElMessage, ElMessageBox } from 'element-plus';
import type { CourseDTO, CourseStatus } from '@learn-site/contracts';
import { listCourses, publishCourse, unpublishCourse, deleteCourse } from '@/api/catalog';
import AdminListPager from '@/components/AdminListPager.vue';
import { hasPermission } from '@/api/http';

const router = useRouter();
const canManageActivationCodes = computed(() => hasPermission('activation_code.manage'));
const canManageFeedback = computed(() => hasPermission('course_feedback.manage'));
const loading = ref(false);
const status = ref<'idle' | 'error'>('idle');
const errorMessage = ref('');
const rows = ref<CourseDTO[]>([]);
const total = ref(0);

const filters = reactive({
  status: '' as CourseStatus | '',
  q: '',
  page: 1,
  limit: 20,
});

function statusLabel(s: CourseStatus): string {
  switch (s) {
    case 'draft':
      return '草稿';
    case 'published':
      return '已发布';
    case 'unpublished':
      return '已下架';
  }
}

function statusType(s: CourseStatus): 'info' | 'success' | 'warning' {
  switch (s) {
    case 'draft':
      return 'info';
    case 'published':
      return 'success';
    case 'unpublished':
      return 'warning';
  }
}

function formatPrice(row: CourseDTO): string {
  if (row.price_mode === 'free') return '免费';
  const list = Number(row.list_price ?? 0).toFixed(2);
  const sale = Number(row.sale_price ?? 0);
  if (sale > 0 && sale < Number(row.list_price ?? 0)) {
    return `¥${sale.toFixed(2)} / ¥${list}`;
  }
  return `¥${list}`;
}

async function reload(): Promise<void> {
  loading.value = true;
  status.value = 'idle';
  errorMessage.value = '';
  try {
    const params: { status?: CourseStatus; q?: string; page: number; limit: number } = {
      page: filters.page,
      limit: filters.limit,
    };
    if (filters.status) params.status = filters.status;
    if (filters.q.trim()) params.q = filters.q.trim();
    const res = await listCourses(params);
    rows.value = res.items;
    total.value = res.total;
  } catch (err: unknown) {
    status.value = 'error';
    errorMessage.value = readError(err, '加载课程失败');
  } finally {
    loading.value = false;
  }
}

function goCreate(): void {
  router.push('/courses/new');
}

function goEdit(id: number): void {
  router.push(`/courses/${id}/edit`);
}

function goPreview(id: number): void {
  router.push(`/courses/${id}/preview`);
}

function goStudents(id: number): void {
  router.push(`/courses/${id}/students`);
}

function goActivationCodes(id: number): void {
  router.push(`/courses/${id}/activation-codes`);
}

function goFeedback(id: number): void {
  router.push(`/courses/${id}/feedback`);
}

async function onPublish(row: CourseDTO): Promise<void> {
  try {
    await ElMessageBox.confirm(`确定发布「${row.title}」吗？`, '发布', { type: 'info' });
  } catch {
    return;
  }
  try {
    await publishCourse(row.id);
    ElMessage.success('已发布');
    await reload();
  } catch (err: unknown) {
    ElMessage.error(readError(err, '发布失败'));
  }
}

async function onUnpublish(row: CourseDTO): Promise<void> {
  try {
    await ElMessageBox.confirm(`确定下架「${row.title}」吗？`, '下架', { type: 'warning' });
  } catch {
    return;
  }
  try {
    await unpublishCourse(row.id);
    ElMessage.success('已下架');
    await reload();
  } catch (err: unknown) {
    ElMessage.error(readError(err, '下架失败'));
  }
}

async function onDelete(row: CourseDTO): Promise<void> {
  try {
    await ElMessageBox.confirm(`确定删除「${row.title}」吗？此操作不可撤销。`, '删除', {
      type: 'warning',
    });
  } catch {
    return;
  }
  try {
    await deleteCourse(row.id);
    ElMessage.success('已删除');
    await reload();
  } catch (err: unknown) {
    ElMessage.error(readError(err, '删除失败'));
  }
}

function readError(err: unknown, fallback: string): string {
  const code = (err as { response?: { data?: { error?: { code?: string; message?: string } } } })
    ?.response?.data?.error?.code;
  const message = (err as { response?: { data?: { error?: { message?: string } } } })?.response
    ?.data?.error?.message;
  if (code === 'CONFLICT') {
    const conflictMessages: Record<string, string> = {
      COURSE_DELETE_REQUIRES_UNPUBLISHED: '课程已发布，请先下架再删除',
      COURSE_HAS_ORDERS: '课程已有订单，无法删除',
      COURSE_HAS_ENTITLEMENTS: '课程已有授权记录，无法删除',
      COURSE_HAS_LEARNING_RECORDS: '课程已有学习记录，无法删除',
      COURSE_IN_LEARNING_MAP: '课程仍在学习地图中，无法删除',
    };
    return conflictMessages[message ?? ''] ?? '操作冲突';
  }
  if (code === 'CATEGORY_IN_USE') return '分类仍在使用中';
  if (code === 'VALIDATION_FAILED') return message ?? '校验失败';
  if (code === 'NOT_FOUND') return '资源不存在';
  return fallback;
}

onMounted(() => {
  void reload();
});
</script>

<style scoped>
.page {
  background: #ffffff;
  border-radius: 8px;
  padding: 20px;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
}
.bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.bar h2 {
  margin: 0;
  font-size: 18px;
  color: #0f172a;
}
.filters {
  display: flex;
  gap: 12px;
  margin-bottom: 16px;
}
.table {
  width: 100%;
}
</style>
