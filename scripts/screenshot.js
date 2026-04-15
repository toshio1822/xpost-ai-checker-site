const { chromium, devices } = require('playwright');
const fs = require('fs/promises');
const path = require('path');

const baseUrl = process.env.SCREENSHOT_BASE_URL || 'http://127.0.0.1:8000';
const screenshotsRoot = path.join(process.cwd(), 'tmp', 'screenshots');
const latestDir = path.join(screenshotsRoot, 'latest');
const baselineDir = path.join(screenshotsRoot, 'baseline');
const updateBaseline = process.argv.includes('--baseline');
const runStamp = new Date()
  .toISOString()
  .replace(/T/, '_')
  .replace(/:/g, '-')
  .replace(/\..+/, '');
const runDir = path.join(screenshotsRoot, runStamp);

const targets = [
  {
    name: 'home-desktop',
    url: '/',
    viewport: { width: 1440, height: 2200 },
  },
  {
    name: 'home-mobile',
    url: '/',
    device: devices['iPhone 13'],
  },
  {
    name: 'service-desktop',
    url: '/service/',
    viewport: { width: 1440, height: 2200 },
  },
  {
    name: 'evidence-mobile',
    url: '/evidence/',
    device: devices['iPhone 13'],
  },
];

async function ensureDir(dir) {
  await fs.mkdir(dir, { recursive: true });
}

async function copyFileSafe(from, to) {
  await fs.copyFile(from, to);
}

async function capture(browser, target) {
  const context = target.device
    ? await browser.newContext({ ...target.device })
    : await browser.newContext({ viewport: target.viewport });

  const page = await context.newPage();
  const fullUrl = new URL(target.url, baseUrl).toString();
  const fileName = `${target.name}.png`;
  const filePath = path.join(runDir, fileName);

  await page.goto(fullUrl, { waitUntil: 'networkidle' });
  await page.screenshot({
    path: filePath,
    fullPage: true,
  });

  await copyFileSafe(filePath, path.join(latestDir, fileName));

  if (updateBaseline) {
    await copyFileSafe(filePath, path.join(baselineDir, fileName));
  }

  await context.close();
  return { fullUrl, fileName };
}

(async () => {
  await ensureDir(runDir);
  await ensureDir(latestDir);
  await ensureDir(baselineDir);
  const browser = await chromium.launch({ headless: true });

  try {
    for (const target of targets) {
      const { fullUrl, fileName } = await capture(browser, target);
      console.log(`saved: ${fileName} <- ${fullUrl}`);
    }
    console.log(`run saved in ${runDir}`);
    console.log(`latest screenshots updated in ${latestDir}`);
    if (updateBaseline) {
      console.log(`baseline screenshots updated in ${baselineDir}`);
    } else {
      console.log(`baseline screenshots kept in ${baselineDir}`);
      console.log('use "npm run shot:baseline" to refresh baseline images');
    }
  } finally {
    await browser.close();
  }
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
