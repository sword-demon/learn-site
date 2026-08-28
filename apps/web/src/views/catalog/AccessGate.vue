<template>
  <template v-if="!lessonLocked">
    <slot />
  </template>
  <template v-else>
    <button type="button" class="lock-trigger" :aria-label="actionLabel" @click="openDialog">
      <svg class="lock-icon" viewBox="0 0 16 16" aria-hidden="true">
        <path
          d="M4.5 7V5a3.5 3.5 0 1 1 7 0v2M3.5 7h9a1 1 0 0 1 1 1v5.5a1 1 0 0 1-1 1h-9a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1Z"
          fill="none"
          stroke="currentColor"
          stroke-width="1.4"
          stroke-linejoin="round"
        />
      </svg>
      <span>{{ actionLabel }}</span>
    </button>

    <dialog ref="dialogRef" class="gate-dialog" @cancel="onDialogCancel">
      <form method="dialog" class="gate-sheet" @submit.prevent>
        <header class="gate-header">
          <p class="gate-kicker latin">访问验证</p>
          <h2 class="gate-title display">{{ headline }}</h2>
          <p class="gate-detail">{{ detail }}</p>
        </header>

        <p v-if="lessonTitle" class="gate-lesson">
          <span class="gate-lesson-label">课节</span>
          {{ lessonTitle }}
        </p>

        <p v-if="errorMessage" class="gate-error" role="alert">{{ errorMessage }}</p>

        <footer class="gate-actions">
          <button v-if="!authed" type="button" class="btn btn-primary" @click="goLogin">
            登录后继续
          </button>
          <button
            v-else-if="priceMode === 'free'"
            type="button"
            class="btn btn-primary"
            :disabled="busy"
            @click="startFree"
          >
            {{ busy ? '授权中…' : '免费加入课程' }}
          </button>
          <template v-else>
            <button type="button" class="btn btn-primary" :disabled="busy" @click="buy">
              {{ busy ? '下单中…' : '前往购买' }}
            </button>
            <button type="button" class="btn btn-ghost" @click="goOrders">查看订单</button>
          </template>
          <button type="button" class="btn btn-ghost" @click="closeDialog">稍后再说</button>
        </footer>
      </form>
    </dialog>
  </template>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { hasTokens } from '@/api/http';
import { createCourseOrder, startCourse } from '@/api/learner';

interface AccessGateProps {
  locked: boolean;
  viewerAuthorized: boolean;
  priceMode: 'free' | 'paid';
  courseId: number;
  lessonId?: number;
  lessonTitle?: string;
}

const props = defineProps<AccessGateProps>();
const emit = defineEmits<{ (e: 'entitled'): void }>();
const router = useRouter();
const route = useRoute();

const dialogRef = ref<HTMLDialogElement | null>(null);
const busy = ref(false);
const errorMessage = ref('');

const lessonLocked = computed(() => props.locked);
const authed = computed(() => hasTokens());

const headline = computed(() => {
  if (!authed.value) return '登录后即可继续学习';
  if (props.priceMode === 'free') return '尚未取得课程访问权';
  return '购买后即可学习完整课节';
});

const detail = computed(() => {
  if (!authed.value) {
    return '试看课节可直接打开；完整课节需登录学员账号。登录后将回到本页，或直接进入课节。';
  }
  if (props.priceMode === 'free') {
    return '这是一门免费课程，点击「免费加入课程」即可获得课程访问权。';
  }
  return '完成支付后即可访问全部非试看课节。';
});

const actionLabel = computed(() => {
  if (!authed.value) return '登录后学习';
  if (props.priceMode === 'free') return '加入后学习';
  return '购买后学习';
});

function openDialog(): void {
  errorMessage.value = '';
  dialogRef.value?.showModal();
}

function closeDialog(): void {
  dialogRef.value?.close();
}

function onDialogCancel(): void {
  errorMessage.value = '';
}

function goLogin(): void {
  const redirect =
    props.lessonId != null
      ? `/learn/${props.courseId}/${props.lessonId}`
      : route.fullPath;
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

async function buy(): Promise<void> {
  busy.value = true;
  errorMessage.value = '';
  try {
    await createCourseOrder(props.courseId);
    closeDialog();
    router.push('/me/orders');
  } catch (err: unknown) {
    const code = (err as { code?: string }).code;
    if (code === 'CONFLICT') {
      emit('entitled');
      closeDialog();
      router.push(`/courses/${props.courseId}`);
      return;
    }
    errorMessage.value = '下单失败，请稍后再试。';
  } finally {
    busy.value = false;
  }
}
</script>

<style scoped>
.lock-trigger {
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
  cursor: pointer;
  transition:
    border-color 0.2s ease,
    color 0.2s ease,
    background-color 0.2s ease;
}

.lock-trigger:hover {
  border-color: var(--accent);
  background: var(--accent-soft);
  color: var(--accent-deep);
}

.lock-icon {
  width: 14px;
  height: 14px;
  flex-shrink: 0;
}

.gate-dialog {
  width: min(440px, calc(100vw - 32px));
  max-height: calc(100vh - 32px);
  margin: auto;
  padding: 0;
  border: 1px solid var(--line);
  border-radius: var(--radius);
  background: var(--surface);
  box-shadow: var(--shadow);
  color: var(--ink);
}

.gate-dialog::backdrop {
  background: rgba(30, 41, 37, 0.42);
  backdrop-filter: blur(3px);
}

.gate-sheet {
  display: grid;
  gap: 0;
  padding: 0;
  margin: 0;
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

.gate-actions .btn {
  flex: 1 1 auto;
  min-width: 120px;
}
</style>
