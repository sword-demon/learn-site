import axios from 'axios';
import {
  ApiErr,
  ApiResponse,
  CaptchaChallenge,
  CategoryCoursesEnvelopeDTO,
  CreateOrderResponseDTO,
  HomePayload,
  LearnerLoginInput,
  LearnerRegisterInput,
  LessonDeliveryDTO,
  LessonProgressDTO,
  LessonProgressReportDTO,
  MyLearningListDTO,
  OrderDTO,
  OrderListDTO,
  PublicCourseDetailDTO,
  PublicCourseList,
  StartCourseResponseDTO,
  TokenPair,
  QuestionListDTO,
  QuestionThreadDTO,
  AskQuestionInput,
  FollowupInput,
  ReviewListDTO,
  ReviewThreadDTO,
  ReviewReplyDTO,
  PostReviewInput,
  UpdateReviewInput,
  PostReplyInput,
  DeleteReviewDTO,
  LearnerMapListDTO,
  LearnerMapDetailDTO,
  FavoriteListDTO,
  FavoriteToggleDTO,
  LearnerProfileDTO,
  LearnerProfileUpdateInput,
  PaymentChannel,
  PaymentOptionsDTO,
} from '@learn-site/contracts';
import { http } from '@/api/http';

const TokenPairLoose = TokenPair.passthrough();

function throwApi(err: unknown): never {
  if (axios.isAxiosError(err) && err.response?.data) {
    const parsed = ApiErr.safeParse(err.response.data);
    if (parsed.success) {
      throw Object.assign(new Error(parsed.data.error.code), { code: parsed.data.error.code });
    }
  }
  throw err;
}

export async function fetchCaptcha(): Promise<CaptchaChallenge> {
  try {
    const { data } = await http.get('/auth/captcha');
    const parsed = ApiResponse(CaptchaChallenge).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function loginLearner(input: LearnerLoginInput): Promise<TokenPair> {
  const body = LearnerLoginInput.parse(input);
  try {
    const { data } = await http.post('/auth/login', body);
    const parsed = ApiResponse(TokenPairLoose).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function registerLearner(input: LearnerRegisterInput): Promise<TokenPair> {
  const body = LearnerRegisterInput.parse(input);
  try {
    const { data } = await http.post('/auth/register', body);
    const parsed = ApiResponse(TokenPairLoose).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function fetchHome(): Promise<HomePayload> {
  try {
    const { data } = await http.get('/home');
    const parsed = ApiResponse(HomePayload).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function fetchLearnerProfile(): Promise<LearnerProfileDTO> {
  try {
    const { data } = await http.get('/me');
    const parsed = ApiResponse(LearnerProfileDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function updateLearnerProfile(
  input: LearnerProfileUpdateInput,
): Promise<LearnerProfileDTO> {
  const body = LearnerProfileUpdateInput.parse(input);
  try {
    const { data } = await http.patch('/me', body);
    const parsed = ApiResponse(LearnerProfileDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function fetchCategoryCourses(
  categoryId: number,
  page = 1,
  limit = 20,
): Promise<{
  category: import('@learn-site/contracts').CategoryBreadcrumbDTO;
  list: PublicCourseList;
}> {
  try {
    const { data } = await http.get(`/categories/${categoryId}/courses`, {
      params: { page, limit },
    });
    const parsed = ApiResponse(CategoryCoursesEnvelopeDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function fetchCourseDetail(id: number): Promise<PublicCourseDetailDTO> {
  try {
    const { data } = await http.get(`/courses/${id}`);
    const parsed = ApiResponse(PublicCourseDetailDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function fetchLesson(courseId: number, lessonId: number): Promise<LessonDeliveryDTO> {
  try {
    const { data } = await http.get(`/courses/${courseId}/lessons/${lessonId}`);
    const parsed = ApiResponse(LessonDeliveryDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function fetchMediaObjectUrl(mediaUrl: string): Promise<string> {
  const { data } = await http.get<Blob>(mediaUrl, {
    baseURL: '',
    responseType: 'blob',
  });
  return URL.createObjectURL(data);
}

export async function startCourse(courseId: number): Promise<StartCourseResponseDTO> {
  try {
    const { data } = await http.post(`/courses/${courseId}/start`);
    const parsed = ApiResponse(StartCourseResponseDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function createCourseOrder(
  courseId: number,
  learnerCouponId?: number | null,
  channel?: PaymentChannel,
): Promise<CreateOrderResponseDTO> {
  try {
    const body: { learner_coupon_id?: number; channel?: PaymentChannel } = {};
    if (learnerCouponId && learnerCouponId > 0) body.learner_coupon_id = learnerCouponId;
    if (channel !== undefined) body.channel = channel;
    const { data } = await http.post(`/courses/${courseId}/orders`, body);
    const parsed = ApiResponse(CreateOrderResponseDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function fetchPaymentOptions(): Promise<PaymentOptionsDTO> {
  try {
    const { data } = await http.get('/payment/options');
    const parsed = ApiResponse(PaymentOptionsDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function fetchOrders(page = 1, limit = 20): Promise<OrderListDTO> {
  try {
    const { data } = await http.get('/orders', { params: { page, limit } });
    const parsed = ApiResponse(OrderListDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function fetchOrder(orderId: number): Promise<OrderDTO> {
  try {
    const { data } = await http.get(`/orders/${orderId}`);
    const parsed = ApiResponse(OrderDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function fetchMyLearning(): Promise<MyLearningListDTO> {
  try {
    const { data } = await http.get('/my/learning');
    const parsed = ApiResponse(MyLearningListDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function reportLessonProgress(
  lessonId: number,
  body: LessonProgressReportDTO,
): Promise<LessonProgressDTO> {
  try {
    const { data } = await http.post(`/lessons/${lessonId}/progress`, body);
    const parsed = ApiResponse(LessonProgressDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

/** Persist a video heartbeat through the video-only learner contract. */
export async function reportVideoHeartbeat(
  lessonId: number,
  positionSeconds: number,
  durationSeconds: number,
): Promise<LessonProgressDTO> {
  try {
    const { data } = await http.post(`/lessons/${lessonId}/video-heartbeat`, {
      position_seconds: Math.max(0, Math.floor(positionSeconds)),
      duration_seconds: Math.max(0, Math.floor(durationSeconds)),
    });
    const parsed = ApiResponse(LessonProgressDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

// Phase 11 / US4 — lesson Q&A
export async function fetchLessonQuestions(
  lessonId: number,
  opts: { page?: number; limit?: number; status?: string } = {},
): Promise<QuestionListDTO> {
  try {
    const { data } = await http.get(`/lessons/${lessonId}/questions`, { params: opts });
    const parsed = ApiResponse(QuestionListDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function askLessonQuestion(
  lessonId: number,
  input: AskQuestionInput,
): Promise<QuestionThreadDTO> {
  const body = AskQuestionInput.parse(input);
  try {
    const { data } = await http.post(`/lessons/${lessonId}/questions`, body);
    const parsed = ApiResponse(QuestionThreadDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function fetchQuestion(id: number): Promise<QuestionThreadDTO> {
  try {
    const { data } = await http.get(`/questions/${id}`);
    const parsed = ApiResponse(QuestionThreadDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function postFollowup(id: number, input: FollowupInput): Promise<QuestionThreadDTO> {
  const body = FollowupInput.parse(input);
  try {
    const { data } = await http.post(`/questions/${id}/messages`, body);
    const parsed = ApiResponse(QuestionThreadDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

// Phase 12 / US5 — course reviews
export async function fetchCourseReviews(
  courseId: number,
  opts: { page?: number; limit?: number } = {},
): Promise<ReviewListDTO> {
  try {
    const { data } = await http.get(`/courses/${courseId}/reviews`, { params: opts });
    const parsed = ApiResponse(ReviewListDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function postCourseReview(
  courseId: number,
  input: PostReviewInput,
): Promise<ReviewThreadDTO> {
  const body = PostReviewInput.parse(input);
  try {
    const { data } = await http.post(`/courses/${courseId}/reviews`, body);
    const parsed = ApiResponse(ReviewThreadDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function fetchReviewThread(id: number): Promise<ReviewThreadDTO> {
  try {
    const { data } = await http.get(`/reviews/${id}`);
    const parsed = ApiResponse(ReviewThreadDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function updateCourseReview(
  id: number,
  input: UpdateReviewInput,
): Promise<ReviewThreadDTO> {
  const body = UpdateReviewInput.parse(input);
  try {
    const { data } = await http.patch(`/reviews/${id}`, body);
    const parsed = ApiResponse(ReviewThreadDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function deleteCourseReview(id: number): Promise<void> {
  try {
    const { data } = await http.delete(`/reviews/${id}`);
    const parsed = ApiResponse(DeleteReviewDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
  } catch (err) {
    throwApi(err);
  }
}

export async function postReviewReply(
  reviewId: number,
  input: PostReplyInput,
): Promise<ReviewReplyDTO> {
  const body = PostReplyInput.parse(input);
  try {
    const { data } = await http.post(`/reviews/${reviewId}/replies`, body);
    const parsed = ApiResponse(ReviewReplyDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

// Phase 13 / US6 — learning maps
export async function fetchLearningMaps(page = 1, limit = 20): Promise<LearnerMapListDTO> {
  try {
    const { data } = await http.get('/learning-maps', { params: { page, limit } });
    const parsed = ApiResponse(LearnerMapListDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function fetchLearningMap(id: number): Promise<LearnerMapDetailDTO> {
  try {
    const { data } = await http.get(`/learning-maps/${id}`);
    const parsed = ApiResponse(LearnerMapDetailDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function startLearningMap(id: number): Promise<LearnerMapDetailDTO> {
  try {
    const { data } = await http.post(`/learning-maps/${id}/start`);
    const parsed = ApiResponse(LearnerMapDetailDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

// Phase 17 / US7 — favorites + share.

export async function fetchFavorites(page = 1, limit = 20): Promise<FavoriteListDTO> {
  try {
    const { data } = await http.get('/me/favorites', { params: { page, limit } });
    const parsed = ApiResponse(FavoriteListDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function addFavorite(courseId: number): Promise<FavoriteToggleDTO> {
  try {
    const { data } = await http.post(`/courses/${courseId}/favorite`);
    const parsed = ApiResponse(FavoriteToggleDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}

export async function removeFavorite(courseId: number): Promise<FavoriteToggleDTO> {
  try {
    const { data } = await http.delete(`/courses/${courseId}/favorite`);
    const parsed = ApiResponse(FavoriteToggleDTO).parse(data);
    if (!parsed.ok) {
      throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    }
    return parsed.data;
  } catch (err) {
    throwApi(err);
  }
}
