const { chromium } = require("playwright");
const { pathToFileURL } = require("url");
const path = require("path");

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: "C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe" });
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  const errors = [];
  const failed = [];
  page.on("pageerror", (error) => errors.push(error.message));
  page.on("console", (message) => { if (message.type() === "error") errors.push(message.text()); });
  page.on("requestfailed", (request) => failed.push(request.url()));
  await page.goto(pathToFileURL(path.resolve("index.html")).href, { waitUntil: "networkidle" });
  await page.locator(".product-cards").scrollIntoViewIfNeeded();
  await page.evaluate(() => Promise.all([...document.querySelectorAll(".product-card__visual img")].map((img) => img.decode())));
  await page.locator(".product-cards").screenshot({ path: "tmp/product-cards-desktop.png" });
  const desktop = await page.evaluate(() => ({
    width: innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
    cards: [...document.querySelectorAll(".product-card")].map((card) => ({
      model: card.querySelector(".product-card__top strong")?.textContent,
      alt: card.querySelector(".product-card__visual img")?.alt,
      currentSrc: card.querySelector(".product-card__visual img")?.currentSrc,
      imageHeight: Math.round(card.querySelector(".product-card__visual img")?.getBoundingClientRect().height || 0),
      visualHeight: Math.round(card.querySelector(".product-card__visual")?.getBoundingClientRect().height || 0),
      pictureHeight: Math.round(card.querySelector(".product-card__visual picture")?.getBoundingClientRect().height || 0),
      imgCss: (() => { const e = card.querySelector(".product-card__visual img"); const s = getComputedStyle(e); return { width: s.width, height: s.height, position: s.position }; })(),
    })),
  }));
  await page.setViewportSize({ width: 390, height: 844 });
  await page.reload({ waitUntil: "networkidle" });
  await page.locator(".product-cards").scrollIntoViewIfNeeded();
  await page.evaluate(() => Promise.all([...document.querySelectorAll(".product-card__visual img")].map((img) => img.decode())));
  await page.locator(".product-cards").screenshot({ path: "tmp/product-cards-mobile.png" });
  const mobile = await page.evaluate(() => ({ width: innerWidth, scrollWidth: document.documentElement.scrollWidth }));
  console.log(JSON.stringify({ desktop, mobile, errors, failed }, null, 2));
  await browser.close();
})().catch((error) => { console.error(error); process.exit(1); });
