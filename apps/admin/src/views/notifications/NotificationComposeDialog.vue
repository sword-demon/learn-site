<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { listLearners } from '@/api/learners';
import { sendAnnouncement, sendInternalMessage } from '@/api/notifications';

defineOptions({ name: 'NotificationComposeDialog' });

const props = defineProps<{
  modelValue: boolean;
}>();

const emit = defineEmits<{
  'update:modelValue': [value: boolean];
  sent: [];
}>();

const mode = ref<'announcement' | 'internal_message'>('announcement');
const title = ref('');
const body = ref('');
const learnerIds = ref<number[]>([]);
const learnerOptions = ref<Array<{ id: number; label: string }>>([]);
const loadingLearners = ref(false);
const submitting = ref(false);
const errorMessage = ref('');

const visible = computed({
  get: () => props.modelValue,
  set: (value: boolean) => emit('update:modelValue', value),
});

const form = reactive({ search: '' });

async function loadLearners(): Promise<void> {
  loadingLearners.value = true;
  try {
    const result = await listLearners({
      status: 'active',
      page: 1,
      limit: 50,
      ...(form.search ? { search: form.search } : {}),
    });
    learnerOptions.value = result.items.map((item) => ({
      id: item.account_id,
      label: item.display_name ? `${item.display_name} (${item.login})` : item.login,
    }));
  } finally {
    loadingLearners.value = false;
  }
}

function resetForm(): void {
  mode.value = 'announcement';
  title.value = '';
  body.value = '';
  learnerIds.value = [];
  errorMessage.value = '';
}

async function onOpen(): Promise<void> {
  resetForm();
  await loadLearners();
}

async function submit(): Promise<void> {
  submitting.value = true;
  errorMessage.value = '';
  try {
    if (mode.value === 'announcement') {
      await sendAnnouncement({ title: title.value, body: body.value });
    } else {
      await sendInternalMessage({
        title: title.value,
        body: body.value,
        learner_ids: learnerIds.value,
      });
    }
    visible.value = false;
    emit('sent');
  } catch (err) {
    errorMessage.value = (err as Error).message || '发送失败';
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <el-dialog v-model="visible" title="发送通知" width="640px" destroy-on-close @open="onOpen">
    <el-form label-position="top">
      <el-form-item label="类型">
        <el-radio-group v-model="mode">
          <el-radio-button value="announcement">公告</el-radio-button>
          <el-radio-button value="internal_message">站内信</el-radio-button>
        </el-radio-group>
      </el-form-item>
      <el-form-item label="标题">
        <el-input v-model="title" maxlength="200" show-word-limit />
      </el-form-item>
      <el-form-item label="正文">
        <el-input v-model="body" type="textarea" :rows="6" maxlength="10000" show-word-limit />
      </el-form-item>
      <el-form-item v-if="mode === 'internal_message'" label="收件学员">
        <div class="learner-picker">
          <el-input
            v-model="form.search"
            placeholder="搜索手机号或昵称"
            @keyup.enter="loadLearners"
          />
          <el-button :loading="loadingLearners" @click="loadLearners">搜索</el-button>
        </div>
        <el-select
          v-model="learnerIds"
          multiple
          filterable
          collapse-tags
          collapse-tags-tooltip
          placeholder="选择学员"
          style="width: 100%; margin-top: 8px"
        >
          <el-option
            v-for="option in learnerOptions"
            :key="option.id"
            :label="option.label"
            :value="option.id"
          />
        </el-select>
      </el-form-item>
      <p v-if="errorMessage" class="error">{{ errorMessage }}</p>
    </el-form>
    <template #footer>
      <el-button @click="visible = false">取消</el-button>
      <el-button type="primary" :loading="submitting" @click="submit">发送</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.learner-picker {
  display: flex;
  gap: 8px;
}
.error {
  color: var(--el-color-danger);
  margin: 0;
}
</style>
