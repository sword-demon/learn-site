import { expect, test } from '@playwright/test'

const CAPTCHA_ANSWER = process.env.E2E_CAPTCHA_ANSWER ?? 'E2-7'
const LEARNER_PHONE = process.env.E2E_LEARNER_PHONE ?? '13900000001'
const LEARNER_PASSWORD = process.env.E2E_LEARNER_PASSWORD ?? 'LearnerPass123!'

const FREE_COURSE = 'E2E 范围内免费课'
const FREE_LESSON = 'E2E 免费课第一节'
const PAID_COURSE = 'E2E 收费课程'

test('学员通过验证码注册，完成免费学习和 Fake 支付订单旅程', async ({ page }) => {
  await page.goto('/register')
  await expect(page.getByRole('heading', { name: '领一张课室学号', exact: true })).toBeVisible()
  await expect(page.getByRole('img', { name: '点击刷新验证码' })).toBeVisible()

  await page.getByLabel('手机号').fill(LEARNER_PHONE)
  await page.getByLabel('密码').fill(LEARNER_PASSWORD)
  await page.getByLabel('图形验证码').fill(CAPTCHA_ANSWER)

  const registerResponse = page.waitForResponse((response) =>
    response.url().endsWith('/api/learner/v1/auth/register'),
  )
  await page.getByRole('button', { name: '注册并进入', exact: true }).click()
  expect((await registerResponse).status()).toBe(200)
  await expect(page).toHaveURL(/\/$/)
  await expect(page.getByRole('link', { name: '我的订单', exact: true })).toBeVisible()

  await page.getByRole('link', { name: '消息', exact: true }).click()
  await expect(page).toHaveURL(/\/me\/messages$/)
  await expect(page.getByRole('heading', { name: '消息', exact: true })).toBeVisible()

  await page.getByRole('link', { name: FREE_COURSE, exact: true }).first().click()
  await expect(page.getByRole('heading', { name: FREE_COURSE, exact: true })).toBeVisible()
  await page.getByRole('button', { name: '开始学习', exact: true }).click()
  await expect(page).toHaveURL(/\/learn\/\d+\/\d+$/)
  await expect(page.locator('.lesson-head').getByRole('heading', { name: FREE_LESSON })).toBeVisible()

  await page.getByRole('link', { name: '分类', exact: true }).click()
  await page.getByRole('link', { name: PAID_COURSE, exact: true }).first().click()
  await page.getByRole('button', { name: '立即购买', exact: true }).click()
  await expect(page.getByRole('heading', { name: '确认课程订单', exact: true })).toBeVisible()
  await page.getByRole('button', { name: '创建支付订单', exact: true }).click()

  const orderText = await page.getByText(/订单 #\d+/).textContent()
  const orderId = Number(orderText?.match(/\d+/)?.[0])
  expect(orderId).toBeGreaterThan(0)

  const notify = await page.request.post('/api/internal/v1/payments/fake/notify', {
    headers: { 'X-Fake-Payment-Result': 'succeeded' },
    data: { order_id: orderId, out_trade_no: `e2e-${orderId}` },
    failOnStatusCode: false,
  })
  expect(notify.status()).toBe(200)

  await page.getByRole('button', { name: '刷新状态', exact: true }).click()
  await expect(page.getByRole('heading', { name: '支付成功', exact: true })).toBeVisible()
  await expect(page.getByText('课程访问权已开通。', { exact: true })).toBeVisible()

  await page.getByRole('link', { name: '查看订单记录', exact: true }).click()
  const orderRow = page.locator('.order-row[data-status="succeeded"]')
  await expect(orderRow).toHaveCount(1)
  await expect(orderRow).toContainText('已支付')
  await expect(orderRow).toContainText('49.00')
})
