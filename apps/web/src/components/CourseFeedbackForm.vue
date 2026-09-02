<script setup lang="ts">
import { ref } from 'vue';
import type { CourseFeedbackCreatedDTO } from '@learn-site/contracts';
import { submitCourseFeedback } from '@/api/courseFeedback';
import CheckinPlanEditor from '@/components/CheckinPlanEditor.vue';
import { hasRichHtml } from '@/utils/richHtml';

const BODY_MAX = 20_000;

const props = defineProps<{
  courseId: number;
}>();

const emit = defineEmits<{
  success: [feedback: CourseFeedbackCreatedDTO];
}>();

const bodyHtml = ref('');
const submitting = ref(false);
const errorMessage = ref<string | null>(null);
const successMessage = ref<string | null>(null);

function errorKeys(error: unknown): string[] {
  const keys: string[] = [];
  if (error instanceof Error && error.message) keys.push(error.message);
  if (typeof error === 'object' && error !== null && 'code' in error) {
    const code = (error as { code?: unknown }).code;
    if (typeof code === 'string') keys.push(code);
  }
  return keys;
}

function understandableError(error: unknown): string {
  const messages: Record<string, string> = {
    FEEDBACK_BODY_REQUIRED: '请填写反馈内容。',
    FEEDBACK_BODY_TOO_LONG: '反馈内容不能超过 20000 个字符。',
    COURSE_ACCESS_REQUIRED: '取得课程访问权后才能提交反馈。',
    FORBIDDEN: '取得课程访问权后才能提交反馈。',
    COURSE_NOT_FOUND: '课程不存在或当前不可访问。',
    NOT_FOUND: '课程不存在或当前不可访问。',
    UNAUTHENTICATED: '登录后才能提交反馈。',
    TOKEN_EXPIRED: '登录状态已过期，请重新登录。',
    TOKEN_REVOKED: '登录状态已失效，请重新登录。',
    RATE_LIMITED: '提交过于频繁，请稍后再试。',
  };
  for (const key of errorKeys(error)) {
    const message = messages[key];
    if (message) return message;
  }
  return '反馈提交失败，请稍后再试。';
}

function validate(): string | null {
  if (bodyHtml.value.length > BODY_MAX) return '反馈内容不能超过 20000 个字符。';
  if (!hasRichHtml(bodyHtml.value)) return '请填写反馈内容。';
  return null;
}

async function submit(): Promise<void> {
  if (submitting.value) return;

  successMessage.value = null;
  errorMessage.value = validate();
  if (errorMessage.value) return;

  submitting.value = true;
  try {
    const feedback = await submitCourseFeedback(props.courseId, bodyHtml.value);
    bodyHtml.value = '';
    successMessage.value = '反馈已提交。';
    emit('success', feedback);
  } catch (error) {
    errorMessage.value = understandableError(error);
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <el-form class="course-feedback-form" label-position="top" @submit.prevent="submit">
    <el-form-item label="反馈内容">
      <CheckinPlanEditor
        v-model="bodyHtml"
        placeholder="请填写课程内容或学习体验方面的反馈"
        :disabled="submitting"
      />
    </el-form-item>

    <el-alert
      v-if="errorMessage"
      class="feedback-status"
      :title="errorMessage"
      type="error"
      :closable="false"
      show-icon
    />
    <el-alert
      v-else-if="successMessage"
      class="feedback-status"
      :title="successMessage"
      type="success"
      :closable="false"
      show-icon
    />

    <div class="feedback-actions">
      <el-button
        type="primary"
        native-type="submit"
        :loading="submitting"
        :disabled="submitting"
        data-testid="submit-feedback"
        @click="submit"
      >
        提交反馈
      </el-button>
    </div>
  </el-form>
</template>

<style scoped>
.course-feedback-form {
  width: 100%;
}

.feedback-status {
  margin-top: 12px;
}

.feedback-actions {
  display: flex;
  justify-content: flex-end;
  margin-top: 16px;
}
</style>
