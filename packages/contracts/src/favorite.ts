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

export const ShareLinkDTO = z.object({
  course_id: z.number().int().positive(),
  share_url: z.string(),
})
export type ShareLinkDTO = z.infer<typeof ShareLinkDTO>

export const SharePosterSnapshotDTO = z.object({
  cover_url: z.string().nullable(),
  title: z.string(),
  teacher_name: z.string(),
  price_label: z.string(),
})
export type SharePosterSnapshotDTO = z.infer<typeof SharePosterSnapshotDTO>

export const SharePosterDTO = z.object({
  poster_id: z.number().int().positive().nullable(),
  token: z.string().nullable(),
  share_url: z.string(),
  render_status: z.enum(['ready', 'failed']),
  snapshot: SharePosterSnapshotDTO,
})
export type SharePosterDTO = z.infer<typeof SharePosterDTO>
