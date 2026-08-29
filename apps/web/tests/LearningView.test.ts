import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';

const learnerApi = vi.hoisted(() => ({
  reportLessonProgress: vi.fn(),
  reportVideoHeartbeat: vi.fn(),
}));

vi.mock('@/api/learner', () => learnerApi);

import { useLearningProgress } from '@/composables/useLearningProgress';

describe('useLearningProgress', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    learnerApi.reportLessonProgress.mockResolvedValue({
      lesson_id: 12,
      position_seconds: 1,
      completed: false,
      completed_at: null,
      opened_at: '2026-08-28 10:00:00',
    });
    learnerApi.reportVideoHeartbeat.mockResolvedValue({
      lesson_id: 12,
      position_seconds: 90,
      completed: true,
      completed_at: '2026-08-28 10:01:00',
      opened_at: '2026-08-28 10:00:00',
    });
  });

  it('uses the document progress contract for opening and completion', async () => {
    const progress = useLearningProgress(ref(12));

    await progress.reportDocumentOpen('markdown');
    expect(learnerApi.reportLessonProgress).toHaveBeenCalledWith(12, {
      content_type: 'markdown',
      position_seconds: 1,
    });

    await progress.completeDocument('markdown');
    expect(learnerApi.reportLessonProgress).toHaveBeenLastCalledWith(12, {
      content_type: 'markdown',
      position_seconds: 1,
      completed: true,
    });
    expect(progress.progress.value?.opened_at).toBe('2026-08-28 10:00:00');
    expect(progress.pending.value).toBe(false);
  });

  it('uses the video-only heartbeat endpoint and exposes completion', async () => {
    const progress = useLearningProgress(ref(12));

    const result = await progress.heartbeat(90.8, 100.9);

    expect(learnerApi.reportVideoHeartbeat).toHaveBeenCalledWith(12, 90.8, 100.9);
    expect(result.completed).toBe(true);
    expect(progress.progress.value?.completed).toBe(true);
  });

  it('retains a stable error state while allowing a later retry', async () => {
    learnerApi.reportLessonProgress.mockRejectedValueOnce(new Error('network down'));
    const progress = useLearningProgress(ref(12));

    await expect(progress.completeDocument('pdf')).rejects.toThrow('network down');
    expect(progress.error.value).toBe('network down');
    expect(progress.pending.value).toBe(false);

    await progress.reportDocumentOpen('pdf');
    expect(progress.error.value).toBeNull();
  });
});
