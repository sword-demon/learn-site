import { ref } from 'vue';
import { describe, expect, it } from 'vitest';
import type { LearnerMapDetailDTO } from '@learn-site/contracts';
import { useMapLearningState } from '@/composables/useMapLearningState';

function makeMap(
  stages: Array<{ id: number; courses: Array<{ id: number; completed: boolean }> }>,
): LearnerMapDetailDTO {
  return {
    id: 1,
    department_id: 1,
    title: 'test',
    summary: null,
    cover_url: null,
    objective: null,
    audience: null,
    status: 'published',
    created_at: '2026-08-31T00:00:00Z',
    updated_at: '2026-08-31T00:00:00Z',
    stages: stages.map((s, idx) => ({
      id: s.id,
      map_id: 1,
      title: `stage-${idx}`,
      summary: null,
      sort_order: idx,
      courses: s.courses.map((c) => ({
        map_stage_course_id: c.id,
        course_id: c.id,
        sort_order: 0,
        available: true,
        viewer_authorized: false,
        completed: c.completed,
        course: null,
      })),
    })),
    enrollment: null,
    next_step: null,
  };
}

describe('useMapLearningState', () => {
  it('returns [] when map is null', () => {
    const m = ref<LearnerMapDetailDTO | null>(null);
    const { stageStates } = useMapLearningState(m);
    expect(stageStates.value).toEqual([]);
  });

  it('returns [completed, completed, active] when first two stages fully completed', () => {
    const m = ref(
      makeMap([
        { id: 1, courses: [{ id: 1, completed: true }] },
        { id: 2, courses: [{ id: 2, completed: true }] },
        { id: 3, courses: [{ id: 3, completed: false }] },
      ]),
    );
    const { stageStates } = useMapLearningState(m);
    expect(stageStates.value).toEqual(['completed', 'completed', 'active']);
  });

  it('returns [active, active, active] when nothing completed', () => {
    const m = ref(
      makeMap([
        { id: 1, courses: [{ id: 1, completed: false }] },
        { id: 2, courses: [{ id: 2, completed: false }] },
        { id: 3, courses: [{ id: 3, completed: false }] },
      ]),
    );
    const { stageStates } = useMapLearningState(m);
    expect(stageStates.value).toEqual(['active', 'active', 'active']);
  });
});
