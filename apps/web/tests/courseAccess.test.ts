import { describe, expect, it } from 'vitest';
import { canParticipateInCourseQa } from '@/utils/courseAccess';

describe('canParticipateInCourseQa', () => {
  it('allows entitled learners on paid courses', () => {
    expect(
      canParticipateInCourseQa({ viewer_authorized: true, price_mode: 'paid' }),
    ).toBe(true);
  });

  it('allows learners on free courses without entitlement', () => {
    expect(
      canParticipateInCourseQa({ viewer_authorized: false, price_mode: 'free' }),
    ).toBe(true);
  });

  it('blocks paid courses without entitlement', () => {
    expect(
      canParticipateInCourseQa({ viewer_authorized: false, price_mode: 'paid' }),
    ).toBe(false);
  });
});
