import { ApiResponse, PaymentConfig, PaymentConfigUpdateInput } from '@learn-site/contracts';
import type {
  PaymentConfig as PaymentConfigDTO,
  PaymentConfigUpdateInput as PaymentConfigInput,
} from '@learn-site/contracts';
import { http } from '@/api/http';

export type { PaymentConfigDTO, PaymentConfigInput };

const configResponse = ApiResponse(PaymentConfig.nullable());

export async function fetchPaymentConfig(): Promise<PaymentConfigDTO | null> {
  const { data } = await http.get('/payment/config');
  const parsed = configResponse.parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function updatePaymentConfig(input: PaymentConfigInput): Promise<PaymentConfigDTO> {
  const body = PaymentConfigUpdateInput.parse(input);
  const { data } = await http.patch('/payment/config', body);
  const parsed = ApiResponse(PaymentConfig).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}
