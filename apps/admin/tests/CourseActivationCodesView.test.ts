// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { ElMessageBox } from 'element-plus';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installElementPlus } from '@/plugins/element-plus';

const codesApi = vi.hoisted(() => ({
  createActivationCodeBatch: vi.fn(),
  listActivationCodes: vi.fn(),
  voidActivationCode: vi.fn(),
}));
const routerApi = vi.hoisted(() => ({ push: vi.fn(), replace: vi.fn() }));

vi.mock('@/api/activationCodes', () => codesApi);
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '42' } }),
  useRouter: () => routerApi,
}));

import CourseActivationCodesView from '@/views/catalog/CourseActivationCodesView.vue';

const row = {
  id: 101,
  batch_id: 9,
  course_id: 42,
  display_code: 'AB3D****PQRS',
  status: 'unused' as const,
  expires_at: null,
  redeemed_by: null,
  redeemed_at: null,
  voided_at: null,
  created_at: '2026-09-02T10:00:00+08:00',
};

function mountView() {
  return mount(CourseActivationCodesView, { global: { plugins: [installElementPlus] } });
}

beforeEach(() => {
  vi.clearAllMocks();
  codesApi.listActivationCodes.mockResolvedValue({ items: [row], total: 1, page: 1, limit: 20 });
  codesApi.createActivationCodeBatch.mockResolvedValue({
    id: 9,
    course_id: 42,
    quantity: 2,
    expires_at: null,
    created_at: '2026-09-02T10:00:00+08:00',
    codes: ['AB3D-EFGH-JKMN-PQRS', 'CD4E-FGHJ-KMNP-QRST'],
  });
  codesApi.voidActivationCode.mockResolvedValue(undefined);
});

describe('CourseActivationCodesView', () => {
  it('loads a masked list and never renders stored plaintext', async () => {
    const wrapper = mountView();
    await flushPromises();

    expect(codesApi.listActivationCodes).toHaveBeenCalledWith(42, {
      page: 1,
      limit: 20,
      status: undefined,
    });
    expect(wrapper.text()).toContain('AB3D****PQRS');
    expect(wrapper.text()).not.toContain('AB3D-EFGH-JKMN-PQRS');
  });

  it('creates a batch and clears plaintext when the result dialog closes', async () => {
    const wrapper = mountView();
    await flushPromises();
    const open = wrapper.findAll('button').find((button) => button.text().includes('生成激活码'))!;
    await open.trigger('click');
    await flushPromises();

    const quantity = wrapper.findComponent({ name: 'ElInputNumber' });
    quantity.vm.$emit('update:modelValue', 2);
    const confirm = wrapper.findAll('button').find((button) => button.text().includes('确认生成'))!;
    await confirm.trigger('click');
    await flushPromises();

    expect(codesApi.createActivationCodeBatch).toHaveBeenCalledWith(42, {
      quantity: 2,
      expires_at: null,
    });
    expect(wrapper.text()).toContain('AB3D-EFGH-JKMN-PQRS');

    const done = wrapper.findAll('button').find((button) => button.text() === '完成')!;
    await done.trigger('click');
    await flushPromises();
    expect(wrapper.text()).not.toContain('AB3D-EFGH-JKMN-PQRS');
  });

  it('sends the selected status and requires confirmation before voiding', async () => {
    vi.spyOn(ElMessageBox, 'confirm').mockResolvedValue(undefined as never);
    const wrapper = mountView();
    await flushPromises();

    const select = wrapper.findComponent({ name: 'ElSelect' });
    select.vm.$emit('update:modelValue', 'expired');
    await wrapper.vm.$nextTick();
    const filter = wrapper.findAll('button').find((button) => button.text() === '筛选')!;
    await filter.trigger('click');
    await flushPromises();
    expect(codesApi.listActivationCodes).toHaveBeenLastCalledWith(42, {
      page: 1,
      limit: 20,
      status: 'expired',
    });

    const voidButton = wrapper.findAll('button').find((button) => button.text().includes('作废'))!;
    await voidButton.trigger('click');
    await flushPromises();
    expect(ElMessageBox.confirm).toHaveBeenCalledOnce();
    expect(codesApi.voidActivationCode).toHaveBeenCalledWith(42, 101);
  });
});
