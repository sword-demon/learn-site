import axios from 'axios';
import {
  ApiErr,
  ApiResponse,
  CourseFeedbackCreatedDTO,
  SubmitCourseFeedbackInput,
} from '@learn-site/contracts';
import { http } from '@/api/http';

function apiError(code: string, message: string): Error & { code: string } {
  return Object.assign(new Error(message), { code });
}

function throwApiError(error: unknown): never {
  if (axios.isAxiosError(error) && error.response?.data) {
    const parsed = ApiErr.safeParse(error.response.data);
    if (parsed.success) {
      throw apiError(parsed.data.error.code, parsed.data.error.message);
    }
  }
  throw error;
}

export async function submitCourseFeedback(
  courseId: number,
  bodyHtml: string,
): Promise<CourseFeedbackCreatedDTO> {
  const body = SubmitCourseFeedbackInput.parse({ body_html: bodyHtml });

  try {
    const { data } = await http.post(`/courses/${courseId}/feedback`, body);
    const parsed = ApiResponse(CourseFeedbackCreatedDTO).parse(data);
    if (!parsed.ok) {
      throw apiError(parsed.error.code, parsed.error.message);
    }
    return parsed.data;
  } catch (error) {
    throwApiError(error);
  }
}
