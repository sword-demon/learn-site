import { expect, test, type Locator, type Page } from '@playwright/test';

const CAPTCHA_ANSWER = process.env.E2E_CAPTCHA_ANSWER ?? 'E2-7';
const OWNER_ACCOUNT = process.env.E2E_OWNER_ACCOUNT ?? 'e2e-owner';
const OWNER_PASSWORD = process.env.E2E_OWNER_PASSWORD ?? 'OwnerPass123!';

async function login(page: Page, account: string, password: string): Promise<void> {
  await page.goto('/login');
  await expect(page).toHaveTitle(/管理员登录/);
  await page.getByLabel('账号').fill(account);
  await page.getByLabel('密码').fill(password);
  await page.getByLabel('验证码').fill(CAPTCHA_ANSWER);
  const loginResponse = page.waitForResponse((response) =>
    response.url().endsWith('/api/admin/v1/auth/login'),
  );
  await page.getByRole('button', { name: '登录', exact: true }).click();
  expect((await loginResponse).status()).toBe(200);
  // 登录响应 200 ≠ token 已持久化;等 token 真正落进 localStorage,
  // 否则紧随其后的整页 goto 会竞态弹回登录页。
  await page.waitForFunction(() => localStorage.getItem('learn-site.admin.auth') !== null, {
    timeout: 10000,
  });
}

async function openOverridesWithOneRow(page: Page): Promise<void> {
  await page.goto('/distribution/overrides');
  await expect(page).toHaveTitle(/课程分销覆盖/);
  // 空表时经页面自身的表单补一条覆盖,保证有可测的数据行。
  if ((await page.getByText('还没有课程分销覆盖').count()) > 0) {
    await page.locator('[data-field="course_id"]').click();
    await page.locator('.el-select-dropdown__item').first().click();
    await page.getByRole('button', { name: '添加覆盖' }).click();
    await expect(page.getByText('已添加覆盖')).toBeVisible();
  }
  await expect(page.locator('.el-table__body-wrapper tbody tr').first()).toBeVisible();
}

async function columnBoxes(loc: Locator): Promise<Array<{ x: number; width: number }>> {
  const boxes: Array<{ x: number; width: number }> = [];
  const count = await loc.count();
  for (let i = 0; i < count; i++) {
    const box = await loc.nth(i).boundingBox();
    expect(box, `column ${i} has a layout box`).not.toBeNull();
    boxes.push({ x: box!.x, width: box!.width });
  }
  return boxes;
}

async function expectHeaderBodyAligned(page: Page, label: string): Promise<void> {
  const ths = page.locator('.el-table__header-wrapper thead th');
  const tds = page.locator('.el-table__body-wrapper tbody tr').first().locator('td');
  const heads = await columnBoxes(ths);
  const cells = await columnBoxes(tds);
  expect(heads.length, 'header/body column count').toBe(cells.length);
  for (let i = 0; i < heads.length; i++) {
    // 左边缘对齐是列对齐的硬指标(文字在格内的对齐方式允许不同)。
    expect(
      Math.abs(heads[i]!.x - cells[i]!.x),
      `${label}: column ${i} left-edge drift`,
    ).toBeLessThanOrEqual(2);
    expect(
      Math.abs(heads[i]!.width - cells[i]!.width),
      `${label}: column ${i} width drift`,
    ).toBeLessThanOrEqual(2);
  }
}

async function scrollTableRight(page: Page): Promise<void> {
  await page.locator('.el-table .el-scrollbar__wrap').evaluateAll((elements) => {
    for (const el of elements) {
      el.scrollLeft = el.scrollWidth;
    }
  });
  await page.waitForTimeout(300);
}

/**
 * 分页组件不能紧贴表格底部(用户报告)——表格底边到分页顶边必须有可见间距。
 */
async function expectPagerSpacing(page: Page, label: string): Promise<void> {
  const table = await page.locator('.el-table').first().boundingBox();
  const pager = await page.locator('.admin-list-pager').first().boundingBox();
  expect(table, 'table has a layout box').not.toBeNull();
  expect(pager, 'pager is rendered').not.toBeNull();
  const gap = pager!.y - (table!.y + table!.height);
  expect(gap, `${label}: table→pager gap ${gap.toFixed(1)}px`).toBeGreaterThanOrEqual(10);
}

async function runAlignmentScenario(page: Page, label: string): Promise<void> {
  await login(page, OWNER_ACCOUNT, OWNER_PASSWORD);
  await openOverridesWithOneRow(page);
  await expectHeaderBodyAligned(page, `${label}/initial`);
  await expectPagerSpacing(page, `${label}/initial`);
  if (label === 'narrow-scroll') {
    await scrollTableRight(page);
    await expectHeaderBodyAligned(page, `${label}/scrolled`);
  }
}

const VIEWPORT_CASES = [
  { width: 1600, height: 900, label: 'wide' },
  { width: 1024, height: 800, label: 'narrow-scroll' },
  { width: 1440, height: 900, cssZoom: '1.25', label: 'zoom-125' },
  { width: 1920, height: 1080, cssZoom: '0.8', label: 'zoom-80' },
] as const;

test.describe('课程分销覆盖表格对齐', () => {
  for (const viewport of VIEWPORT_CASES) {
    test(`表头与表体逐列对齐 (${viewport.label})`, async ({ page }) => {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      if ('cssZoom' in viewport && viewport.cssZoom) {
        // 模拟浏览器非 100% 缩放的布局效果(EP 表格在分数缩放下曾有线宽取整漂移)。
        await page.addInitScript((zoom) => {
          document.documentElement.style.zoom = zoom;
        }, viewport.cssZoom);
      }
      await runAlignmentScenario(page, viewport.label);
    });
  }
});

test.describe('课程分销覆盖表格对齐 (retina @2x)', () => {
  test.use({ viewport: { width: 1600, height: 900 }, deviceScaleFactor: 2 });
  test('表头与表体逐列对齐', async ({ page }) => {
    await runAlignmentScenario(page, 'wide-retina');
  });
});
