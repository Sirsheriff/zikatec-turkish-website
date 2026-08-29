function buildPhotoGallery(model, folder, files) {
  return files.map((file, index) => ({
    src: `assets/images/${folder}/${file}`,
    alt: `${model} endüstriyel hava soğutucu ürün görünümü ${index + 1}`,
    label: `Ürün görünümü ${index + 1}`,
  }));
}

const productData = {
  "zika-350": {
    id: "zika-350",
    number: "350",
    title: "ZIKA 350",
    description:
      "Kompakt yapısıyla daha sınırlı endüstriyel alanlar ve bölgesel soğutma ihtiyaçları için değerlendirilir.",
    use:
      "Daha sınırlı endüstriyel alanlar ve belirli çalışma noktalarında bölgesel hava akışı ihtiyacı için.",
    image: "assets/images/products/cards/zika-350.webp",
    gallery: [
      {
        src: "assets/images/products/cards/zika-350.webp",
        alt: "ZIKA 350 endüstriyel hava soğutucu ön görünümü",
        label: "Ön görünüm",
      },
      ...buildPhotoGallery("ZIKA 350", "zika 350", [
        "DSS09553.jpg",
        "DSS09556.jpg",
        "DSS09558.jpg",
        "DSS09558_label removed.jpg",
        "DSS09560.jpg",
        "DSS09560_label removed.jpg",
        "DSS09561.jpg",
        "DSS09563.jpg",
        "DSS09563_arrow removed.jpg",
      ]),
    ],
    specs: {
      airflow: "22.000 m³/h",
      motor: "0,9 kW",
      current: "Yaklaşık 2,4 A",
      speed: "20 kademe",
      drive: "Kayışsız, doğrudan tahrik",
      body: "Tek parça mühendislik polimeri",
      mobility: "Tekerlekli, taşınabilir yapı",
      useCase: "Daha sınırlı alanlar ve bölgesel soğutma",
    },
  },
  "zika-500": {
    id: "zika-500",
    number: "500",
    title: "ZIKA 500",
    description:
      "Orta ölçekli üretim ve çalışma alanlarında dengeli hava debisi ihtiyacı için değerlendirilir.",
    use:
      "Orta ölçekli üretim, atölye ve çalışma alanlarında taşınabilir yüksek hava debisi ihtiyacı için.",
    image: "assets/images/products/cards/zika-500.webp",
    gallery: [
      {
        src: "assets/images/products/DSC07249-cutout.png",
        alt: "ZIKA 500 endüstriyel hava soğutucu ön görünümü",
        label: "Ön görünüm",
      },
      {
        src: "assets/images/products/DSC07250-cutout.png",
        alt: "ZIKA 500 endüstriyel hava soğutucu açılı görünümü",
        label: "Açılı görünüm",
      },
      {
        src: "assets/images/products/DSC07251-cutout.png",
        alt: "ZIKA 500 endüstriyel hava soğutucu yan görünümü",
        label: "Yan görünüm",
      },
      {
        src: "assets/images/products/DSC07252-cutout.png",
        alt: "ZIKA 500 evaporatif soğutma pedi görünümü",
        label: "Soğutma pedi",
      },
      {
        src: "assets/images/products/DSC07253-cutout.png",
        alt: "ZIKA 500 endüstriyel hava soğutucu kontrol tarafı",
        label: "Kontrol tarafı",
      },
      {
        src: "assets/images/products/DSC07255-cutout.png",
        alt: "ZIKA 500 endüstriyel hava soğutucu üç çeyrek görünümü",
        label: "Üç çeyrek görünüm",
      },
    ],
    specs: {
      airflow: "Yaklaşık 36.000 m³/h",
      motor: "1,1 kW",
      current: "Yaklaşık 3,5 A",
      speed: "20 kademe",
      drive: "Kayışsız, doğrudan tahrik",
      body: "Tek parça mühendislik polimeri",
      mobility: "Tekerlekli, taşınabilir yapı",
      useCase: "Orta ölçekli üretim ve çalışma alanları",
    },
  },
  "zika-700": {
    id: "zika-700",
    number: "700",
    title: "ZIKA 700",
    description:
      "Geniş hacimler ve yüksek hava ihtiyacı bulunan endüstriyel alanlar için değerlendirilir.",
    use:
      "Geniş üretim ve çalışma hacimlerinde daha yüksek hava debisi gerektiren uygulamalar için.",
    image: "assets/images/products/cards/zika-700.webp",
    gallery: [
      {
        src: "assets/images/products/cards/zika-700.webp",
        alt: "ZIKA 700 endüstriyel hava soğutucu ön görünümü",
        label: "Ön görünüm",
      },
      ...buildPhotoGallery("ZIKA 700", "zika 700", [
        "DSC04724-1.jpg",
        "DSC04724.jpg",
        "DSC04729-1.jpg",
        "DSC04729.jpg",
        "DSC04791.jpg",
        "DSC04807.jpg",
        "DSC04820.jpg",
        "DSC04829.jpg",
        "DSC04841.jpg",
        "DSC04842.jpg",
        "DSC04849.jpg",
        "DSC04864.jpg",
        "DSC04871.jpg",
        "DSC05148.jpg",
        "DSC05162.jpg",
        "DSC05165.jpg",
        "DSC05170.jpg",
      ]),
    ],
    specs: {
      airflow: "Yaklaşık 45.000 m³/h",
      motor: "1,1 kW",
      current: "Yaklaşık 4 A",
      speed: "20 kademe",
      drive: "Kayışsız, doğrudan tahrik",
      body: "Tek parça mühendislik polimeri",
      mobility: "Tekerlekli, taşınabilir yapı",
      useCase: "Geniş hacimler ve yüksek hava ihtiyacı",
    },
  },
};

const productIds = Object.keys(productData);
const specRows = [
  ["airflow", "Hava debisi"],
  ["motor", "BLDC motor gücü"],
  ["current", "Nominal akım"],
  ["speed", "Hız kontrolü"],
  ["drive", "Tahrik yapısı"],
  ["body", "Gövde"],
  ["mobility", "Kullanım yapısı"],
  ["useCase", "Değerlendirme odağı"],
];
const benefits = [
  {
    title: "Kayışsız BLDC motor",
    text: "Doğrudan tahrik yapısı kayış ve kasnak ihtiyacını ortadan kaldırır; enerji kullanımını ve mekanik bakım ihtiyacını azaltmaya yardımcı olur.",
  },
  {
    title: "20 kademeli kontrol",
    text: "Hava debisi, çalışma noktasındaki ihtiyaca göre farklı hız seviyelerinde ayarlanabilir.",
  },
  {
    title: "Taşınabilir kullanım",
    text: "Tekerlekli yapı, cihazın ihtiyaç değiştikçe farklı çalışma noktalarına taşınabilmesini sağlar.",
  },
  {
    title: "Polimer gövde",
    text: "Tek parça mühendislik polimeri gövde, endüstriyel çalışma koşulları için tasarlanmıştır.",
  },
];

const number = document.querySelector("[data-product-number]");
const description = document.querySelector("[data-product-description]");
const use = document.querySelector("[data-product-use]");
const breadcrumb = document.querySelector("[data-breadcrumb-model]");
const technicalTitle = document.querySelector("[data-technical-title]");
const mainImage = document.querySelector("[data-main-image]");
const thumbs = document.querySelector("[data-gallery-thumbs]");
const modelTabs = document.querySelector("[data-model-tabs]");
const technicalList = document.querySelector("[data-technical-list]");
const benefitGrid = document.querySelector("[data-benefits]");
const relatedModels = document.querySelector("[data-related-models]");
const metaDescription = document.querySelector("[data-product-meta]");

const metricAirflow = document.querySelector("[data-metric-airflow]");
const metricMotor = document.querySelector("[data-metric-motor]");
const metricCurrent = document.querySelector("[data-metric-current]");

const imageDialog = document.querySelector("[data-image-dialog]");
const dialogImage = document.querySelector("[data-dialog-image]");
const imageCaption = document.querySelector("[data-image-caption]");
const imagePrev = document.querySelector("[data-image-prev]");
const imageNext = document.querySelector("[data-image-next]");

const compareDialog = document.querySelector("[data-compare-dialog]");
const compareLeft = document.querySelector("[data-compare-left]");
const compareRight = document.querySelector("[data-compare-right]");
const compareLeftPanel = document.querySelector("[data-compare-left-panel]");
const compareRightPanel = document.querySelector("[data-compare-right-panel]");

let currentProductId = "zika-350";
let currentGalleryIndex = 0;

function normalizeProductId(value) {
  return productIds.includes(value) ? value : productIds[0];
}

function getNextProductId(value) {
  const index = productIds.indexOf(value);
  return productIds[(index + 1) % productIds.length];
}

function renderModelTabs() {
  if (!modelTabs) return;
  modelTabs.innerHTML = productIds
    .map((id) => {
      const product = productData[id];
      const active = id === currentProductId;
      return `<button type="button" class="${active ? "is-active" : ""}" data-model-id="${id}" aria-pressed="${active}">ZIKA ${product.number}</button>`;
    })
    .join("");
}

function setGalleryImage(index) {
  const product = productData[currentProductId];
  const item = product.gallery[index] || product.gallery[0];
  currentGalleryIndex = Math.max(0, product.gallery.indexOf(item));

  if (mainImage) {
    mainImage.src = item.src;
    mainImage.alt = item.alt;
  }

  thumbs?.querySelectorAll("button").forEach((button, buttonIndex) => {
    const active = buttonIndex === currentGalleryIndex;
    button.classList.toggle("is-active", active);
    button.setAttribute("aria-pressed", String(active));
  });
}

function renderGallery() {
  const product = productData[currentProductId];
  if (!thumbs) return;

  thumbs.innerHTML = product.gallery
    .map(
      (item, index) => `
        <button type="button" data-gallery-index="${index}" aria-label="${item.label}" aria-pressed="${index === 0}">
          <img src="${item.src}" alt="" loading="lazy" decoding="async" />
        </button>
      `,
    )
    .join("");
  setGalleryImage(0);
}

function renderTechnicalList() {
  if (!technicalList) return;
  const product = productData[currentProductId];
  technicalList.innerHTML = specRows
    .map(([key, label]) => `<div><dt>${label}</dt><dd>${product.specs[key]}</dd></div>`)
    .join("");
}

function renderBenefits() {
  if (!benefitGrid) return;
  benefitGrid.innerHTML = benefits
    .map(
      (benefit, index) => `
        <article>
          <span>0${index + 1}</span>
          <h3>${benefit.title}</h3>
          <p>${benefit.text}</p>
        </article>
      `,
    )
    .join("");
}

function renderRelatedModels() {
  if (!relatedModels) return;
  relatedModels.innerHTML = productIds
    .filter((id) => id !== currentProductId)
    .map((id) => {
      const product = productData[id];
      return `<a href="product.html?model=${id}"><span>Endüstriyel hava soğutucu</span><strong>ZIKA ${product.number} →</strong></a>`;
    })
    .join("");
}

function setProduct(productId, updateHistory = true) {
  currentProductId = normalizeProductId(productId);
  const product = productData[currentProductId];

  if (number) number.textContent = product.number;
  if (description) description.textContent = product.description;
  if (use) use.textContent = product.use;
  if (breadcrumb) breadcrumb.textContent = product.title;
  if (technicalTitle) technicalTitle.textContent = `${product.title} teknik özellikleri.`;
  if (metricAirflow) metricAirflow.textContent = product.specs.airflow;
  if (metricMotor) metricMotor.textContent = product.specs.motor;
  if (metricCurrent) metricCurrent.textContent = product.specs.current;
  if (metaDescription) {
    metaDescription.content = `${product.title} endüstriyel evaporatif hava soğutucunun görsellerini, teknik özelliklerini ve diğer ZIKA modelleriyle karşılaştırmasını inceleyin.`;
  }

  document.title = `${product.title} Endüstriyel Hava Soğutucu | Zikatec`;
  renderModelTabs();
  renderGallery();
  renderTechnicalList();
  renderBenefits();
  renderRelatedModels();

  if (updateHistory) {
    const url = new URL(window.location.href);
    url.searchParams.set("model", currentProductId);
    window.history.replaceState({ model: currentProductId }, "", url);
  }
}

function updateImageDialog() {
  const product = productData[currentProductId];
  const item = product.gallery[currentGalleryIndex];
  if (!item) return;
  if (dialogImage) {
    dialogImage.src = item.src;
    dialogImage.alt = item.alt;
  }
  if (imageCaption) imageCaption.textContent = `${product.title} — ${item.label}`;
  const hasMultipleImages = product.gallery.length > 1;
  if (imagePrev) imagePrev.hidden = !hasMultipleImages;
  if (imageNext) imageNext.hidden = !hasMultipleImages;
}

function changeDialogImage(direction) {
  const product = productData[currentProductId];
  currentGalleryIndex =
    (currentGalleryIndex + direction + product.gallery.length) % product.gallery.length;
  setGalleryImage(currentGalleryIndex);
  updateImageDialog();
}

function renderCompareOptions() {
  const options = productIds
    .map((id) => `<option value="${id}">${productData[id].title}</option>`)
    .join("");
  if (compareLeft) compareLeft.innerHTML = options;
  if (compareRight) compareRight.innerHTML = options;
}

function renderComparePanel(productId, otherProductId) {
  const product = productData[productId];
  const otherProduct = productData[otherProductId];
  const specs = specRows
    .map(([key, label]) => {
      const different = product.specs[key] !== otherProduct.specs[key];
      return `<div class="${different ? "is-different" : ""}"><dt>${label}</dt><dd>${product.specs[key]}</dd></div>`;
    })
    .join("");

  return `
    <div class="compare-side__hero">
      <div class="compare-side__image"><img src="${product.image}" alt="${product.title} endüstriyel hava soğutucu" /></div>
      <div class="compare-side__copy">
        <span>Endüstriyel evaporatif hava soğutucu</span>
        <h3>${product.title}</h3>
        <p>${product.description}</p>
      </div>
    </div>
    <dl class="compare-specs">${specs}</dl>
  `;
}

function renderComparison() {
  if (!compareLeft || !compareRight) return;
  const leftId = normalizeProductId(compareLeft.value);
  const rightId = normalizeProductId(compareRight.value);
  if (compareLeftPanel) compareLeftPanel.innerHTML = renderComparePanel(leftId, rightId);
  if (compareRightPanel) compareRightPanel.innerHTML = renderComparePanel(rightId, leftId);
}

modelTabs?.addEventListener("click", (event) => {
  const button = event.target.closest("[data-model-id]");
  if (button) setProduct(button.dataset.modelId);
});

thumbs?.addEventListener("click", (event) => {
  const button = event.target.closest("[data-gallery-index]");
  if (button) setGalleryImage(Number(button.dataset.galleryIndex));
});

document.querySelector("[data-gallery-open]")?.addEventListener("click", () => {
  updateImageDialog();
  imageDialog?.showModal();
});

document.querySelector("[data-image-close]")?.addEventListener("click", () => imageDialog?.close());
imagePrev?.addEventListener("click", () => changeDialogImage(-1));
imageNext?.addEventListener("click", () => changeDialogImage(1));

imageDialog?.addEventListener("click", (event) => {
  if (event.target === imageDialog) imageDialog.close();
});

imageDialog?.addEventListener("keydown", (event) => {
  if (event.key === "ArrowLeft") changeDialogImage(-1);
  if (event.key === "ArrowRight") changeDialogImage(1);
});

document.querySelector("[data-compare-open]")?.addEventListener("click", () => {
  if (!compareLeft || !compareRight) return;
  compareLeft.value = currentProductId;
  compareRight.value = getNextProductId(currentProductId);
  renderComparison();
  compareDialog?.showModal();
});

document.querySelector("[data-compare-close]")?.addEventListener("click", () => compareDialog?.close());

compareLeft?.addEventListener("change", () => {
  if (compareLeft.value === compareRight?.value && compareRight) {
    compareRight.value = getNextProductId(compareLeft.value);
  }
  renderComparison();
});

compareRight?.addEventListener("change", () => {
  if (compareRight.value === compareLeft?.value && compareLeft) {
    compareLeft.value = getNextProductId(compareRight.value);
  }
  renderComparison();
});

document.querySelector("[data-compare-swap]")?.addEventListener("click", () => {
  if (!compareLeft || !compareRight) return;
  const leftValue = compareLeft.value;
  compareLeft.value = compareRight.value;
  compareRight.value = leftValue;
  renderComparison();
});

compareDialog?.addEventListener("click", (event) => {
  if (event.target === compareDialog) compareDialog.close();
});

window.addEventListener("popstate", () => {
  const model = new URLSearchParams(window.location.search).get("model");
  setProduct(normalizeProductId(model), false);
});

renderCompareOptions();
const initialModel = new URLSearchParams(window.location.search).get("model");
setProduct(normalizeProductId(initialModel), false);
