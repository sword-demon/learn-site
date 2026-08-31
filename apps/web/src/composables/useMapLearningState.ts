import { computed, type Ref } from 'vue';
import type { LearnerMapDetailDTO } from '@learn-site/contracts';

export type StageState = 'completed' | 'active' | 'locked';

// ponytail: linear progression — first incomplete stage is active, later stages are locked.
// ponytail: when nothing has been completed yet, every stage is active (no gating on fresh start).
// ponytail: stages with no courses are skipped through.
// Upgrade when product requires strict gating from stage 1 onward.
export function useMapLearningState(map: Ref<LearnerMapDetailDTO | null>) {
  const stageStates = computed<StageState[]>(() => {
    if (!map.value) return [];
    const stages = map.value.stages;
    if (stages.length === 0) return [];

    const anyCompleted = stages.some(
      (s) => s.courses.length > 0 && s.courses.every((c) => c.completed),
    );

    if (!anyCompleted) {
      return stages.map(() => 'active' as const);
    }

    const firstIncompleteIdx = stages.findIndex(
      (s) => s.courses.length === 0 || !s.courses.every((c) => c.completed),
    );

    return stages.map((stage, idx) => {
      const courses = stage.courses;
      if (courses.length > 0 && courses.every((c) => c.completed)) {
        return 'completed' as const;
      }
      if (idx === firstIncompleteIdx) return 'active' as const;
      if (idx < firstIncompleteIdx) return 'active' as const;
      return 'locked' as const;
    });
  });

  return { stageStates };
}
