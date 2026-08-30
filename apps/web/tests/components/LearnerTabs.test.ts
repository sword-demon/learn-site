// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import LearnerTabs from '@/components/LearnerTabs.vue';

describe('LearnerTabs', () => {
  const tabs = [
    { key: 'a', label: 'Tab A' },
    { key: 'b', label: 'Tab B' },
  ];

  it('switches active tab on click', async () => {
    const w = mount(LearnerTabs, {
      props: { tabs, modelValue: 'a' },
      slots: { a: '<div>content A</div>', b: '<div>content B</div>' },
    });
    await w.get('[data-tab="a"]').trigger('click');
    expect(w.text()).toContain('content A');
    expect(w.findAll('[data-tab]')).toHaveLength(2);
  });
});
