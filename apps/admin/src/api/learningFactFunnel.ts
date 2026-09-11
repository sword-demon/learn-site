import {
  ApiResponse,
  LearningFactFunnelDTO,
  type LearningFactFunnelQuery,
} from '@learn-site/contracts';
import { http } from '@/api/http';

export async function fetchLearningFactFunnel(
  courseId: number,
  query: LearningFactFunnelQuery = { window_days: 30, source: 'all' },
) {
  const { data } = await http.get(`/courses/${courseId}/learning-funnel`, { params: query });
  const parsed = ApiResponse(LearningFactFunnelDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}
