import http from './http';
import { z, ZodError } from 'zod';
import { ApiOk, BannerImageKey, BannerImageUrl } from '@learn-site/contracts';

const CoverUploadEnvelope = ApiOk(
  z.object({
    key: z.string().min(1),
    url: z.string().min(1),
    mime_type: z.enum(['image/jpeg', 'image/png', 'image/webp']),
    size_bytes: z.number().int().positive(),
  }),
);

export interface UploadCoverInput {
  file: File;
  onUploadProgress?: (event: { loaded: number; total?: number }) => void;
}

export type UploadCoverResult = z.infer<typeof CoverUploadEnvelope>['data'];

const BannerUploadEnvelope = ApiOk(
  z.object({
    key: BannerImageKey,
    url: BannerImageUrl,
    mime_type: z.enum(['image/jpeg', 'image/png', 'image/webp']),
    size_bytes: z.number().int().positive(),
  }),
);

type CoverUploadEndpoint = '/course-covers' | '/map-covers' | '/banner-images';

function parseBannerUploadResponse(data: unknown): UploadCoverResult {
  const parsed = BannerUploadEnvelope.safeParse(data);
  if (!parsed.success) {
    if (
      parsed.error instanceof ZodError &&
      parsed.error.issues.some((issue) => issue.path[0] === 'data')
    ) {
      throw new Error('轮播图片上传响应无效，请执行 make rebuild-api 后重试');
    }
    throw parsed.error;
  }
  if (parsed.data.data.url !== `/api/media/${parsed.data.data.key}`) {
    throw new Error('轮播图片上传响应无效，请重新上传');
  }
  return parsed.data.data;
}

export async function uploadCover(
  endpoint: CoverUploadEndpoint,
  input: UploadCoverInput,
): Promise<UploadCoverResult> {
  const fd = new FormData();
  fd.append('file', input.file);
  const response = await http.post<unknown>(endpoint, fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
    onUploadProgress: (event) => {
      if (input.onUploadProgress && typeof event.loaded === 'number') {
        const progress: { loaded: number; total?: number } = { loaded: event.loaded };
        if (event.total !== undefined) progress.total = event.total;
        input.onUploadProgress(progress);
      }
    },
  });
  return CoverUploadEnvelope.parse(response.data).data;
}

export async function uploadBannerImage(input: UploadCoverInput): Promise<UploadCoverResult> {
  const fd = new FormData();
  fd.append('file', input.file);
  const response = await http.post<unknown>('/banner-images', fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
    onUploadProgress: (event) => {
      if (input.onUploadProgress && typeof event.loaded === 'number') {
        const progress: { loaded: number; total?: number } = { loaded: event.loaded };
        if (event.total !== undefined) progress.total = event.total;
        input.onUploadProgress(progress);
      }
    },
  });
  return parseBannerUploadResponse(response.data);
}
