import {
  ActivationCodeBatchCreatedDTO,
  AdminActivationCodeListDTO,
  ApiResponse,
  CreateActivationCodeBatchInput,
  VoidActivationCodeResultDTO,
  type ActivationCodeBatchCreatedDTO as ActivationCodeBatchCreated,
  type ActivationCodeStatus,
  type AdminActivationCodeListDTO as AdminActivationCodeList,
} from '@learn-site/contracts';
import { http } from '@/api/http';

export type { ActivationCodeBatchCreated, ActivationCodeStatus, AdminActivationCodeList };

export interface ActivationCodeListParams {
  page?: number;
  limit?: number;
  status?: ActivationCodeStatus | '';
}

export async function createActivationCodeBatch(
  courseId: number,
  input: CreateActivationCodeBatchInput,
): Promise<ActivationCodeBatchCreated> {
  const body = CreateActivationCodeBatchInput.parse(input);
  const { data } = await http.post(`/courses/${courseId}/activation-code-batches`, body);
  const parsed = ApiResponse(ActivationCodeBatchCreatedDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.message), {
      code: parsed.error.code,
      domainMessage: parsed.error.message,
    });
  }
  return parsed.data;
}

export async function listActivationCodes(
  courseId: number,
  params: ActivationCodeListParams = {},
): Promise<AdminActivationCodeList> {
  const { data } = await http.get(`/courses/${courseId}/activation-codes`, { params });
  const parsed = ApiResponse(AdminActivationCodeListDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.message), {
      code: parsed.error.code,
      domainMessage: parsed.error.message,
    });
  }
  return parsed.data;
}

export async function voidActivationCode(courseId: number, codeId: number): Promise<void> {
  const { data } = await http.post(`/courses/${courseId}/activation-codes/${codeId}/void`);
  const parsed = ApiResponse(VoidActivationCodeResultDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.message), {
      code: parsed.error.code,
      domainMessage: parsed.error.message,
    });
  }
}
