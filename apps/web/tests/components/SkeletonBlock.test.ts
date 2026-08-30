// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import SkeletonBlock from '@/components/SkeletonBlock.vue';

describe('SkeletonBlock', () => {
  it('renders 3 rows by default', () => {
    const w = mount(SkeletonBlock);
    expect(w.findAll('[data-testid="skeleton-row"]')).toHaveLength(3);
  });
  it('respects rows prop', () => {
    const w = mount(SkeletonBlock, { props: { rows: 6 } });
    expect(w.findAll('[data-testid="skeleton-row"]')).toHaveLength(6);
  });
});
