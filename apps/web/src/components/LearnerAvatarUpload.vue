<template>
  <div class="avatar-upload" data-testid="learner-avatar-upload">
    <div class="avatar-seal" aria-hidden="true">
      <img
        v-if="avatarUrl"
        :src="avatarUrl"
        alt=""
        class="avatar-seal__photo"
        data-role="avatar-preview"
      />
      <span v-else class="avatar-seal__char">{{ initial }}</span>
      <span class="avatar-seal__stamp">学</span>
    </div>
    <div class="avatar-actions">
      <el-upload
        :auto-upload="true"
        :show-file-list="false"
        :disabled="busy"
        accept="image/jpeg,image/png,image/webp"
        :before-upload="beforeUpload"
        :http-request="onUpload"
      >
        <el-button type="primary" :loading="uploading">
          {{ avatarUrl ? '更换头像' : '上传头像' }}
        </el-button>
      </el-upload>
      <el-button
        v-if="avatarUrl"
        link
        data-role="clear-avatar"
        :disabled="busy"
        :loading="removing"
        @click="onRemove"
      >
        移除头像
      </el-button>
      <p class="avatar-hint">JPEG、PNG 或 WebP，最大 5 MiB。上传后立即生效。</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { ElMessage, type UploadRequestOptions } from 'element-plus';
import type { LearnerProfileDTO } from '@learn-site/contracts';
import { deleteLearnerAvatar, uploadLearnerAvatar } from '@/api/learner';

const MAX_BYTES = 5 * 1024 * 1024;
const ALLOWED_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp']);

defineProps<{
  avatarUrl: string | null;
  initial: string;
}>();
const emit = defineEmits<{
  updated: [profile: LearnerProfileDTO];
}>();

const uploading = ref(false);
const removing = ref(false);
const busy = computed(() => uploading.value || removing.value);

function beforeUpload(file: File): boolean {
  if (!ALLOWED_TYPES.has(file.type)) {
    ElMessage.error('请选择 JPEG、PNG 或 WebP 图片');
    return false;
  }
  if (file.size <= 0 || file.size > MAX_BYTES) {
    ElMessage.error('图片不能超过 5 MiB');
    return false;
  }
  return true;
}

async function onUpload(request: UploadRequestOptions): Promise<void> {
  uploading.value = true;
  try {
    const profile = await uploadLearnerAvatar(request.file);
    emit('updated', profile);
    request.onSuccess(profile);
    ElMessage.success('头像已更新');
  } catch (error) {
    request.onError(error as Parameters<typeof request.onError>[0]);
    ElMessage.error('头像上传失败，请稍后重试');
  } finally {
    uploading.value = false;
  }
}

async function onRemove(): Promise<void> {
  removing.value = true;
  try {
    const profile = await deleteLearnerAvatar();
    emit('updated', profile);
    ElMessage.success('头像已移除');
  } catch {
    ElMessage.error('头像移除失败，请稍后重试');
  } finally {
    removing.value = false;
  }
}
</script>

<style scoped>
.avatar-upload {
  display: flex;
  align-items: center;
  gap: 16px;
}

.avatar-seal {
  position: relative;
  width: 88px;
  height: 88px;
  flex-shrink: 0;
  border-radius: 6px;
  background: var(--seal);
  color: #fff5f1;
  display: grid;
  place-items: center;
  box-shadow:
    inset 0 0 0 1px rgba(255, 255, 255, 0.18),
    inset 0 0 14px rgba(74, 8, 12, 0.45);
}

.avatar-seal__photo {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  border-radius: 6px;
}

.avatar-seal__char {
  font-family: var(--serif);
  font-size: 36px;
  font-weight: 600;
  letter-spacing: 0.02em;
}

.avatar-seal__stamp {
  position: absolute;
  z-index: 1;
  right: -6px;
  bottom: -6px;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: var(--card);
  color: var(--seal);
  border: 1px solid var(--seal);
  display: grid;
  place-items: center;
  font-family: var(--serif);
  font-size: 11px;
  font-weight: 600;
  box-shadow: 0 2px 4px rgba(40, 30, 32, 0.15);
}

.avatar-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px 12px;
  min-width: 0;
}

.avatar-hint {
  flex: 1 1 100%;
  margin: 0;
  color: var(--muted);
  font-size: 12px;
  line-height: 1.5;
}

@media (max-width: 760px) {
  .avatar-upload {
    align-items: flex-start;
  }
}
</style>
