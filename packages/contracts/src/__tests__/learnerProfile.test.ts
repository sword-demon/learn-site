import { describe, expect, it } from 'vitest'
import { LearnerAvatarKey, LearnerAvatarUrl, LearnerProfileDTO } from '../index.js'

const avatar = {
  avatar_url: '/api/media/avatars/2026/09/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp',
  avatar_key: 'avatars/2026/09/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp',
}

describe('learner profile contracts', () => {
  it('accepts a stored avatar url and rejects arbitrary remote urls', () => {
    expect(LearnerAvatarKey.parse(avatar.avatar_key)).toBe(avatar.avatar_key)
    expect(LearnerAvatarUrl.parse(avatar.avatar_url)).toBe(avatar.avatar_url)
    expect(LearnerAvatarUrl.safeParse('https://example.test/me.png').success).toBe(false)
    expect(LearnerAvatarUrl.safeParse('/api/media/covers/2026/09/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp').success).toBe(
      false,
    )
  })

  it('keeps avatar_url nullable on the profile dto', () => {
    const profile = LearnerProfileDTO.parse({
      account_id: 7,
      phone: '13800138000',
      nickname: '林间学员',
      avatar_url: null,
      show_on_course: false,
      status: 'active',
      created_at: '2026-08-30 10:00:00',
    })
    expect(profile.avatar_url).toBeNull()

    const withAvatar = LearnerProfileDTO.parse({
      ...profile,
      avatar_url: avatar.avatar_url,
    })
    expect(withAvatar.avatar_url).toBe(avatar.avatar_url)
  })
})
