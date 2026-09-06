import {
  OpsInboxListResponseSchema,
  OpsRetryResponseSchema,
  OpsTransitionRequestSchema,
  OpsTransitionResponseSchema,
} from '@contracts/opsInbox';
import { ApiOk } from '@contracts/envelope';
import type {
  OpsInboxListRequest,
  OpsInboxListResponse,
  OpsRetryResponse,
  OpsTransitionRequest,
  OpsTransitionResponse,
} from '@contracts/opsInbox';
import { http } from '@/api/http';

const listEnvelope = ApiOk(OpsInboxListResponseSchema);
const transitionEnvelope = ApiOk(OpsTransitionResponseSchema);
const retryEnvelope = ApiOk(OpsRetryResponseSchema);

export async function fetchOpsInbox(params: OpsInboxListRequest): Promise<OpsInboxListResponse> {
  const response = await http.get<unknown>('/ops-inbox', { params });
  return listEnvelope.parse(response.data).data;
}

export async function transitionOpsInbox(
  id: string,
  body: OpsTransitionRequest,
): Promise<OpsTransitionResponse> {
  const response = await http.post<unknown>(
    `/ops-inbox/${encodeURIComponent(id)}/transition`,
    OpsTransitionRequestSchema.parse(body),
  );
  return transitionEnvelope.parse(response.data).data;
}

export async function retryOpsInbox(sourceKey: string): Promise<OpsRetryResponse> {
  const response = await http.post<unknown>(
    `/ops-inbox/queue-failed/${encodeURIComponent(sourceKey)}/retry`,
  );
  return retryEnvelope.parse(response.data).data;
}
