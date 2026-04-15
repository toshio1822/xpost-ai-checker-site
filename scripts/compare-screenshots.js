const fs = require('fs/promises');
const path = require('path');
const { PNG } = require('pngjs');
const pixelmatch = require('pixelmatch').default;

const screenshotsRoot = path.join(process.cwd(), 'tmp', 'screenshots');
const baselineDir = path.join(screenshotsRoot, 'baseline');
const latestDir = path.join(screenshotsRoot, 'latest');
const diffDir = path.join(screenshotsRoot, 'diff');

async function ensureDir(dir) {
  await fs.mkdir(dir, { recursive: true });
}

async function readPng(filePath) {
  const buffer = await fs.readFile(filePath);
  return PNG.sync.read(buffer);
}

async function writePng(filePath, png) {
  const buffer = PNG.sync.write(png);
  await fs.writeFile(filePath, buffer);
}

async function main() {
  await ensureDir(diffDir);

  const files = (await fs.readdir(latestDir)).filter((name) => name.endsWith('.png'));

  if (files.length === 0) {
    console.log('no latest screenshots found');
    return;
  }

  for (const file of files) {
    const baselinePath = path.join(baselineDir, file);
    const latestPath = path.join(latestDir, file);
    try {
      const [baseline, latest] = await Promise.all([readPng(baselinePath), readPng(latestPath)]);

      if (baseline.width !== latest.width || baseline.height !== latest.height) {
        console.log(`size mismatch: ${file}`);
        continue;
      }

      const diff = new PNG({ width: baseline.width, height: baseline.height });
      const diffPixels = pixelmatch(
        baseline.data,
        latest.data,
        diff.data,
        baseline.width,
        baseline.height,
        { threshold: 0.1 }
      );

      const diffPath = path.join(diffDir, file);
      await writePng(diffPath, diff);
      console.log(`compared: ${file} (${diffPixels} different pixels) -> ${diffPath}`);
    } catch (error) {
      console.log(`skipped: ${file} (${error.message})`);
    }
  }

  console.log(`diff screenshots saved in ${diffDir}`);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
