import { ref, type Ref } from 'vue';
import type { LessonProgressDTO, LessonProgressReportDTO } from '@learn-site/contracts';
import { reportLessonProgress, reportVideoHeartbeat } from '@/api/learner';

/**
 * Shared learner progress commands. The lesson view owns rendering and
 * playback events; this composable owns request shape and transient state.
 */
export function useLearningProgress(lessonId: Readonly<Ref<number>>) {
  const progress = ref<LessonProgressDTO | null>(null);
  const pending = ref(false);
  const error = ref<string | null>(null);

  async function reportDocumentOpen(contentType: 'markdown' | 'pdf'): Promise<LessonProgressDTO> {
    return submit({ content_type: contentType, position_seconds: 1 });
  }

  async function completeDocument(contentType: 'markdown' | 'pdf'): Promise<LessonProgressDTO> {
    return submit({ content_type: contentType, position_seconds: 1, completed: true });
  }

  async function heartbeat(
    positionSeconds: number,
    durationSeconds: number,
  ): Promise<LessonProgressDTO> {
    pending.value = true;
    error.value = null;
    try {
      const result = await reportVideoHeartbeat(lessonId.value, positionSeconds, durationSeconds);
      progress.value = result;
      return result;
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'PROGRESS_FAILED';
      throw err;
    } finally {
      pending.value = false;
    }
  }

  async function submit(body: LessonProgressReportDTO): Promise<LessonProgressDTO> {
    pending.value = true;
    error.value = null;
    try {
      const result = await reportLessonProgress(lessonId.value, body);
      progress.value = result;
      return result;
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'PROGRESS_FAILED';
      throw err;
    } finally {
      pending.value = false;
    }
  }

  return { progress, pending, error, reportDocumentOpen, completeDocument, heartbeat };
}
