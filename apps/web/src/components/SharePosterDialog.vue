<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import type { SharePosterDTO } from '@learn-site/contracts';
import { Download } from '@element-plus/icons-vue';

const props = defineProps<{
  modelValue: boolean;
  poster: SharePosterDTO | null;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();
const canvas = ref<HTMLCanvasElement | null>(null);
const renderError = ref(false);
const downloading = ref(false);
const dialogVisible = computed({
  get: () => props.modelValue && props.poster !== null,
  set: (value: boolean) => emit('update:modelValue', value),
});

watch(
  () => [props.modelValue, props.poster] as const,
  async ([open, poster]) => {
    if (!open || !poster) return;
    await nextTick();
    renderError.value = !(await drawPoster(poster));
  },
);

function close(): void {
  emit('update:modelValue', false);
}

async function drawPoster(poster: SharePosterDTO): Promise<boolean> {
  const target = canvas.value;
  const context = target?.getContext('2d');
  if (!target || !context) return false;

  context.fillStyle = '#f5f2e9';
  context.fillRect(0, 0, target.width, target.height);
  context.fillStyle = '#23483b';
  context.fillRect(0, 0, target.width, 20);

  const cover = poster.snapshot.cover_url
    ? await loadImage(poster.snapshot.cover_url).catch(() => null)
    : null;
  if (cover) {
    drawCover(context, cover, 64, 76, 772, 430);
  } else {
    context.fillStyle = '#dfe8e1';
    context.fillRect(64, 76, 772, 430);
    context.fillStyle = '#23483b';
    context.font = '700 72px system-ui, sans-serif';
    context.textAlign = 'center';
    context.fillText(poster.snapshot.title.slice(0, 2), 450, 320);
  }

  context.textAlign = 'left';
  context.fillStyle = '#17241f';
  context.font = '700 54px system-ui, sans-serif';
  let y = drawWrappedText(context, poster.snapshot.title, 64, 590, 772, 70);
  context.fillStyle = '#607068';
  context.font = '400 28px system-ui, sans-serif';
  context.fillText(`讲师 · ${poster.snapshot.teacher_name}`, 64, y + 32);
  context.fillStyle = '#b94f36';
  context.font = '700 42px system-ui, sans-serif';
  context.fillText(poster.snapshot.price_label, 64, y + 96);

  context.strokeStyle = '#ccd5cf';
  context.beginPath();
  context.moveTo(64, 1000);
  context.lineTo(836, 1000);
  context.stroke();
  context.fillStyle = '#23483b';
  context.font = '600 24px system-ui, sans-serif';
  context.fillText('打开课程', 64, 1060);
  context.fillStyle = '#607068';
  context.font = '400 20px ui-monospace, monospace';
  drawWrappedText(context, absoluteUrl(poster.share_url), 64, 1102, 772, 30);
  return true;
}

function drawCover(
  context: CanvasRenderingContext2D,
  image: HTMLImageElement,
  x: number,
  y: number,
  width: number,
  height: number,
): void {
  const scale = Math.max(width / image.naturalWidth, height / image.naturalHeight);
  const sourceWidth = width / scale;
  const sourceHeight = height / scale;
  const sourceX = Math.max(0, (image.naturalWidth - sourceWidth) / 2);
  const sourceY = Math.max(0, (image.naturalHeight - sourceHeight) / 2);
  context.drawImage(image, sourceX, sourceY, sourceWidth, sourceHeight, x, y, width, height);
}

function drawWrappedText(
  context: CanvasRenderingContext2D,
  text: string,
  x: number,
  startY: number,
  maxWidth: number,
  lineHeight: number,
): number {
  const characters = Array.from(text);
  let line = '';
  let y = startY;
  for (const character of characters) {
    const candidate = line + character;
    if (line && context.measureText(candidate).width > maxWidth) {
      context.fillText(line, x, y);
      line = character;
      y += lineHeight;
    } else {
      line = candidate;
    }
  }
  if (line) context.fillText(line, x, y);
  return y;
}

function loadImage(source: string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const image = new Image();
    const url = absoluteUrl(source);
    if (new URL(url).origin !== window.location.origin) image.crossOrigin = 'anonymous';
    image.onload = () => resolve(image);
    image.onerror = () => reject(new Error('POSTER_COVER_LOAD_FAILED'));
    image.src = url;
  });
}

function absoluteUrl(path: string): string {
  return new URL(path, window.location.origin).toString();
}

async function download(): Promise<void> {
  const target = canvas.value;
  if (!target || downloading.value) return;
  downloading.value = true;
  renderError.value = false;
  try {
    const blob = await new Promise<Blob>((resolve, reject) => {
      target.toBlob((value) => {
        if (value) resolve(value);
        else reject(new Error('POSTER_EXPORT_FAILED'));
      }, 'image/png');
    });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = 'course-poster.png';
    anchor.click();
    URL.revokeObjectURL(url);
  } catch {
    renderError.value = true;
  } finally {
    downloading.value = false;
  }
}
</script>

<template>
  <el-dialog
    v-model="dialogVisible"
    class="poster-dialog"
    title="课程海报"
    align-center
    append-to-body
    width="min(440px, calc(100vw - 32px))"
    style="
      max-height: calc(100vh - 40px);
      border: 1px solid var(--line);
      border-radius: 7px;
      background: var(--surface);
      box-shadow: 0 24px 70px rgba(16, 28, 23, 0.28);
    "
  >
    <div v-if="poster" class="poster-body">
      <canvas ref="canvas" width="900" height="1200" aria-label="课程分享海报预览" />
      <el-alert
        v-if="renderError"
        title="海报暂时无法导出，课程链接仍可正常分享。"
        type="error"
        :closable="false"
        show-icon
      />
    </div>
    <template #footer>
      <div class="poster-actions">
        <el-button @click="close">关闭</el-button>
        <el-button type="primary" :icon="Download" :loading="downloading" @click="download">
          下载海报
        </el-button>
      </div>
    </template>
  </el-dialog>
</template>

<style scoped>
.poster-body {
  display: grid;
  gap: 12px;
  max-height: calc(100vh - 164px);
  overflow: auto;
}
canvas {
  display: block;
  width: 100%;
  height: auto;
  margin: 0;
  border: 1px solid var(--line);
}
.poster-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
}
</style>
