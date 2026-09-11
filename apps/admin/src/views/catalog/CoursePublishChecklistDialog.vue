<template>
  <el-dialog
    :model-value="modelValue"
    title="课程发布核验"
    top="6vh"
    :close-on-click-modal="!saving"
    :close-on-press-escape="!saving"
    :show-close="!saving"
    width="min(760px, 94vw)"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <el-skeleton v-if="loading" :rows="5" animated />
    <el-alert v-else-if="error" :title="error" type="error" :closable="false" />
    <div v-else-if="checklist" class="checklist">
      <h3>{{ checklist.course_title }}</h3>
      <p>硬错误 {{ checklist.hard_error_count }} · 警告 {{ checklist.warning_count }}</p>
      <el-alert
        v-for="(finding, index) in checklist.findings"
        :key="index"
        :title="finding.message"
        :description="findingScope(finding)"
        :type="findingAlertType(finding)"
        :closable="false"
        show-icon
      />
      <h4>课程目录</h4>
      <section v-for="chapter in checklist.catalog.chapters" :key="chapter.id">
        <strong>{{ chapter.title }}</strong>
        <el-tag v-if="chapter.status === 'disabled'" type="info">已归档</el-tag>
        <ul>
          <li v-for="lesson in chapter.lessons" :key="lesson.id">
            <span>{{ lesson.title }}</span>
            <el-tag :type="lesson.is_effective ? 'success' : 'info'">{{
              lesson.is_effective ? '有效' : '无效'
            }}</el-tag>
            <el-tag v-if="lesson.is_preview" type="warning">试看</el-tag>
            <span
              >{{ lesson.content_type
              }}<template v-if="lesson.asset_id">
                · 资源 #{{ lesson.asset_id }} ({{ lesson.asset_status }})</template
              ></span
            >
          </li>
        </ul>
      </section>
      <h4>发布影响</h4>
      <dl>
        <dt>学习地图</dt>
        <dd>
          {{ checklist.impact.maps.published_count }} 张已发布地图
          <ul>
            <li v-for="map in checklist.impact.maps.items" :key="map.id">
              {{ map.title }} ·
              {{ map.will_recover_abnormal_step ? '异常步骤将恢复' : '引用本课程' }}
            </li>
          </ul>
        </dd>
        <dt>已有访问权</dt>
        <dd>{{ checklist.impact.entitlements.active_count }} 名学员</dd>
        <dt>学习进度</dt>
        <dd>
          {{ checklist.impact.progress.current_denominator }} →
          {{ checklist.impact.progress.next_denominator }} 个有效课节；已完成课节保留
        </dd>
      </dl>
    </div>
    <p v-if="checklist" class="notification-impact">
      <strong>课程发布消息：</strong>
      <span v-if="checklist.impact.notification.recipient_unavailable">在册学员人数暂不可用</span>
      <span v-else
        >在册学员 {{ checklist.impact.notification.recipient_count }} 人；{{
          checklist.impact.notification.will_dispatch ? '发布后将通知全体在册学员' : '本次不通知'
        }}</span
      >
    </p>
    <el-checkbox
      v-if="
        checklist && (checklist.warning_count > 0 || checklist.impact.notification.will_dispatch)
      "
      v-model="acknowledged"
      >我已知晓以上警告与通知影响</el-checkbox
    >
    <el-alert v-if="publishError" :title="publishError" type="error" :closable="false" />
    <template #footer>
      <el-button :disabled="saving" @click="emit('update:modelValue', false)">取消</el-button>
      <el-button type="primary" :disabled="!canConfirm" :loading="saving" @click="confirmPublish"
        >确认发布</el-button
      >
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { PublishChecklistDTO } from '@learn-site/contracts';
import { fetchPublishChecklist, publishCourse } from '@/api/catalog';

const props = defineProps<{ modelValue: boolean; courseId: number }>();
const emit = defineEmits<{ 'update:modelValue': [value: boolean]; published: [] }>();

function findingScope(finding: { lesson_id?: number | null; chapter_id?: number | null }): string {
  if (finding.lesson_id) return `课节 #${finding.lesson_id}`;
  if (finding.chapter_id) return `章节 #${finding.chapter_id}`;
  return '课程';
}

function findingAlertType(finding: {
  severity: 'hard' | 'warning' | 'info';
}): 'error' | 'warning' | 'info' {
  return finding.severity === 'hard' ? 'error' : finding.severity;
}
const checklist = ref<PublishChecklistDTO | null>(null);
const loading = ref(false);
const error = ref('');
const publishError = ref('');
const saving = ref(false);
const acknowledged = ref(false);
const canConfirm = computed(
  () =>
    !loading.value &&
    !saving.value &&
    checklist.value?.can_publish === true &&
    !checklist.value.impact.notification.recipient_unavailable &&
    checklist.value.impact.notification.recipient_count !== null &&
    (checklist.value.warning_count === 0 || acknowledged.value),
);
async function confirmPublish(): Promise<void> {
  if (!canConfirm.value) return;
  saving.value = true;
  publishError.value = '';
  try {
    await publishCourse(props.courseId, { acknowledge_warnings: acknowledged.value });
    emit('published');
    emit('update:modelValue', false);
  } catch (err: unknown) {
    const data = (err as { response?: { data?: { error?: { checklist?: unknown } } } }).response
      ?.data?.error?.checklist;
    const parsed = PublishChecklistDTO.safeParse(data);
    if (parsed.success) {
      checklist.value = parsed.data;
      acknowledged.value = false;
    }
    publishError.value = parsed.success ? '课程内容已重新核验，请检查最新结果' : '发布失败，请重试';
  } finally {
    saving.value = false;
  }
}
watch(
  () => [props.modelValue, props.courseId] as const,
  async ([open, id], _, onCleanup) => {
    let active = true;
    onCleanup(() => {
      active = false;
    });
    checklist.value = null;
    acknowledged.value = false;
    publishError.value = '';
    if (!open) return;
    loading.value = true;
    error.value = '';
    try {
      const result = await fetchPublishChecklist(id);
      if (active) checklist.value = result;
    } catch {
      if (active) error.value = '发布核验加载失败，请关闭后重试';
    } finally {
      if (active) loading.value = false;
    }
  },
  { immediate: true },
);
</script>

<style scoped>
.checklist {
  max-height: max(160px, calc(88vh - 230px));
  overflow-y: auto;
  overflow-wrap: anywhere;
}
.notification-impact {
  margin: 16px 0 8px;
  overflow-wrap: anywhere;
}
.checklist :deep(.el-alert) {
  margin: 8px 0;
}
h3 {
  font-size: 18px;
}
h4 {
  font-size: 15px;
  margin: 20px 0 12px;
}
li {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin: 8px 0;
}
dl {
  display: grid;
  grid-template-columns: 100px minmax(0, 1fr);
  gap: 12px;
}
dd {
  margin: 0;
}
</style>
