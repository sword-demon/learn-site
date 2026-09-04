import {
  ApiResponse,
  RedeemActivationCodeInput,
  RedeemActivationCodeResultDTO,
  type RedeemActivationCodeResultDTO as RedeemActivationCodeResult,
} from '@learn-site/contracts';
import { http } from '@/api/http';

export type { RedeemActivationCodeResult };

export async function redeemActivationCode(code: string): Promise<RedeemActivationCodeResult> {
  const body = RedeemActivationCodeInput.parse({ code: code.trim() });
  const { data } = await http.post('/activation-codes/redeem', body);
  const parsed = ApiResponse(RedeemActivationCodeResultDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.message), {
      code: parsed.error.code,
      domainMessage: parsed.error.message,
    });
  }
  return parsed.data;
}

const ERROR_MESSAGES: Record<string, string> = {
  ACTIVATION_CODE_INVALID: '激活码格式无效或不存在。',
  ACTIVATION_CODE_REDEEMED: '该激活码已被兑换。',
  ACTIVATION_CODE_VOID: '该激活码已作废。',
  ACTIVATION_CODE_EXPIRED: '该激活码已过期。',
  ACTIVATION_CODE_COURSE_UNAVAILABLE: '该激活码对应的课程当前不可兑换。',
  ENTITLEMENT_ALREADY_ACTIVE: '你已拥有该课程，激活码未被消耗。',
  RATE_LIMITED: '尝试次数过多，请稍后再试。',
  UNAUTHENTICATED: '登录状态已失效，请重新登录。',
};

/** Extract a stable domain key from mapped errors or raw Axios envelopes. */
export function activationCodeErrorMessage(error: unknown): string {
  if (typeof error === 'object' && error !== null) {
    const mapped = error as {
      code?: unknown;
      message?: unknown;
      domainMessage?: unknown;
      response?: {
        data?: {
          error?: {
            code?: unknown;
            message?: unknown;
            course_id?: unknown;
            course_title?: unknown;
          };
        };
      };
    };
    const envelope = mapped.response?.data?.error;
    if (
      envelope?.message === 'ENTITLEMENT_ALREADY_ACTIVE' &&
      typeof envelope.course_title === 'string' &&
      envelope.course_title.trim() !== ''
    ) {
      return `你已拥有「${envelope.course_title.trim()}」，激活码未被消耗。`;
    }
    const candidates = [
      mapped.domainMessage,
      envelope?.message,
      envelope?.code,
      mapped.code,
      mapped.message,
    ];
    for (const candidate of candidates) {
      if (typeof candidate === 'string' && ERROR_MESSAGES[candidate]) {
        return ERROR_MESSAGES[candidate];
      }
    }
  }
  return '兑换失败，请稍后再试。';
}
