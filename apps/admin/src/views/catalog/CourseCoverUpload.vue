<template>
  <div class="cover-upload">
    <div
      v-if="modelValue"
      class="cover-preview"
    >
      <el-image
        :src="modelValue"
        fit="contain"
        class="cover-image"
      />
      <el-button
        link
        data-role="clear-cover"
        :disabled="uploading"
        @click="clear"
      >
        清除封面
      </el-button>
    </div>
    <el-upload
      :auto-upload="true"
      :show-file-list="false"
      :disabled="uploading"
      accept="image/jpeg,image/png,image/webp"
      :http-request="onUpload"
    >
      <el-button
        type="primary"
        :loading="uploading"
      >
        {{ modelValue ? '替换图片' : '选择图片' }}
      </el-button>
    </el-upload>
    <span class="cover-hint">JPEG、PNG 或 WebP，最大 5 MiB</span>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { ElMessage, type UploadRequestOptions } from 'element-plus'
import type { UploadCoverInput, UploadCoverResult } from '@/api/covers'

export type CoverUploadHandler = (input: UploadCoverInput) => Promise<UploadCoverResult>

const props = defineProps<{
  modelValue: string
  upload: CoverUploadHandler
}>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
const uploading = ref(false)

async function onUpload(request: UploadRequestOptions): Promise<void> {
  uploading.value = true
  try {
    const result = await props.upload({ file: request.file })
    emit('update:modelValue', result.url)
    request.onSuccess(result)
    ElMessage.success('封面上传成功')
  } catch (error) {
    request.onError(error as Parameters<typeof request.onError>[0])
    ElMessage.error('封面上传失败')
  } finally {
    uploading.value = false
  }
}

function clear(): void {
  emit('update:modelValue', '')
}
</script>

<style scoped>
.cover-upload {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
}
.cover-preview {
  display: flex;
  align-items: center;
  gap: 10px;
}
.cover-image {
  width: 160px;
  height: 90px;
  border: 1px solid #dbe3ef;
  border-radius: 6px;
  background: #f8fafc;
}
.cover-hint {
  color: #64748b;
  font-size: 12px;
}
</style>
