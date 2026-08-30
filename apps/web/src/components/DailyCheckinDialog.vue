<script setup lang="ts">
import { computed, ref } from 'vue';
import { ElMessage } from 'element-plus';
import CheckinPlanEditor from '@/components/CheckinPlanEditor.vue';
import { createCheckin } from '@/api/checkins';
import { hasRichHtml } from '@/utils/richHtml';

const props = defineProps<{
  modelValue: boolean;
}>();

const emit = defineEmits<{
  'update:modelValue': [value: boolean];
  success: [];
  dismiss: [];
}>();

const planHtml = ref('');
const submitting = ref(false);

const visible = computed({
  get: () => props.modelValue,
  set: (value: boolean) => emit('update:modelValue', value),
});

function close(): void {
  visible.value = false;
  emit('dismiss');
}

async function submit(): Promise<void> {
  if (!hasRichHtml(planHtml.value)) {
    ElMessage.warning('请填写每日计划');
    return;
  }
  submitting.value = true;
  try {
    await createCheckin(planHtml.value);
    planHtml.value = '';
    visible.value = false;
    emit('success');
    ElMessage.success('签到成功');
  } catch (err) {
    const code = (err as { code?: string }).code;
    if (code === 'ALREADY_CHECKED_IN') {
      ElMessage.info('今日已签到');
      visible.value = false;
      emit('success');
      return;
    }
    const message = (err as Error).message;
    if (code === 'VALIDATION_FAILED' && message === 'PLAN_HTML_TOO_LARGE') {
      ElMessage.warning('每日计划内容过长，请精简后重试');
      return;
    }
    if (code === 'VALIDATION_FAILED' && message === 'PLAN_HTML_REQUIRED') {
      ElMessage.warning('请填写每日计划');
      return;
    }
    ElMessage.error('签到失败，请稍后重试');
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <el-dialog
    v-model="visible"
    title="每日签到"
    width="min(640px, 92vw)"
    append-to-body
    :close-on-click-modal="false"
    @close="close"
  >
    <p class="intro">记录今天的学习计划，养成每日打卡习惯。</p>
    <CheckinPlanEditor v-model="planHtml" :disabled="submitting" />
    <template #footer>
      <el-button @click="close">稍后再说</el-button>
      <el-button type="primary" :loading="submitting" @click="submit">完成签到</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.intro {
  margin: 0 0 12px;
  color: var(--ink-soft, #5c6b64);
  font-size: 0.95rem;
}
</style>
