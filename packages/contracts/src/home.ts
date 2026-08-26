import { z } from 'zod'
import { SiteIntro } from './site.js'

export type CategoryNode = {
  id: number
  name: string
  children: CategoryNode[]
}

export const CategoryNode: z.ZodType<CategoryNode> = z.lazy(() =>
  z.object({
    id: z.number().int().positive(),
    name: z.string().min(1),
    children: z.array(CategoryNode),
  }),
)

export const HomePayload = z.object({
  categories: z.array(CategoryNode),
  site_intro: SiteIntro,
})
export type HomePayload = {
  categories: CategoryNode[]
  site_intro: z.infer<typeof SiteIntro>
}

// Re-export so existing `import { SiteIntro } from './home.js'` callers keep working.
export { SiteIntro } from './site.js'
export type { SiteIntro as SiteIntroType } from './site.js'
