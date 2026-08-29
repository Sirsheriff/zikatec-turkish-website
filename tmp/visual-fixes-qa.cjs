const { chromium } = require("playwright");
const { pathToFileURL } = require("url");
const path = require("path");

(async () => {
  const browser = await chromium.launch({
    headless: true,
    executablePath: "C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe",
  });
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  const errors = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await page.goto(pathToFileURL(path.resolve("index.html")).href, { waitUntil: "networkidle" });
  await page.evaluate(() => document.fonts.ready);

  await page.locator(".site-header").screenshot({ path: "tmp/header-qa.png" });
  await page.locator(".hero").screenshot({ path: "tmp/hero-qa.png" });
  await page.locator(".advantages").screenshot({ path: "tmp/features-qa.png" });

  const report = await page.evaluate(() => {
    const rect = (selector) => {
      const r = document.querySelector(selector).getBoundingClientRect();
      return { left: r.left, top: r.top, right: r.right, bottom: r.bottom, width: r.width, height: r.height };
    };
    const tops = [...document.querySelectorAll(".product-card__top")].map((top) => {
      const label = top.querySelector("span").getBoundingClientRect();
      const capacity = top.querySelector("strong").getBoundingClientRect();
      return { gap: capacity.top - label.bottom, overlaps: capacity.top < label.bottom };
    });
    return {
      viewport: { width: innerWidth, scrollWidth: document.documentElement.scrollWidth },
      font: getComputedStyle(document.body).fontFamily,
      manropeLoaded: document.fonts.check('16px "Manrope"'),
      logo: rect(".brand__crop img"),
      logoFrame: rect(".brand"),
      featureBackgrounds: [...document.querySelectorAll(".feature-card")].map((card) => getComputedStyle(card).backgroundColor),
      productTitles: tops,
    };
  });

  console.log(JSON.stringify({ report, errors }, null, 2));
  await browser.close();
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
