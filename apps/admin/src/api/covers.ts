import http from './http'
import { z } from 'zod'
import { ApiOk } from '@learn-site/contracts'

const CoverUploadEnvelope = ApiOk(z.object({
  key: z.string().min(1),
  url: z.string().min(1),
  mime_type: z.enum(['image/jpeg', 'image/png', 'image/webp']),
  size_bytes: z.number().int().positive(),
}))

export interface UploadCoverInput {
  file: File
  onUploadProgress?: (event: { loaded: number; total?: number }) => void
}

export type UploadCoverResult = z.infer<typeof CoverUploadEnvelope>['data']

type CoverUploadEndpoint = '/course-covers' | '/map-covers'

export async function uploadCover(
  endpoint: CoverUploadEndpoint,
  input: UploadCoverInput,
): Promise<UploadCoverResult> {
  const fd = new FormData()
  fd.append('file', input.file)
  const response = await http.post<unknown>(endpoint, fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
    onUploadProgress: (event) => {
      if (input.onUploadProgress && typeof event.loaded === 'number') {
        const progress: { loaded: number; total?: number } = { loaded: event.loaded }
        if (event.total !== undefined) progress.total = event.total
        input.onUploadProgress(progress)
      }
    },
  })
  return CoverUploadEnvelope.parse(response.data).data
}
