import axios from 'axios';
import { ApiErr, ApiResponse, ShareLinkDTO, SharePosterDTO } from '@learn-site/contracts';
import { http } from '@/api/http';

function throwApi(error: unknown): never {
  if (axios.isAxiosError(error) && error.response?.data) {
    const parsed = ApiErr.safeParse(error.response.data);
    if (parsed.success) {
      throw Object.assign(new Error(parsed.data.error.code), { code: parsed.data.error.code });
    }
  }
  throw error;
}

export async function createShareLink(courseId: number): Promise<ShareLinkDTO> {
  try {
    const { data } = await http.post(`/courses/${courseId}/share-link`);
    const parsed = ApiResponse(ShareLinkDTO).parse(data);
    if (!parsed.ok) throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    return parsed.data;
  } catch (error) {
    throwApi(error);
  }
}

export async function createSharePoster(courseId: number): Promise<SharePosterDTO> {
  try {
    const { data } = await http.post(`/courses/${courseId}/posters`);
    const parsed = ApiResponse(SharePosterDTO).parse(data);
    if (!parsed.ok) throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    return parsed.data;
  } catch (error) {
    throwApi(error);
  }
}
