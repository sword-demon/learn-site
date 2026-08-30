import type { PriceMode } from '@learn-site/contracts';

/** Learners with entitlement, or on a free course, may use course Q&A. */
export function canParticipateInCourseQa(course: {
  viewer_authorized: boolean;
  price_mode: PriceMode;
}): boolean {
  return course.viewer_authorized || course.price_mode === 'free';
}
