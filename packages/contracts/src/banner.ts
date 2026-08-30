import { z } from 'zod'

const bannerImageKeyPattern = /^banners\/\d{4}\/\d{2}\/[a-f0-9]{32}\.(jpg|jpeg|png|webp)$/

export const BannerImageKey = z.string().regex(bannerImageKeyPattern)
export const BannerImageUrl = z.string().regex(/^\/api\/media\/banners\//).max(512)

export const BannerPublicDTO = z.object({
  id: z.number().int().positive(),
  image_url: BannerImageUrl,
  link_url: z.string().max(2048).nullable(),
  sort_order: z.number().int().min(0).max(9999),
})
export type BannerPublicDTO = z.infer<typeof BannerPublicDTO>

export const AdminBannerDTO = BannerPublicDTO.extend({
  image_key: BannerImageKey,
  is_enabled: z.boolean(),
  created_at: z.string(),
  updated_at: z.string(),
})
export type AdminBannerDTO = z.infer<typeof AdminBannerDTO>

export const CreateBannerInput = z.object({
  image_url: BannerImageUrl,
  image_key: BannerImageKey,
  link_url: z.string().max(2048).nullable().optional(),
  sort_order: z.number().int().min(0).max(9999).default(0),
  is_enabled: z.boolean().default(true),
})
export type CreateBannerInput = z.infer<typeof CreateBannerInput>

export const UpdateBannerInput = z
  .object({
    image_url: BannerImageUrl.optional(),
    image_key: BannerImageKey.optional(),
    link_url: z.string().max(2048).nullable().optional(),
    sort_order: z.number().int().min(0).max(9999).optional(),
    is_enabled: z.boolean().optional(),
  })
  .refine((value) => Object.keys(value).length > 0, '至少填写一项修改内容')
export type UpdateBannerInput = z.infer<typeof UpdateBannerInput>

export const AdminBannerListDTO = z.object({
  items: z.array(AdminBannerDTO),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive(),
})
export type AdminBannerListDTO = z.infer<typeof AdminBannerListDTO>
