import { describe, expect, it } from 'vitest';
import {
  AdminMapDetailDTO,
  CreateMapInput,
  LearnerMapDetailDTO,
} from '../learningMap.js';

const summary = {
  id: 7,
  department_id: 2,
  title: 'Backend growth path',
  summary: 'A focused path',
  cover_url: 'https://cdn.example.test/maps/backend.png',
  objective: 'Build production-ready backend services',
  audience: 'Developers with basic PHP experience',
  status: 'published',
  created_at: '2026-08-25 10:00:00',
  updated_at: '2026-08-25 10:00:00',
} as const;

const stage = {
  id: 11,
  map_id: 7,
  title: 'Foundations',
  summary: null,
  sort_order: 1,
  courses: [{
    map_stage_course_id: 13,
    course_id: 17,
    sort_order: 1,
    available: true,
    viewer_authorized: false,
    completed: false,
    course: {
      id: 17,
      title: 'PHP services',
      teacher_name: 'Teacher',
      cover_url: null,
      status: 'published',
    },
  }],
};

describe('learning map contracts', () => {
  it('accepts cover, objective and audience in map writes', () => {
    const parsed = CreateMapInput.safeParse({
      department_id: 2,
      title: 'Backend growth path',
      cover_url: summary.cover_url,
      objective: summary.objective,
      audience: summary.audience,
    });

    expect(parsed.success).toBe(true);
    if (parsed.success) {
      expect(parsed.data.cover_url).toBe(summary.cover_url);
      expect(parsed.data.objective).toBe(summary.objective);
      expect(parsed.data.audience).toBe(summary.audience);
    }
  });

  it('requires structured publish issues on admin details', () => {
    const parsed = AdminMapDetailDTO.safeParse({
      ...summary,
      stages: [stage],
      publish_issues: [{
        code: 'MAP_HAS_UNPUBLISHED_COURSE',
        stage_id: 11,
        course_id: 17,
      }],
    });

    expect(parsed.success).toBe(true);
  });

  it('requires learner step state and next-step progress', () => {
    const parsed = LearnerMapDetailDTO.safeParse({
      ...summary,
      stages: [stage],
      enrollment: {
        enrolled_at: '2026-08-25 10:00:00',
        completed_courses: 0,
        total_courses: 1,
        progress_percent: 0,
        completed_at: null,
      },
      next_step: {
        map_stage_course_id: 13,
        stage_id: 11,
        course_id: 17,
      },
    });

    expect(parsed.success).toBe(true);
  });
});
