<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { useRouter } from 'vue-router';
import {
  ContentTodoErrorCodes,
  type ContentTodoDetail,
  type ContentTodoLabel,
  type ContentTodoApproveRequest,
  type ContentTodoCloseRequest,
} from '@contracts/contentTodo';
import { hasPermission } from '@/api/http';
import {
  approveContentTodoCandidate,
  closeContentTodo,
  editContentTodoCandidate,
  fetchContentTodo,
  generateContentTodoCandidate,
  rejectContentTodoCandidate,
  respondContentTodo,
  triageContentTodo,
} from '@/api/contentTodo';

const props = defineProps<{
  visible: boolean;
  todoId: number | null;
}>();
const emit = defineEmits<{
  'update:visible': [value: boolean];
  changed: [];
}>();

const router = useRouter();
const loading = ref(false);
const detail = ref<ContentTodoDetail | null>(null);
const label = ref<ContentTodoLabel | null>(null);
const targetCourseId = ref<number | null>(null);
const targetChapterId = ref<number | null>(null);
const targetLessonId = ref<number | null>(null);
const responseBody = ref('');
const candidateBody = ref('');
const notifyMode = ref<NonNullable<ContentTodoApproveRequest['notify_mode']>>('submitter');
const closeReason =
  ref<NonNullable<ContentTodoCloseRequest['close_reason_code']>>('already_covered');
const closeNote = ref('');

const canManage = computed(() => hasPermission('content_todo.manage'));
const canWriteCourse = computed(() => hasPermission('course.manage'));
const draftCandidate = computed(() =>
  detail.value?.candidates.find((candidate) => candidate.status === 'draft'),
);
const isOpen = computed(() => {
  const status = detail.value?.workflow_status;
  return status !== 'resolved' && status !== 'closed';
});

function parsePositiveInt(value: unknown): number | null {
  const raw = Array.isArray(value) ? value[0] : value;
  if (raw === null || raw === undefined || raw === '') return null;
  const parsed = typeof raw === 'number' ? raw : Number(String(raw));
  if (!Number.isFinite(parsed) || parsed <= 0) return null;
  return Math.trunc(parsed);
}

function actionError(err: unknown, fallback: string): string {
  const payload = err as { response?: { data?: { error?: { code?: string; message?: string } } } };
  const code = payload.response?.data?.error?.code;
  const message = payload.response?.data?.error?.message;
  if (message === ContentTodoErrorCodes.CONTENT_TODO_VERSION_CONFLICT)
    return '待办已被其他人更新, 请刷新后重试';
  if (message === ContentTodoErrorCodes.CONTENT_TODO_CONTENT_CHANGED)
    return '目标内容已变化, 请重新生成候选';
  if (message === ContentTodoErrorCodes.CONTENT_TODO_TARGET_CHANGED)
    return '目标位置已变化, 请重新生成候选';
  if (code === 'FORBIDDEN') return '没有权限执行该操作';
  if (code === 'CONFLICT') return message ?? '操作冲突';
  if (code === 'VALIDATION_FAILED') return message ?? '校验失败';
  return fallback;
}

function applyDetail(next: ContentTodoDetail): void {
  detail.value = next;
  label.value = next.label;
  targetCourseId.value = next.target.course_id;
  targetChapterId.value = next.target.chapter_id;
  targetLessonId.value = next.target.lesson_id;
  const draft = next.candidates.find((candidate) => candidate.status === 'draft');
  candidateBody.value = draft?.body ?? '';
}

async function load(): Promise<void> {
  if (!props.todoId) return;
  loading.value = true;
  try {
    applyDetail(await fetchContentTodo(props.todoId));
  } catch (err: unknown) {
    ElMessage.error(actionError(err, '内容待办加载失败'));
  } finally {
    loading.value = false;
  }
}

watch(
  () => [props.visible, props.todoId] as const,
  ([visible]) => {
    if (visible) void load();
  },
  { immediate: true },
);

async function saveTriage(): Promise<void> {
  if (!detail.value || !label.value) {
    ElMessage.warning('请先选择主标签');
    return;
  }
  try {
    applyDetail(
      await triageContentTodo(detail.value.id, {
        label: label.value,
        target_course_id: targetCourseId.value,
        target_chapter_id: targetChapterId.value,
        target_lesson_id: targetLessonId.value,
        expected_version: detail.value.version,
      }),
    );
    ElMessage.success('分诊已保存');
    emit('changed');
  } catch (err: unknown) {
    ElMessage.error(actionError(err, '分诊保存失败'));
  }
}

async function respond(): Promise<void> {
  if (!detail.value || !responseBody.value.trim()) {
    ElMessage.warning('请输入响应内容');
    return;
  }
  try {
    applyDetail(await respondContentTodo(detail.value.id, { body: responseBody.value }));
    responseBody.value = '';
    ElMessage.success('响应已发送');
    emit('changed');
  } catch (err: unknown) {
    ElMessage.error(actionError(err, '响应发送失败'));
  }
}

async function generate(): Promise<void> {
  if (!detail.value) return;
  try {
    applyDetail(
      await generateContentTodoCandidate(
        detail.value.id,
        detail.value.label === 'resource_problem' ? { target_kind: 'help_center_candidate' } : {},
      ),
    );
    ElMessage.success('候选草稿已生成');
    emit('changed');
  } catch (err: unknown) {
    ElMessage.error(actionError(err, '候选生成失败'));
  }
}

async function saveCandidate(): Promise<void> {
  if (!detail.value || !draftCandidate.value) return;
  try {
    applyDetail(
      await editContentTodoCandidate(detail.value.id, draftCandidate.value.id, {
        body: candidateBody.value,
      }),
    );
    ElMessage.success('候选已保存');
    emit('changed');
  } catch (err: unknown) {
    ElMessage.error(actionError(err, '候选保存失败'));
  }
}

async function reject(): Promise<void> {
  if (!detail.value || !draftCandidate.value) return;
  let reason: string;
  try {
    const result = await ElMessageBox.prompt('请输入拒绝原因', '拒绝候选', {
      inputValidator: (value) =>
        value !== undefined && value.trim().length > 0 && value.trim().length <= 500
          ? true
          : '请填写 1-500 字原因',
      type: 'warning',
    });
    reason = result.value;
  } catch {
    return;
  }
  try {
    applyDetail(
      await rejectContentTodoCandidate(detail.value.id, draftCandidate.value.id, {
        reason,
      }),
    );
    ElMessage.success('候选已拒绝');
    emit('changed');
  } catch (error: unknown) {
    ElMessage.error(actionError(error, '拒绝候选失败'));
  }
}

async function approve(): Promise<void> {
  if (!detail.value || !draftCandidate.value) return;
  try {
    await ElMessageBox.confirm(
      draftCandidate.value.target_kind === 'help_center_candidate'
        ? '批准后只交接帮助中心候选, 不会直接发布。确认批准?'
        : '批准后将写回学员可见内容, 且不会自动撤销。确认批准?',
      '确认批准',
      { type: 'warning' },
    );
  } catch {
    return;
  }
  try {
    applyDetail(
      await approveContentTodoCandidate(detail.value.id, draftCandidate.value.id, {
        expected_version: detail.value.version,
        notify_mode: notifyMode.value,
      }),
    );
    ElMessage.success('候选已批准并写回');
    emit('changed');
  } catch (error: unknown) {
    ElMessage.error(actionError(error, '候选批准失败'));
  }
}

async function closeWithoutChange(): Promise<void> {
  if (!detail.value || !closeNote.value.trim()) {
    ElMessage.warning('请填写关闭说明');
    return;
  }
  try {
    applyDetail(
      await closeContentTodo(detail.value.id, {
        close_reason_code: closeReason.value,
        close_reason_note: closeNote.value,
        expected_version: detail.value.version,
      }),
    );
    ElMessage.success('待办已关闭');
    emit('changed');
  } catch (err: unknown) {
    ElMessage.error(actionError(err, '关闭待办失败'));
  }
}

async function openCourseEditor(): Promise<void> {
  const courseId = parsePositiveInt(detail.value?.target.course_id);
  if (!courseId) {
    ElMessage.warning('请先确认课程目标');
    return;
  }
  const query: Record<string, string> = {};
  const chapterId = parsePositiveInt(detail.value?.target.chapter_id);
  const lessonId = parsePositiveInt(detail.value?.target.lesson_id);
  if (chapterId) query.chapter_id = String(chapterId);
  if (lessonId) query.lesson_id = String(lessonId);
  await router.push({
    name: 'course-edit',
    params: { id: String(courseId) },
    ...(Object.keys(query).length > 0 ? { query } : {}),
  });
}
</script>

<template>
  <el-drawer
    :model-value="visible"
    title="内容待办"
    size="560px"
    @update:model-value="emit('update:visible', $event)"
  >
    <el-skeleton v-if="loading" :rows="8" animated />
    <template v-else-if="detail">
      <el-descriptions :column="1" border>
        <el-descriptions-item label="来源">
          {{ detail.source.source_type === 'question_pending' ? '公开问答' : '课程意见反馈' }}
        </el-descriptions-item>
        <el-descriptions-item label="课程">{{ detail.course_title }}</el-descriptions-item>
        <el-descriptions-item label="积压">{{ detail.age_label }}</el-descriptions-item>
        <el-descriptions-item label="工作流">{{ detail.workflow_status }}</el-descriptions-item>
        <el-descriptions-item label="首次响应">
          {{ detail.first_response_confirmed ? detail.first_response_kind : '未确认' }}
        </el-descriptions-item>
        <el-descriptions-item label="结果">{{ detail.result_type ?? '—' }}</el-descriptions-item>
        <el-descriptions-item label="来源正文">
          <div class="source-body">{{ detail.source.body }}</div>
        </el-descriptions-item>
      </el-descriptions>

      <el-divider />
      <el-form label-position="top">
        <el-form-item label="主标签">
          <el-select v-model="label" placeholder="选择标签" :disabled="!canManage || !isOpen">
            <el-option label="错误" value="error" />
            <el-option label="缺例子" value="missing_example" />
            <el-option label="资源问题" value="resource_problem" />
            <el-option label="其他" value="other" />
          </el-select>
        </el-form-item>
        <el-form-item label="目标课程">
          <el-input-number v-model="targetCourseId" :min="1" :disabled="!canManage || !isOpen" />
        </el-form-item>
        <el-form-item label="目标章节">
          <el-input-number v-model="targetChapterId" :min="1" :disabled="!canManage || !isOpen" />
        </el-form-item>
        <el-form-item label="目标课节">
          <el-input-number v-model="targetLessonId" :min="1" :disabled="!canManage || !isOpen" />
        </el-form-item>
        <el-button type="primary" :disabled="!canManage || !isOpen" @click="saveTriage">
          保存分诊
        </el-button>
        <el-button :disabled="!detail.target.course_id" @click="openCourseEditor">
          打开课程编辑器
        </el-button>
        <el-form-item label="管理员响应" class="mt-4">
          <el-input
            v-model="responseBody"
            type="textarea"
            :rows="3"
            :disabled="!canManage || !isOpen"
          />
        </el-form-item>
        <el-button :disabled="!canManage || !isOpen" @click="respond">发送响应</el-button>
      </el-form>

      <el-divider />
      <div class="candidate-actions">
        <el-button :disabled="!canManage || !isOpen" @click="generate">生成候选草稿</el-button>
        <el-button v-if="draftCandidate" :disabled="!canManage || !isOpen" @click="saveCandidate">
          保存候选
        </el-button>
        <el-button v-if="draftCandidate" :disabled="!canManage || !isOpen" @click="reject">
          拒绝候选
        </el-button>
      </div>
      <el-form v-if="draftCandidate" label-position="top" class="mt-3">
        <el-form-item :label="`候选 v${draftCandidate.version}`">
          <el-input
            v-model="candidateBody"
            type="textarea"
            :rows="6"
            :disabled="!canManage || !isOpen"
          />
        </el-form-item>
        <el-form-item label="通知对象">
          <el-select v-model="notifyMode" :disabled="!canManage || !isOpen">
            <el-option label="不通知" value="none" />
            <el-option label="仅提交者" value="submitter" />
            <el-option label="当前有权学员" value="enrolled" />
            <el-option label="提交者与有权学员" value="both" />
          </el-select>
        </el-form-item>
        <el-button
          type="success"
          :disabled="
            !canManage ||
            !isOpen ||
            (draftCandidate.target_kind !== 'help_center_candidate' && !canWriteCourse)
          "
          @click="approve"
        >
          {{ draftCandidate.target_kind === 'help_center_candidate' ? '批准并交接' : '批准并写回' }}
        </el-button>
      </el-form>

      <el-divider />
      <el-form label-position="top">
        <el-form-item label="关闭原因">
          <el-select v-model="closeReason" :disabled="!canManage || !isOpen">
            <el-option label="重复" value="duplicate" />
            <el-option label="不可行动" value="not_actionable" />
            <el-option label="已有覆盖" value="already_covered" />
            <el-option label="暂不计划" value="not_planned" />
          </el-select>
        </el-form-item>
        <el-form-item label="关闭说明">
          <el-input
            v-model="closeNote"
            type="textarea"
            :rows="2"
            :disabled="!canManage || !isOpen"
          />
        </el-form-item>
        <el-button
          type="danger"
          plain
          :disabled="!canManage || !isOpen"
          @click="closeWithoutChange"
        >
          无内容变更关闭
        </el-button>
      </el-form>
    </template>
    <el-empty v-else description="请选择内容待办" />
  </el-drawer>
</template>

<style scoped>
.source-body {
  white-space: pre-wrap;
  max-height: 180px;
  overflow: auto;
}
.candidate-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.mt-3 {
  margin-top: 12px;
}
.mt-4 {
  margin-top: 16px;
}
</style>
