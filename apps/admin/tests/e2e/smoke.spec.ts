import { expect, test, type Page } from '@playwright/test';

const CAPTCHA_ANSWER = process.env.E2E_CAPTCHA_ANSWER ?? 'E2-7';
const OWNER_ACCOUNT = process.env.E2E_OWNER_ACCOUNT ?? 'e2e-owner';
const OWNER_PASSWORD = process.env.E2E_OWNER_PASSWORD ?? 'OwnerPass123!';
const EDITOR_ACCOUNT = process.env.E2E_EDITOR_ACCOUNT ?? 'e2e-course-editor';
const EDITOR_PASSWORD = process.env.E2E_EDITOR_PASSWORD ?? 'EditorPass123!';

const DRAFT_COURSE = 'E2E 待发布课程';
const VISIBLE_COURSE = 'E2E 范围内免费课';
const HIDDEN_COURSE = 'E2E 范围外课程';

async function login(page: Page, account: string, password: string): Promise<void> {
  await page.goto('/login');
  await expect(page).toHaveTitle(/管理员登录/);
  await expect(page.getByRole('img', { name: '验证码' })).toBeVisible();

  await page.getByLabel('账号').fill(account);
  await page.getByLabel('密码').fill(password);
  await page.getByLabel('验证码').fill(CAPTCHA_ANSWER);

  const loginResponse = page.waitForResponse((response) =>
    response.url().endsWith('/api/admin/v1/auth/login'),
  );
  await page.getByRole('button', { name: '登录', exact: true }).click();
  expect((await loginResponse).status()).toBe(200);
}

test.describe.serial('管理端核心旅程', () => {
  test('超级管理员通过验证码登录并发布完整草稿课程', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await login(page, OWNER_ACCOUNT, OWNER_PASSWORD);
    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByText('学习平台 · 管理端')).toBeVisible();

    await page.getByText('课程管理', { exact: true }).click();
    await expect(page).toHaveURL(/\/courses$/);

    const courseRow = page.getByRole('row').filter({ hasText: DRAFT_COURSE });
    await expect(courseRow).toContainText('草稿');
    await courseRow.getByRole('button', { name: '发布', exact: true }).click();
    await expect(page.getByRole('dialog')).toContainText('在册学员');
    await expect(page.getByRole('dialog')).toContainText('已有访问权');
    await expect(courseRow).toContainText('草稿');
    await page.getByRole('dialog').getByRole('button', { name: '取消', exact: true }).click();
    await expect(courseRow).toContainText('草稿');
    await courseRow.getByRole('button', { name: '发布', exact: true }).click();
    await expect(page.getByRole('dialog')).toContainText('在册学员');
    await page.screenshot({ path: '/tmp/course-publish-desktop.png', animations: 'disabled' });
    await page.setViewportSize({ width: 390, height: 844 });
    const dialogBox = await page.getByRole('dialog').boundingBox();
    expect(dialogBox!.width).toBeLessThanOrEqual(390);
    await page.screenshot({ path: '/tmp/course-publish-mobile.png', animations: 'disabled' });
    await page.setViewportSize({ width: 1280, height: 720 });
    await page.getByRole('button', { name: '确认发布', exact: true }).click();

    await expect(courseRow).toContainText('已发布');
    expect(errors).toEqual([]);
  });

  test('发布接口重算过期清单并要求确认警告', async ({ page }) => {
    await login(page, OWNER_ACCOUNT, OWNER_PASSWORD);
    const token = await page.evaluate(
      () => JSON.parse(localStorage.getItem('learn-site.admin.auth')!).access as string,
    );
    const headers = { Authorization: `Bearer ${token}` };
    const url = '/api/admin/v1/courses/1';
    expect((await page.request.post(`${url}/unpublish`, { headers })).status()).toBe(200);
    const a = (await (await page.request.get(`${url}/publish-checklist`, { headers })).json()).data;
    const b = (await (await page.request.get(`${url}/publish-checklist`, { headers })).json()).data;
    expect(a.content_fingerprint).toBe(b.content_fingerprint);
    expect(a.hard_error_count).toBe(0);
    expect(
      (await page.request.patch(url, { headers, data: { intro_rich_text: '' } })).status(),
    ).toBe(200);
    const blocked = await page.request.post(`${url}/publish`, { headers });
    expect(blocked.status()).toBe(422);
    expect((await blocked.json()).error.checklist.findings).toEqual(
      expect.arrayContaining([
        expect.objectContaining({ code: 'INTRO_REQUIRED', severity: 'hard' }),
      ]),
    );
    expect(
      (
        await page.request.patch(url, { headers, data: { intro_rich_text: '<p>Restored</p>' } })
      ).status(),
    ).toBe(200);
    const tree = (await (await page.request.get(url, { headers })).json()).data;
    const lesson = await page.request.post(`${url}/lessons`, {
      headers,
      data: {
        chapter_id: tree.chapters[0].id,
        title: 'Incomplete',
        content_type: 'markdown',
        body_markdown: '',
        status: 'enabled',
      },
    });
    expect(lesson.status()).toBe(200);
    const warning = await page.request.post(`${url}/publish`, { headers });
    expect(warning.status()).toBe(422);
    expect((await warning.json()).error.message).toBe('WARNINGS_NOT_ACKNOWLEDGED');
    expect(
      (
        await page.request.post(`${url}/publish`, { headers, data: { acknowledge_warnings: true } })
      ).status(),
    ).toBe(200);
    expect((await page.request.post(`${url}/publish`, { headers })).status()).toBe(200);
  });

  test('受限员工只看到已授权菜单和本部门课程', async ({ page }) => {
    await login(page, EDITOR_ACCOUNT, EDITOR_PASSWORD);
    await expect(page).toHaveURL(/\/courses$/);

    const menu = page.locator('.menu');
    await expect(menu.getByText('课程管理', { exact: true })).toBeVisible();
    await expect(menu.getByText('订单管理', { exact: true })).toHaveCount(0);
    await expect(menu.getByText('组织管理', { exact: true })).toHaveCount(0);

    await expect(page.getByText(VISIBLE_COURSE, { exact: true })).toBeVisible();
    await expect(page.getByText(HIDDEN_COURSE, { exact: true })).toHaveCount(0);

    await page.goto('/orders');
    await expect(page).toHaveURL(
      (url) => url.pathname === '/forbidden' && url.searchParams.get('from') === '/orders',
    );
    const main = page.getByRole('main');
    await expect(main.getByText('无权访问', { exact: true })).toBeVisible();
    await expect(main.getByText('当前账号没有访问此模块的权限', { exact: true })).toBeVisible();

    const accessToken = await page.evaluate(() => {
      const raw = localStorage.getItem('learn-site.admin.auth');
      return raw ? (JSON.parse(raw) as { access?: string }).access : undefined;
    });
    expect(accessToken).toBeTruthy();

    const forbidden = await page.request.get('/api/admin/v1/orders', {
      headers: { Authorization: `Bearer ${accessToken}` },
      failOnStatusCode: false,
    });
    expect(forbidden.status()).toBe(403);
    expect((await forbidden.json()).error.code).toBe('FORBIDDEN');
  });

  test('超级管理员可进入通知管理并打开发送对话框', async ({ page }) => {
    await login(page, OWNER_ACCOUNT, OWNER_PASSWORD);
    await page.getByText('通知管理', { exact: true }).click();
    await expect(page).toHaveURL(/\/notifications$/);
    await expect(page.getByRole('heading', { name: '通知管理', exact: true })).toBeVisible();
    await page.getByRole('button', { name: '发送通知', exact: true }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
  });

  test('超级管理员可进入自动任务列表', async ({ page }) => {
    await login(page, OWNER_ACCOUNT, OWNER_PASSWORD);
    await page.getByText('自动任务', { exact: true }).click();
    await expect(page).toHaveURL(/\/scheduled-tasks$/);
    await expect(page.getByRole('heading', { name: '自动任务', exact: true })).toBeVisible();
  });
});
