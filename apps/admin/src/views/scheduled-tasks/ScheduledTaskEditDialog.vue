<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { AdminScheduledTaskDTO } from '@learn-site/contracts';
import { updateScheduledTask, validateScheduleExpression } from '@/api/scheduledTasks';

const props = defineProps<{
  task: AdminScheduledTaskDTO;
}>();

const open = defineModel<boolean>({ required: true });

const emit = defineEmits<{
  saved: [];
}>();

const expression = ref('');
const enabled = ref(true);
const batchSize = ref(500);
const previewNext = ref<string | null>(null);
const previewError = ref('');
const saving = ref(false);
const previewing = ref(false);
const formError = ref('');

const readonly = computed(() => props.task.handler_status !== 'available');

watch(
  () => props.task,
  (task) => {
    expression.value = task.schedule_expression;
    enabled.value = task.enabled;
    const params = task.params as { batch_size?: number } | null;
    batchSize.value = params?.batch_size ?? 500;
    previewNext.value = task.next_run_at;
    previewError.value = '';
    formError.value = '';
  },
  { immediate: true },
);

async function preview(): Promise<void> {
  previewing.value = true;
  previewError.value = '';
  try {
    const result = await validateScheduleExpression(expression.value);
    if (!result.valid) {
      previewNext.value = null;
      previewError.value = result.error ?? '表达式无效';
      return;
    }
    previewNext.value = result.next_run_at;
  } catch (err) {
    previewError.value = (err as Error).message || '预览失败';
  } finally {
    previewing.value = false;
  }
}

async function save(): Promise<void> {
  saving.value = true;
  formError.value = '';
  try {
    await updateScheduledTask(props.task.id, {
      schedule_expression: expression.value,
      enabled: enabled.value,
      params: { batch_size: batchSize.value },
    });
    emit('saved');
  } catch (err) {
    formError.value = (err as Error).message || '保存失败';
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <el-dialog v-model="open" :title="`编辑任务：${task.name}`" width="560px" destroy-on-close>
    <el-alert
      v-if="readonly"
      type="warning"
      title="该任务处理器不可用，仅可查看。"
      show-icon
      class="mb-4"
    />
    <el-alert v-if="formError" type="error" :title="formError" show-icon class="mb-4" />

    <el-form label-width="120px">
      <el-form-item label="调度表达式">
        <el-input v-model="expression" :disabled="readonly" placeholder="0 30 3 * * *" />
      </el-form-item>
      <el-form-item label="下次执行">
        <div class="preview-row">
          <span>{{ previewNext ?? '—' }}</span>
          <el-button :loading="previewing" :disabled="readonly" @click="preview">预览</el-button>
        </div>
        <p v-if="previewError" class="hint error">{{ previewError }}</p>
        <p class="hint">六段式：秒 分 时 日 月 周；最短间隔 60 秒。</p>
      </el-form-item>
      <el-form-item label="启用">
        <el-switch v-model="enabled" :disabled="readonly" />
      </el-form-item>
      <el-form-item v-if="task.handler_code === 'notification.cleanup'" label="批处理大小">
        <el-input-number v-model="batchSize" :min="1" :max="2000" :disabled="readonly" />
      </el-form-item>
    </el-form>

    <template #footer>
      <el-button @click="open = false">取消</el-button>
      <el-button type="primary" :loading="saving" :disabled="readonly" @click="save"
        >保存</el-button
      >
    </template>
  </el-dialog>
</template>

<style scoped>
.mb-4 {
  margin-bottom: 16px;
}
.preview-row {
  display: flex;
  align-items: center;
  gap: 12px;
}
.hint {
  margin: 6px 0 0;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
.hint.error {
  color: var(--el-color-danger);
}
</style>
