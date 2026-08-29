<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import type { SharePosterDTO } from '@learn-site/contracts';

const props = defineProps<{
  modelValue: boolean;
  poster: SharePosterDTO | null;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();
const canvas = ref<HTMLCanvasElement | null>(null);
const renderError = ref(false);
const downloading = ref(false);

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
  <div v-if="modelValue && poster" class="poster-overlay" role="presentation" @click.self="close">
    <section class="poster-dialog" role="dialog" aria-modal="true" aria-labelledby="poster-title">
      <header class="poster-head">
        <h2 id="poster-title">课程海报</h2>
        <button type="button" class="icon-button" aria-label="关闭海报" title="关闭" @click="close">
          ×
        </button>
      </header>
      <canvas ref="canvas" width="900" height="1200" aria-label="课程分享海报预览" />
      <p v-if="renderError" class="poster-error">海报暂时无法导出，课程链接仍可正常分享。</p>
      <footer class="poster-actions">
        <button type="button" class="poster-button secondary" @click="close">关闭</button>
        <button
          type="button"
          class="poster-button primary"
          :disabled="downloading"
          @click="download"
        >
          {{ downloading ? '导出中…' : '下载海报' }}
        </button>
      </footer>
    </section>
  </div>
</template>

<style scoped>
.poster-overlay {
  position: fixed;
  z-index: 60;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 20px;
  background: rgba(16, 28, 23, 0.62);
}
.poster-dialog {
  width: min(440px, 100%);
  max-height: calc(100vh - 40px);
  overflow: auto;
  border: 1px solid var(--line);
  border-radius: 7px;
  background: var(--surface);
  box-shadow: 0 24px 70px rgba(16, 28, 23, 0.28);
}
.poster-head,
.poster-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
}
.poster-head {
  border-bottom: 1px solid var(--line);
}
.poster-head h2 {
  margin: 0;
  color: var(--pine-deep);
  font-size: 1rem;
}
.icon-button {
  width: 32px;
  height: 32px;
  padding: 0;
  border: 0;
  background: transparent;
  color: var(--muted);
  font-size: 1.5rem;
  line-height: 1;
  cursor: pointer;
}
canvas {
  display: block;
  width: calc(100% - 32px);
  height: auto;
  margin: 16px;
  border: 1px solid var(--line);
}
.poster-actions {
  justify-content: flex-end;
  border-top: 1px solid var(--line);
}
.poster-button {
  min-height: 38px;
  padding: 8px 14px;
  border-radius: 5px;
  font: inherit;
  cursor: pointer;
}
.poster-button.secondary {
  border: 1px solid var(--line);
  background: var(--surface);
  color: var(--pine-deep);
}
.poster-button.primary {
  border: 1px solid var(--pine);
  background: var(--pine);
  color: white;
}
.poster-button:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}
.poster-error {
  margin: 0 16px 14px;
  color: #9e3f2c;
  font-size: 0.82rem;
}
</style>
