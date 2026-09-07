// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { h } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { LearnerProfileDTO } from '@learn-site/contracts';

const learnerApi = vi.hoisted(() => ({
  uploadLearnerAvatar: vi.fn(),
  deleteLearnerAvatar: vi.fn(),
}));
vi.mock('@/api/learner', () => learnerApi);

import LearnerAvatarUpload from '@/components/LearnerAvatarUpload.vue';

const profile: LearnerProfileDTO = {
  account_id: 7,
  phone: '13800138000',
  nickname: '林间学员',
  avatar_url: '/api/media/avatars/2026/09/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp',
  show_on_course: false,
  status: 'active',
  created_at: '2026-08-30 10:00:00',
};

const UploadStub = {
  props: ['httpRequest', 'beforeUpload'],
  setup(props: {
    httpRequest?: (request: {
      file: File;
      onSuccess: () => void;
      onError: () => void;
    }) => Promise<void>;
    beforeUpload?: (file: File) => boolean;
  }) {
    const file = new File(['avatar'], 'avatar.webp', { type: 'image/webp' });
    return () =>
      h(
        'button',
        {
          'data-role': 'choose-avatar',
          onClick: () => {
            if (props.beforeUpload && props.beforeUpload(file) === false) return;
            void props.httpRequest?.({
              file,
              onSuccess: () => undefined,
              onError: () => undefined,
            });
          },
        },
        '上传头像',
      );
  },
};

describe('LearnerAvatarUpload', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    learnerApi.uploadLearnerAvatar.mockResolvedValue(profile);
    learnerApi.deleteLearnerAvatar.mockResolvedValue({ ...profile, avatar_url: null });
  });

  function mountField(avatarUrl: string | null = null) {
    return mount(LearnerAvatarUpload, {
      props: { avatarUrl, initial: '林' },
      global: {
        stubs: {
          'el-upload': UploadStub,
          'el-button': { template: '<button><slot /></button>' },
        },
      },
    });
  }

  it('uploads an image and emits the updated profile', async () => {
    const wrapper = mountField();

    await wrapper.get('[data-role="choose-avatar"]').trigger('click');
    await flushPromises();

    expect(learnerApi.uploadLearnerAvatar).toHaveBeenCalledOnce();
    expect(wrapper.emitted('updated')).toEqual([[profile]]);
  });

  it('shows a preview and can remove the current avatar', async () => {
    const wrapper = mountField(profile.avatar_url);

    expect(wrapper.find('[data-role="avatar-preview"]').attributes('src')).toBe(profile.avatar_url);

    await wrapper.get('[data-role="clear-avatar"]').trigger('click');
    await flushPromises();

    expect(learnerApi.deleteLearnerAvatar).toHaveBeenCalledOnce();
    expect(wrapper.emitted('updated')).toEqual([[{ ...profile, avatar_url: null }]]);
  });

  it('rejects unsupported files before calling the API', async () => {
    const wrapper = mount(LearnerAvatarUpload, {
      props: { avatarUrl: null, initial: '林' },
      global: {
        stubs: {
          'el-upload': {
            props: ['httpRequest', 'beforeUpload'],
            setup(props: {
              httpRequest?: (request: {
                file: File;
                onSuccess: () => void;
                onError: () => void;
              }) => Promise<void>;
              beforeUpload?: (file: File) => boolean;
            }) {
              const file = new File(['nope'], 'avatar.gif', { type: 'image/gif' });
              return () =>
                h(
                  'button',
                  {
                    'data-role': 'choose-avatar',
                    onClick: () => {
                      if (props.beforeUpload && props.beforeUpload(file) === false) return;
                      void props.httpRequest?.({
                        file,
                        onSuccess: () => undefined,
                        onError: () => undefined,
                      });
                    },
                  },
                  '上传头像',
                );
            },
          },
          'el-button': { template: '<button><slot /></button>' },
        },
      },
    });

    await wrapper.get('[data-role="choose-avatar"]').trigger('click');
    await flushPromises();

    expect(learnerApi.uploadLearnerAvatar).not.toHaveBeenCalled();
  });
});
