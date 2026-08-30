// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import DailyCheckinDialog from '@/components/DailyCheckinDialog.vue';

const checkinsApi = vi.hoisted(() => ({
  createCheckin: vi.fn(),
}));

vi.mock('@/api/checkins', () => checkinsApi);
vi.mock('@/components/CheckinPlanEditor.vue', () => ({
  default: {
    name: 'CheckinPlanEditor',
    props: ['modelValue'],
    emits: ['update:modelValue'],
    template:
      '<button type="button" data-testid="editor-stub" @click="$emit(\'update:modelValue\', \'<p>计划</p>\')">fill</button>',
  },
}));

const dialogStub = {
  template:
    '<div class="dialog-stub"><slot /><div class="footer"><slot name="footer" /></div></div>',
  props: ['modelValue'],
};

describe('DailyCheckinDialog', () => {
  it('emits dismiss when closed without submitting', async () => {
    const wrapper = mount(DailyCheckinDialog, {
      props: { modelValue: true },
      global: {
        stubs: { ElDialog: dialogStub },
      },
    });
    const laterBtn = wrapper.findAll('button').find((btn) => btn.text().includes('稍后再说'));
    expect(laterBtn).toBeTruthy();
    await laterBtn!.trigger('click');
    expect(wrapper.emitted('dismiss')).toBeTruthy();
  });

  it('submits plan and emits success', async () => {
    checkinsApi.createCheckin.mockResolvedValue({
      id: 1,
      checkin_date: '2026-08-30',
      plan_html: '<p>计划</p>',
      checked_in_at: '2026-08-30T09:00:00+08:00',
    });
    const wrapper = mount(DailyCheckinDialog, {
      props: { modelValue: true },
      global: {
        stubs: { ElDialog: dialogStub },
      },
    });
    await wrapper.find('[data-testid="editor-stub"]').trigger('click');
    const submitBtn = wrapper.findAll('button').find((btn) => btn.text().includes('完成签到'));
    expect(submitBtn).toBeTruthy();
    await submitBtn!.trigger('click');
    await flushPromises();
    expect(checkinsApi.createCheckin).toHaveBeenCalledWith('<p>计划</p>');
    expect(wrapper.emitted('success')).toBeTruthy();
  });
});
