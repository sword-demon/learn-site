<template>
  <template v-if="!lessonLocked">
    <slot />
  </template>
  <template v-else>
    <el-button
      plain
      class="lock-trigger"
      :icon="Lock"
      :aria-label="actionLabel"
      @click="openDialog"
    >
      {{ actionLabel }}
    </el-button>

    <el-dialog
      v-model="dialogVisible"
      class="gate-dialog"
      width="440px"
      :show-close="false"
      append-to-body
      @closed="onDialogClosed"
    >
      <header class="gate-header">
        <p class="gate-kicker latin">访问验证</p>
        <h2 class="gate-title display">{{ headline }}</h2>
        <p class="gate-detail">{{ detail }}</p>
      </header>

      <p v-if="lessonTitle" class="gate-lesson">
        <span class="gate-lesson-label">课节</span>
        {{ lessonTitle }}
      </p>

      <el-alert
        v-if="errorMessage"
        class="gate-error"
        :title="errorMessage"
        type="error"
        :closable="false"
        show-icon
      />

      <template #footer>
        <footer class="gate-actions">
          <el-button v-if="!authed" type="primary" @click="goLogin"> 登录后继续 </el-button>
          <el-button
            v-else-if="priceMode === 'free'"
            type="primary"
            :loading="busy"
            @click="startFree"
          >
            {{ canRejoin ? '再次加入课程' : '免费加入课程' }}
          </el-button>
          <template v-else>
            <el-button type="primary" :loading="busy" @click="buy">前往购买</el-button>
            <el-button @click="redeem">使用激活码</el-button>
            <el-button @click="goOrders">查看订单</el-button>
          </template>
          <el-button @click="closeDialog">稍后再说</el-button>
        </footer>
      </template>
    </el-dialog>
  </template>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Lock } from '@element-plus/icons-vue';
import { hasTokens } from '@/api/http';
import { startCourse } from '@/api/learner';

interface AccessGateProps {
  locked: boolean;
  viewerAuthorized: boolean;
  priceMode: 'free' | 'paid';
  courseId: number;
  lessonId?: number;
  lessonTitle?: string;
  canRejoin?: boolean;
  revokedReason?: string | null;
}

const props = defineProps<AccessGateProps>();
const emit = defineEmits<{ (e: 'entitled'): void }>();
const router = useRouter();
const route = useRoute();

const dialogVisible = ref(false);
const busy = ref(false);
const errorMessage = ref('');

const lessonLocked = computed(() => props.locked);
const authed = computed(() => hasTokens());

const headline = computed(() => {
  if (!authed.value) return '登录后即可继续学习';
  if (props.canRejoin) return '课程访问权已被撤销';
  if (props.priceMode === 'free') return '尚未取得课程访问权';
  return '购买后即可学习完整课节';
});

const detail = computed(() => {
  if (!authed.value) {
    return '试看课节可直接打开；完整课节需登录学员账号。登录后将回到本页，或直接进入课节。';
  }
  if (props.priceMode === 'free') {
    if (props.canRejoin) {
      const reason = props.revokedReason ? `原因：${props.revokedReason}。` : '';
      return `${reason}再次加入后可沿用原有学习进度。`;
    }
    return '这是一门免费课程，点击「免费加入课程」即可获得课程访问权。';
  }
  return '完成支付后即可访问全部非试看课节。';
});

const actionLabel = computed(() => {
  if (!authed.value) return '登录后学习';
  if (props.canRejoin) return '再次加入后学习';
  if (props.priceMode === 'free') return '加入后学习';
  return '购买后学习';
});

function openDialog(): void {
  errorMessage.value = '';
  dialogVisible.value = true;
}

function closeDialog(): void {
  dialogVisible.value = false;
}

function onDialogClosed(): void {
  errorMessage.value = '';
}

function goLogin(): void {
  const redirect =
    props.lessonId != null ? `/learn/${props.courseId}/${props.lessonId}` : route.fullPath;
  closeDialog();
  router.push(`/login?redirect=${encodeURIComponent(redirect)}`);
}

function goOrders(): void {
  closeDialog();
  router.push('/me/orders');
}

async function startFree(): Promise<void> {
  busy.value = true;
  errorMessage.value = '';
  try {
    const result = await startCourse(props.courseId);
    emit('entitled');
    closeDialog();
    if (props.lessonId != null) {
      router.push(`/learn/${props.courseId}/${props.lessonId}`);
      return;
    }
    if (result.first_lesson) {
      router.push(`/learn/${result.course_id}/${result.first_lesson.id}`);
      return;
    }
    router.push(`/courses/${result.course_id}`);
  } catch (err: unknown) {
    const code = (err as { code?: string }).code;
    if (code === 'CONFLICT') {
      emit('entitled');
      closeDialog();
      if (props.lessonId != null) {
        router.push(`/learn/${props.courseId}/${props.lessonId}`);
      } else {
        router.push(`/courses/${props.courseId}`);
      }
      return;
    }
    errorMessage.value = code === 'NOT_FOUND' ? '课程不存在或已下架。' : '授权失败，请稍后再试。';
  } finally {
    busy.value = false;
  }
}

function buy(): void {
  closeDialog();
  router.push(`/checkout/${props.courseId}`);
}

function redeem(): void {
  closeDialog();
  router.push('/me/redeem');
}
</script>

<style scoped>
.lock-trigger.el-button {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 32px;
  padding: 5px 10px;
  border: 1px dashed var(--line);
  border-radius: 6px;
  background: rgba(255, 254, 250, 0.72);
  color: var(--muted);
  font-size: 0.78rem;
  font-weight: 600;
  margin-left: 0;
  transition:
    border-color 0.2s ease,
    color 0.2s ease,
    background-color 0.2s ease;
}

.lock-trigger.el-button:hover {
  border-color: var(--accent);
  background: var(--accent-soft);
  color: var(--accent-deep);
}

:deep(.gate-dialog) {
  max-width: calc(100vw - 32px);
  border: 1px solid var(--line);
  border-radius: var(--radius);
  background: var(--surface);
  box-shadow: var(--shadow);
  color: var(--ink);
}

:deep(.gate-dialog .el-dialog__header) {
  display: none;
}

:deep(.gate-dialog .el-dialog__body),
:deep(.gate-dialog .el-dialog__footer) {
  padding: 0;
}

.gate-header {
  padding: 22px 22px 16px;
  border-bottom: 1px solid var(--line);
  background: linear-gradient(180deg, var(--surface-muted), var(--surface));
}

.gate-kicker {
  margin: 0 0 8px;
  color: var(--accent);
  font-size: 0.68rem;
  letter-spacing: 0.12em;
}

.gate-title {
  margin: 0 0 8px;
  color: var(--pine-deep);
  font-size: 1.35rem;
  line-height: 1.3;
}

.gate-detail {
  margin: 0;
  color: var(--muted);
  font-size: 0.84rem;
  line-height: 1.65;
}

.gate-lesson {
  margin: 0;
  padding: 14px 22px;
  border-bottom: 1px solid var(--line);
  font-size: 0.86rem;
  line-height: 1.5;
}

.gate-lesson-label {
  display: block;
  margin-bottom: 4px;
  color: var(--muted);
  font-family: var(--font-mono);
  font-size: 0.68rem;
  letter-spacing: 0.08em;
}

.gate-error {
  margin: 0;
  padding: 12px 22px 0;
  color: #9e3f2c;
  font-size: 0.8rem;
}

.gate-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding: 18px 22px 22px;
}

.gate-actions .el-button {
  flex: 1 1 auto;
  min-width: 120px;
  margin-left: 0;
}
</style>
