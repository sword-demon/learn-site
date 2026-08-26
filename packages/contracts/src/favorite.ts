import { z } from 'zod'

// Phase 17 / US7 — favorites (T089) + share (T090) payloads.

export const FavoriteCourseDTO = z.object({
  course_id: z.number().int(),
  title: z.string(),
  cover_url: z.string().nullable(),
  teacher_name: z.string(),
  price_mode: z.string(),
  list_price: z.number(),
  status: z.string(),
  favorited_at: z.string(),
})
export type FavoriteCourseDTO = z.infer<typeof FavoriteCourseDTO>

export const FavoriteListDTO = z.object({
  items: z.array(FavoriteCourseDTO),
  total: z.number().int(),
  page: z.number().int(),
  limit: z.number().int(),
})
export type FavoriteListDTO = z.infer<typeof FavoriteListDTO>

export const FavoriteToggleDTO = z.object({
  course_id: z.number().int(),
  favorited: z.boolean(),
})
export type FavoriteToggleDTO = z.infer<typeof FavoriteToggleDTO>

export const ShareCreateDTO = z.object({
  token: z.string(),
  share_url: z.string(),
  render_status: z.enum(['pending', 'ready', 'failed']),
})
export type ShareCreateDTO = z.infer<typeof ShareCreateDTO>