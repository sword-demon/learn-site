<template>
  <section class="page">
    <header class="bar">
      <el-button link @click="goBack"> ← 返回课程列表 </el-button>
      <div class="actions">
        <el-button :disabled="isNew" @click="goEdit"> 返回编辑 </el-button>
        <el-button
          v-if="tree.status === 'published'"
          type="warning"
          :loading="working"
          @click="onUnpublish"
        >
          下架
        </el-button>
        <el-button v-else type="success" :loading="working" @click="onPublish"> 发布 </el-button>
      </div>
    </header>

    <el-alert
      v-if="status === 'error'"
      :title="errorMessage"
      type="error"
      show-icon
      :closable="false"
    />

    <article v-if="tree.id" class="course">
      <div class="cover" :style="coverStyle" />
      <h1 class="title">
        {{ tree.title }}
      </h1>
      <p class="meta">
        <span>讲师：{{ tree.teacher_name }}</span>
        <span>·</span>
        <span>{{ formatPrice(tree) }}</span>
        <span>·</span>
        <el-tag size="small" :type="statusType(tree.status)">
          {{ statusLabel(tree.status) }}
        </el-tag>
      </p>
      <p v-if="tree.summary" class="summary">
        {{ tree.summary }}
      </p>
      <!-- eslint-disable-next-line vue/no-v-html -- ponytail: server-side HtmlSanitizer whitelisted before persist -->
      <section class="rich" v-html="tree.intro_rich_text" />

      <h2 class="toc-title">课程目录</h2>
      <p v-if="(tree.chapters ?? []).length === 0" class="muted">暂无章节</p>
      <el-collapse>
        <el-collapse-item v-for="ch in tree.chapters ?? []" :key="ch.id" :name="ch.id">
          <template #title>
            <strong>{{ ch.sort + 1 }}. {{ ch.title }}</strong>
            <span class="muted small">（{{ ch.lessons.length }} 节）</span>
            <el-tag v-if="ch.status !== 'enabled'" size="small" type="info" class="ml">
              已禁用
            </el-tag>
          </template>
          <ul class="lessons">
            <li v-for="ls in ch.lessons" :key="ls.id">
              <span class="lt">{{ ls.sort + 1 }}. {{ ls.title }}</span>
              <el-tag size="small" effect="plain">
                {{ contentTypeLabel(ls.content_type) }}
              </el-tag>
              <el-tag v-if="ls.is_preview" size="small" type="warning"> 试看 </el-tag>
              <el-tag v-if="ls.status !== 'enabled'" size="small" type="info"> 已禁用 </el-tag>
            </li>
            <li v-if="ch.lessons.length === 0" class="muted">该章节暂无课节</li>
          </ul>
        </el-collapse-item>
      </el-collapse>
    </article>

    <el-empty v-else description="课程不存在" />
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage, ElMessageBox } from 'element-plus';
import type {
  CourseStatus,
  CourseTreeDTO,
  LessonContentType,
  PriceMode,
} from '@learn-site/contracts';
import { getCourseTree, publishCourse, unpublishCourse } from '@/api/catalog';

const route = useRoute();
const router = useRouter();
const status = ref<'idle' | 'error'>('idle');
const errorMessage = ref('');
const working = ref(false);
const tree = reactive<
  Partial<CourseTreeDTO> & { chapters: NonNullable<CourseTreeDTO['chapters']> }
>({
  id: 0,
  title: '',
  cover_url: null,
  teacher_name: '',
  summary: '',
  intro_rich_text: '',
  price_mode: 'free',
  list_price: 0,
  sale_price: 0,
  chapters: [],
});

const isNew = computed(() => !route.params.id);
const coverStyle = computed(() => {
  if (tree.cover_url) {
    return {
      backgroundImage: `url(${tree.cover_url})`,
      backgroundSize: 'cover',
      backgroundPosition: 'center',
    };
  }
  return {};
});

function contentTypeLabel(t: LessonContentType): string {
  switch (t) {
    case 'markdown':
      return 'Markdown';
    case 'pdf':
      return 'PDF';
    case 'video':
      return '视频';
  }
}

function statusLabel(s: CourseStatus | undefined): string {
  if (s === 'published') return '已发布';
  if (s === 'unpublished') return '已下架';
  return '草稿';
}

function statusType(s: CourseStatus | undefined): 'info' | 'success' | 'warning' {
  if (s === 'published') return 'success';
  if (s === 'unpublished') return 'warning';
  return 'info';
}

function formatPrice(row: {
  price_mode?: PriceMode;
  list_price?: number | null;
  sale_price?: number | null;
}): string {
  if (row.price_mode === 'free') return '免费';
  const list = Number(row.list_price ?? 0).toFixed(2);
  const sale = Number(row.sale_price ?? 0);
  if (sale > 0 && sale < Number(row.list_price ?? 0)) return `¥${sale.toFixed(2)} / ¥${list}`;
  return `¥${list}`;
}

async function reload(): Promise<void> {
  if (isNew.value) return;
  status.value = 'idle';
  errorMessage.value = '';
  try {
    const t = await getCourseTree(Number(route.params.id));
    Object.assign(tree, t);
    tree.chapters = (t.chapters ?? []).map((ch) => ({
      id: ch.id,
      course_id: ch.course_id,
      title: ch.title,
      sort: ch.sort,
      status: ch.status,
      lessons: ch.lessons ?? [],
    }));
  } catch (err: unknown) {
    status.value = 'error';
    errorMessage.value = readError(err, '加载失败');
  }
}

async function onPublish(): Promise<void> {
  try {
    await ElMessageBox.confirm('确定发布该课程吗？', '发布', { type: 'info' });
  } catch {
    return;
  }
  working.value = true;
  try {
    await publishCourse(Number(route.params.id));
    ElMessage.success('已发布');
    await reload();
  } catch (err: unknown) {
    ElMessage.error(readError(err, '发布失败'));
  } finally {
    working.value = false;
  }
}

async function onUnpublish(): Promise<void> {
  try {
    await ElMessageBox.confirm('确定下架该课程吗？', '下架', { type: 'warning' });
  } catch {
    return;
  }
  working.value = true;
  try {
    await unpublishCourse(Number(route.params.id));
    ElMessage.success('已下架');
    await reload();
  } catch (err: unknown) {
    ElMessage.error(readError(err, '下架失败'));
  } finally {
    working.value = false;
  }
}

function goEdit(): void {
  router.push(`/courses/${route.params.id}/edit`);
}

function goBack(): void {
  router.push('/courses');
}

function readError(err: unknown, fallback: string): string {
  const code = (err as { response?: { data?: { error?: { code?: string; message?: string } } } })
    ?.response?.data?.error?.code;
  const message = (err as { response?: { data?: { error?: { message?: string } } } })?.response
    ?.data?.error?.message;
  if (code === 'VALIDATION_FAILED') return message ?? '校验失败';
  if (code === 'NOT_FOUND') return '资源不存在';
  if (code === 'CONFLICT') return message ?? '操作冲突';
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
  margin-bottom: 16px;
}
.actions {
  display: flex;
  gap: 8px;
}
.course .cover {
  width: 100%;
  height: 220px;
  background: linear-gradient(135deg, #0ea5e9 0%, #6366f1 100%);
  border-radius: 8px;
}
.course .title {
  font-size: 24px;
  font-weight: 600;
  margin: 16px 0 4px;
  color: #0f172a;
}
.course .meta {
  display: flex;
  align-items: center;
  gap: 8px;
  color: #64748b;
  font-size: 14px;
  margin: 0 0 12px;
}
.course .summary {
  color: #475569;
  margin: 0 0 16px;
}
.course .rich {
  background: #f8fafc;
  padding: 16px;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
  margin-bottom: 24px;
}
.toc-title {
  font-size: 16px;
  margin: 16px 0 12px;
  color: #0f172a;
}
.muted {
  color: #94a3b8;
}
.muted.small {
  font-size: 12px;
  margin-left: 6px;
}
.lessons {
  list-style: none;
  margin: 0;
  padding: 0;
}
.lessons li {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 0;
  border-bottom: 1px dashed #e2e8f0;
}
.lessons li .lt {
  flex: 1 1 auto;
  font-size: 13px;
  color: #0f172a;
}
.ml {
  margin-left: 6px;
}
</style>
