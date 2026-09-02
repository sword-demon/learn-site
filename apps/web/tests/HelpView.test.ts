// @vitest-environment happy-dom

import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import HelpView from '@/views/help/HelpView.vue';

describe('HelpView', () => {
  it('renders help sections and legal links', () => {
    const wrapper = mount(HelpView);

    expect(wrapper.get('[data-view="help"]').attributes('data-view')).toBe('help');
    expect(wrapper.text()).toContain('帮助中心');
    expect(wrapper.text()).toContain('账号与登录');
    expect(wrapper.text()).toContain('激活码兑换');
    expect(wrapper.get('a[href="/refund"]').text()).toContain('退款说明');
    expect(wrapper.get('a[href="/terms"]').text()).toContain('用户协议');
  });
});
