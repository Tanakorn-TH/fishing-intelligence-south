/* คลื่นดี — Fishing Intelligence South
   หน้าเว็บดึงข้อมูลจริงจาก api/ ตาม docs/api-contract.md
   ส่วนที่ยังไม่มี backend (คะแนน ปฏิทินคะแนน) ยังเป็นชุดตัวอย่าง
   และถูกติดป้าย "ข้อมูลตัวอย่าง" ไว้ใน index.html ทุกจุด — ห้ามถอดป้ายออกจนกว่าจะมีข้อมูลจริง

   น้ำขึ้นน้ำลงเป็นข้อมูลจริงแล้ว แต่มีเงื่อนไขเรื่อง datum ที่ต้องบอกผู้ใช้เสมอ
   ดูคำอธิบายเหนือ loadTides() ก่อนแก้ส่วนนั้น */

/* เลขเวอร์ชัน — ที่นี่ที่เดียวเป็นแหล่งความจริง
   ปล่อยรุ่น = แก้เลขนี้ + สร้าง git tag ชื่อเดียวกัน (vX.Y.Z) แล้ว push tags
   ค่าใน index.html เป็นแค่ตัวสำรองตอน JS ยังไม่ทำงาน ต้องตรงกับค่านี้เสมอ */
const APP_VERSION = '0.3.1';

const TH_DAY_ABBR = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];
const TH_MONTH_ABBR = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
const TH_MONTH_FULL = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

/* ── ข้อมูลตัวอย่างที่ยังเหลืออยู่ ──────────────────────────────────────
   ปฏิทินคะแนนยังไม่มี endpoint (ดูตาราง "สิ่งที่ยังไม่มี" ใน docs/api-contract.md)
   ตรึงไว้ที่เดือนของชุดตัวอย่าง ปุ่มเลื่อนเดือนจึงยัง disabled อยู่
   ทุกที่ที่แสดงตัวเลขชุดนี้ต้องมีป้าย .sample-badge กำกับเสมอ */
const CALENDAR_YEAR = 2026;
const CALENDAR_MONTH = 7; // 0-indexed = สิงหาคม
const DAY_SCORES = [78, 65, 61, 42, 67, 74, 82, 91, 88, 79, 63, 69, 76, 80, 94, 89, 75, 68, 49, 64, 77, 83, 90, 73, 66, 45, 62, 76, 81, 87, 78];

/* ── จุดอ้างอิงสำหรับคิวรีสภาพอากาศ ───────────────────────────────────
   ยังไม่มีหมายจริงในฐานข้อมูล (ตาราง fishing_spots ว่าง) จึงใช้พิกัดตัวอย่าง
   ชุดเดียวกับที่ระบุไว้ใน docs/api-contract.md เป็นจุดตั้งต้นของอ่าวปัตตานี
   ไม่ใช่พิกัดหมายตกปลา และแสดงตัวเลขพิกัดให้ผู้ใช้เห็นตรง ๆ บนแถบบน
   พอผู้ใช้เลือกหมายจริงจาก /api/spots.php ค่านี้จะถูกแทนที่ด้วยพิกัดของหมายนั้น */
const REFERENCE_POINT = { lat: 6.87, lon: 101.25, label: 'ปัตตานี', isReference: true };

/* ตัดการรอที่ 8 วินาที — นานกว่านี้ผู้ใช้บนมือถือสัญญาณอ่อนจะคิดว่าหน้าค้าง */
const API_TIMEOUT_MS = 8000;

/* ═══ ชั้นเรียก API กลาง ═══════════════════════════════════════════════ */

class ApiError extends Error {
  constructor(message, status = 0) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
  }
}

/* ข้อความสำหรับรหัสสถานะที่สัญญา API กำหนดไว้
   404 อยู่ในนี้ด้วยเพราะ weather.php กับ solunar.php ยังไม่ถูกเขียน
   ผู้ใช้ต้องอ่านออกว่า "ยังไม่มี" ไม่ใช่ "พัง" */
function messageForStatus(status) {
  if (status === 404) return 'ยังไม่ได้เปิดให้บริการบนเซิร์ฟเวอร์ ทีมกำลังพัฒนาส่วนนี้อยู่';
  if (status === 400) return 'ค่าที่ส่งไปยังเซิร์ฟเวอร์ไม่ถูกต้อง';
  if (status === 405) return 'วิธีเรียกข้อมูลไม่ถูกต้อง';
  if (status === 502) return 'แหล่งข้อมูลภายนอกไม่ตอบกลับ ลองใหม่อีกครั้งภายหลัง';
  if (status === 503) return 'ระบบขัดข้องชั่วคราว ลองใหม่อีกครั้งภายหลัง';
  if (status >= 500) return 'เซิร์ฟเวอร์มีปัญหาภายใน ลองใหม่อีกครั้งภายหลัง';
  return `เซิร์ฟเวอร์ตอบกลับด้วยรหัส ${status}`;
}

/**
 * เรียก endpoint หนึ่งตัว แล้วคืน payload ตามสัญญา {data, meta}
 * ทุกความล้มเหลวถูกแปลงเป็น ApiError ที่มีข้อความภาษาไทยพร้อมแสดงต่อผู้ใช้
 * ไม่มีทางที่ error จากตัวใดตัวหนึ่งจะทำให้ส่วนอื่นของหน้าหยุดทำงาน
 * เพราะทุกจุดที่เรียกฟังก์ชันนี้ครอบ try/catch ของตัวเองไว้
 */
async function fetchJson(path, params = {}) {
  const url = new URL(path, window.location.href);
  Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, String(value)));

  const controller = new AbortController();
  const timer = window.setTimeout(() => controller.abort(), API_TIMEOUT_MS);

  let response;
  try {
    response = await fetch(url.toString(), {
      signal: controller.signal,
      headers: { Accept: 'application/json' },
    });
  } catch (error) {
    // abort กับเน็ตล่มมาทางเดียวกัน แยกด้วยสถานะของ signal
    if (controller.signal.aborted) {
      throw new ApiError('เซิร์ฟเวอร์ตอบช้าเกินไป (เกิน 8 วินาที)');
    }
    throw new ApiError('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ ตรวจสอบอินเทอร์เน็ตแล้วลองใหม่');
  } finally {
    window.clearTimeout(timer);
  }

  // อ่าน body ก่อนเช็คสถานะ เพราะฝั่ง error ก็ส่ง JSON ที่มีข้อความไทยมาให้เหมือนกัน
  let payload = null;
  try {
    payload = await response.json();
  } catch (error) {
    payload = null;
  }

  if (!response.ok) {
    const message = payload && payload.error && payload.error.message
      ? payload.error.message
      : messageForStatus(response.status);
    throw new ApiError(message, response.status);
  }

  if (!payload || typeof payload !== 'object') {
    throw new ApiError('คำตอบจากเซิร์ฟเวอร์ไม่ใช่ JSON ที่อ่านได้');
  }

  if (payload.error) {
    throw new ApiError(payload.error.message || 'เกิดข้อผิดพลาดจากเซิร์ฟเวอร์', response.status);
  }

  return payload;
}

/* ═══ สถานะสามแบบ: กำลังโหลด / ผิดพลาด / ไม่มีข้อมูล ═══════════════════
   ทุกส่วนของหน้าใช้กล่องเดียวกันหมด ผู้ใช้จึงอ่านรูปแบบเดียวได้ทั้งหน้า
   ห้ามมีส่วนไหนปล่อยว่างเปล่าโดยไม่บอกอะไรเลย */

const retryActions = {};

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function renderState(slot, state) {
  if (!slot) return;
  if (!state) {
    slot.innerHTML = '';
    slot.hidden = true;
    return;
  }

  const { kind, title, detail = '', retry = '' } = state;
  const mark = kind === 'loading'
    ? '<span class="state-spinner" aria-hidden="true"></span>'
    : `<span class="state-icon" aria-hidden="true">${kind === 'error' ? '!' : '⌖'}</span>`;
  const retryButton = retry
    ? `<button type="button" class="state-retry press" data-retry="${escapeHtml(retry)}">ลองใหม่</button>`
    : '';

  slot.hidden = false;
  slot.innerHTML = `<div class="state state-${escapeHtml(kind)}" role="status"${kind === 'loading' ? ' aria-busy="true"' : ''}>`
    + mark
    + `<div class="state-copy"><b>${escapeHtml(title)}</b>${detail ? `<small>${escapeHtml(detail)}</small>` : ''}</div>`
    + retryButton
    + '</div>';
}

document.addEventListener('click', (event) => {
  const button = event.target && event.target.closest ? event.target.closest('[data-retry]') : null;
  if (!button) return;
  const action = retryActions[button.dataset.retry];
  if (action) action();
});

/* ═══ ตัวช่วยทั่วไป ════════════════════════════════════════════════════ */

const toast = document.getElementById('toast');
let toastTimer;

function showToast(message) {
  toast.textContent = message;
  toast.classList.add('show');
  window.clearTimeout(toastTimer);
  toastTimer = window.setTimeout(() => toast.classList.remove('show'), 2600);
}

function formatThaiDate(date) {
  return `${TH_DAY_ABBR[date.getDay()]} ${date.getDate()} ${TH_MONTH_ABBR[date.getMonth()]} ${date.getFullYear() + 543}`;
}

/* วันที่แบบ ISO ของ "วันนี้" ตามเครื่องผู้ใช้ ใช้ส่งเป็นพารามิเตอร์ date
   ไม่ใช้ toISOString() เพราะมันแปลงเป็น UTC แล้ววันจะเพี้ยนก่อนเจ็ดโมงเช้า */
function isoDate(date) {
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${date.getFullYear()}-${month}-${day}`;
}

/* คำตอบจาก API เป็น ISO 8601 ที่มี offset +07:00 เสมอตามสัญญา
   จึงอ่าน HH:MM จากตัวสตริงตรง ๆ ไม่แปลงผ่าน Date
   ถ้าแปลงผ่าน Date เวลาจะถูกเลื่อนตาม timezone ของเครื่องที่เปิดดู
   ซึ่งผิดความหมาย — เวลาน้ำและ Solunar เป็นเวลาที่หน้างานเสมอ */
function formatIsoTime(iso) {
  const matched = /T(\d{2}:\d{2})/.exec(String(iso));
  return matched ? matched[1] : '—';
}

function formatRange(period) {
  if (!period || !period.start || !period.end) return null;
  return `${formatIsoTime(period.start)} - ${formatIsoTime(period.end)}`;
}

function roundTo(value, digits = 1) {
  const factor = 10 ** digits;
  return Math.round(Number(value) * factor) / factor;
}

function isFiniteNumber(value) {
  return typeof value === 'number' && Number.isFinite(value);
}

/* ใส่ค่า + หน่วยลงในตัวเลขใหญ่ของการ์ด — โครง <b>ค่า <em>หน่วย</em></b> เดิม */
function setMetric(element, value, unit) {
  if (!element) return;
  element.innerHTML = unit
    ? `${escapeHtml(value)} <em>${escapeHtml(unit)}</em>`
    : escapeHtml(value);
}

/* รหัสสภาพอากาศ WMO → สัญลักษณ์ที่ใช้อยู่เดิมในหน้า
   เป็นการแปลงเพื่อแสดงผลเท่านั้น ไม่ได้เดาค่าอะไรเพิ่ม */
function weatherGlyph(code) {
  if (!isFiniteNumber(code)) return '◌';
  if (code === 0) return '☀';
  if (code <= 2) return '⛅';
  if (code === 3) return '☁';
  if (code <= 48) return '≡';
  if (code <= 67) return '☂';
  if (code <= 77) return '❄';
  if (code <= 82) return '☂';
  if (code <= 86) return '❄';
  return '⚡';
}

/* บรรทัดที่มาของข้อมูล — สัญญา API บังคับให้ meta ติดไปกับข้อมูลเสมอ
   จึงต้องแสดงให้ผู้ใช้เห็น ไม่ใช่เก็บไว้ในโค้ด */
function renderSource(element, meta) {
  if (!element) return;
  if (!meta || !meta.source) {
    element.textContent = '';
    element.hidden = true;
    return;
  }
  const parts = [`ที่มา: ${meta.source}`];
  if (meta.license) parts.push(meta.license);
  if (meta.fetched_at) parts.push(`ดึงข้อมูล ${formatIsoTime(meta.fetched_at)} น.`);
  if (meta.cached) parts.push('จากแคช');
  element.hidden = false;
  element.innerHTML = meta.source_url
    ? `${escapeHtml(parts.join(' · '))} <a href="${escapeHtml(meta.source_url)}" rel="noreferrer">แหล่งอ้างอิง ↗</a>`
    : escapeHtml(parts.join(' · '));
}

/* ═══ แถบบนและคำทักทาย — คำนวณจาก Date เสมอ ═══════════════════════════ */

const now = new Date();
document.getElementById('todayLabel').textContent = formatThaiDate(now);
document.getElementById('appVersion').textContent = `v${APP_VERSION}`;

function greetingForHour(hour) {
  if (hour < 12) return 'สวัสดีตอนเช้า, นที';
  if (hour < 16) return 'สวัสดีตอนบ่าย, นที';
  if (hour < 19) return 'สวัสดีตอนเย็น, นที';
  return 'สวัสดีตอนค่ำ, นที';
}
document.getElementById('greeting').textContent = greetingForHour(now.getHours());

/* ตำแหน่งที่ใช้คิวรีตอนนี้ — เริ่มที่จุดอ้างอิง แล้วเปลี่ยนเมื่อผู้ใช้เลือกหมายจริง */
let activeLocation = { ...REFERENCE_POINT };

function renderLocation() {
  document.getElementById('locationName').textContent = activeLocation.label;
  const coords = `${roundTo(activeLocation.lat, 4)}, ${roundTo(activeLocation.lon, 4)}`;
  document.getElementById('locationCoords').textContent = activeLocation.isReference
    ? `พิกัดอ้างอิง ${coords}`
    : `หมายที่เลือก ${coords}`;
}
renderLocation();

/* ═══ สภาพอากาศ — GET /api/weather.php ═════════════════════════════════ */

const metricFields = [
  { value: 'metricWind', note: 'metricWindNote' },
  { value: 'metricWave', note: 'metricWaveNote' },
  { value: 'metricRain', note: 'metricRainNote' },
  { value: 'metricPressure', note: 'metricPressureNote' },
];

function setMetricsPlaceholder(note) {
  metricFields.forEach((field) => {
    setMetric(document.getElementById(field.value), '—', '');
    document.getElementById(field.note).textContent = note;
  });
}

function renderWeather(payload) {
  const current = (payload.data && payload.data.current) || null;
  const hourly = Array.isArray(payload.data && payload.data.hourly) ? payload.data.hourly : [];

  if (!current) {
    setMetricsPlaceholder('ไม่มีข้อมูล');
    renderState(document.getElementById('weatherState'), {
      kind: 'empty',
      title: 'ยังไม่มีค่าสภาพอากาศของจุดนี้',
      detail: 'เซิร์ฟเวอร์ตอบกลับสำเร็จแต่ไม่มีค่าปัจจุบันส่งมา',
      retry: 'weather',
    });
  } else {
    renderState(document.getElementById('weatherState'), null);

    setMetric(document.getElementById('metricWind'), roundTo(current.wind_speed_kmh), 'km/h');
    document.getElementById('metricWindNote').textContent = current.wind_direction_label || 'ไม่ระบุทิศ';

    // สัญญากำหนดว่า wave_height_m เป็น null ได้ ถ้าจุดนั้นไม่มีข้อมูลคลื่น ต้องบอกตรง ๆ
    if (isFiniteNumber(current.wave_height_m)) {
      setMetric(document.getElementById('metricWave'), roundTo(current.wave_height_m, 2), 'm');
      document.getElementById('metricWaveNote').textContent = 'ความสูงคลื่น';
    } else {
      setMetric(document.getElementById('metricWave'), 'ไม่มีข้อมูล', '');
      document.getElementById('metricWaveNote').textContent = 'จุดนี้ไม่มีข้อมูลคลื่น';
    }

    setMetric(document.getElementById('metricRain'), roundTo(current.precipitation_probability_pct, 0), '%');
    document.getElementById('metricRainNote').textContent = 'โอกาสมีฝน';

    setMetric(document.getElementById('metricPressure'), roundTo(current.pressure_hpa, 0), 'hPa');
    document.getElementById('metricPressureNote').textContent = current.observed_at
      ? `วัดเมื่อ ${formatIsoTime(current.observed_at)} น.`
      : 'ความกดอากาศระดับน้ำทะเล';

    document.getElementById('miniTemp').textContent = `${roundTo(current.temperature_c, 0)}°`;
    document.getElementById('miniNote').textContent = `${activeLocation.label} · ${formatIsoTime(current.observed_at)} น.`;
    document.getElementById('miniIcon').textContent = weatherGlyph(hourly.length ? hourly[0].weather_code : null);

    const waveText = isFiniteNumber(current.wave_height_m)
      ? `คลื่น ${roundTo(current.wave_height_m, 2)} ม.`
      : 'ไม่มีข้อมูลคลื่นที่จุดนี้';
    document.getElementById('heroSub').textContent =
      `ลม ${roundTo(current.wind_speed_kmh)} กม./ชม. · ${waveText} · โอกาสฝน ${roundTo(current.precipitation_probability_pct, 0)}%`;
  }

  renderHourly(hourly);
  renderSource(document.getElementById('weatherSource'), payload.meta);
}

function renderHourly(hourly) {
  const scroll = document.getElementById('forecastScroll');
  const slot = document.getElementById('forecastState');

  if (!hourly.length) {
    scroll.hidden = true;
    scroll.innerHTML = '';
    renderState(slot, {
      kind: 'empty',
      title: 'ยังไม่มีพยากรณ์รายชั่วโมง',
      detail: 'เซิร์ฟเวอร์ไม่ได้ส่งรายการชั่วโมงข้างหน้ามา',
      retry: 'weather',
    });
    return;
  }

  // ความสูงแท่ง = ความเร็วลมเทียบกับลมแรงสุดในชุดนี้ ไม่ใช่ค่าคงที่ที่ตั้งไว้เอง
  const speeds = hourly.map((hour) => (isFiniteNumber(hour.wind_speed_kmh) ? hour.wind_speed_kmh : 0));
  const peak = Math.max(...speeds, 1);

  scroll.innerHTML = hourly.map((hour, index) => {
    const height = Math.max(6, Math.round((speeds[index] / peak) * 100));
    const temperature = isFiniteNumber(hour.temperature_c) ? `${roundTo(hour.temperature_c, 0)}°` : '—';
    const wind = isFiniteNumber(hour.wind_speed_kmh) ? `${roundTo(hour.wind_speed_kmh)} km/h` : '—';
    return `<div class="hour${index === 0 ? ' active-hour' : ''}">`
      + `<b>${escapeHtml(formatIsoTime(hour.time))}</b>`
      + `<span>${weatherGlyph(hour.weather_code)}</span>`
      + `<strong>${escapeHtml(temperature)}</strong>`
      + `<small>${escapeHtml(wind)}</small>`
      + `<span class="bar"><i style="height:${height}%"></i></span>`
      + '</div>';
  }).join('');

  scroll.hidden = false;
  renderState(slot, null);
}

async function loadWeather() {
  const slot = document.getElementById('weatherState');
  setMetricsPlaceholder('กำลังโหลด…');
  document.getElementById('miniTemp').textContent = '—°';
  document.getElementById('miniNote').textContent = 'กำลังโหลดสภาพอากาศ…';
  document.getElementById('heroSub').textContent = 'กำลังดึงสภาพอากาศทะเลของจุดนี้…';
  renderState(slot, { kind: 'loading', title: 'กำลังโหลดสภาพอากาศทะเล…', detail: `พิกัด ${activeLocation.lat}, ${activeLocation.lon}` });
  renderState(document.getElementById('forecastState'), { kind: 'loading', title: 'กำลังโหลดพยากรณ์รายชั่วโมง…' });
  document.getElementById('forecastScroll').hidden = true;

  try {
    const payload = await fetchJson('api/weather.php', { lat: activeLocation.lat, lon: activeLocation.lon });
    renderWeather(payload);
  } catch (error) {
    setMetricsPlaceholder('ยังไม่มีข้อมูล');
    document.getElementById('miniTemp').textContent = '—°';
    document.getElementById('miniNote').textContent = 'ยังไม่มีข้อมูลสภาพอากาศ';
    document.getElementById('miniIcon').textContent = '◌';
    document.getElementById('heroSub').textContent = 'ยังดึงสภาพอากาศไม่ได้ ตัวเลขด้านล่างจึงยังว่างไว้ ไม่ได้เดาค่าแทน';
    renderState(slot, {
      kind: 'error',
      title: 'ยังไม่มีข้อมูลสภาพอากาศ',
      detail: error.message,
      retry: 'weather',
    });
    renderState(document.getElementById('forecastState'), {
      kind: 'error',
      title: 'ยังไม่มีพยากรณ์รายชั่วโมง',
      detail: error.message,
      retry: 'weather',
    });
    renderSource(document.getElementById('weatherSource'), null);
  }
}
retryActions.weather = loadWeather;

/* ═══ ดวงจันทร์และ Solunar — GET /api/solunar.php ═══════════════════════ */

function setSolunarPlaceholder(text) {
  document.getElementById('moonPhase').textContent = '—';
  document.getElementById('moonIllum').textContent = text;
  document.getElementById('majorTime').textContent = '—';
  document.getElementById('majorTimeNote').textContent = text;
  document.getElementById('minorTime').textContent = '—';
  document.getElementById('minorTimeNote').textContent = text;
  document.getElementById('majorWindowValue').textContent = text;
  document.getElementById('moonDisc').classList.add('is-unknown');
}

/* บอกว่าช่วงถัดไปจะมาถึงเมื่อไหร่ — เทียบเป็นเวลาสัมบูรณ์ผ่าน Date
   ตรงนี้ใช้ Date ได้ เพราะเปรียบเทียบ "ขณะเดียวกัน" ไม่ได้เอาไปแสดงเป็นเวลานาฬิกา */
function describeUpcoming(periods) {
  const stamp = Date.now();
  for (const period of periods) {
    const start = Date.parse(period.start);
    const end = Date.parse(period.end);
    if (!Number.isFinite(start) || !Number.isFinite(end)) continue;
    if (stamp >= start && stamp <= end) return 'กำลังอยู่ในช่วงนี้';
    if (stamp < start) {
      const minutes = Math.round((start - stamp) / 60000);
      if (minutes < 60) return `กำลังจะเริ่มใน ${minutes} นาที`;
      return `อีก ${Math.floor(minutes / 60)} ชั่วโมง ${minutes % 60} นาที`;
    }
  }
  return 'ผ่านไปแล้วทั้งหมดในวันนี้';
}

function nextPeriod(periods) {
  const stamp = Date.now();
  return periods.find((period) => Date.parse(period.end) >= stamp) || periods[0] || null;
}

function renderSolunar(payload) {
  const data = payload.data || {};
  const moon = data.moon || {};
  const majors = Array.isArray(data.major_periods) ? data.major_periods : [];
  const minors = Array.isArray(data.minor_periods) ? data.minor_periods : [];

  document.getElementById('moonDisc').classList.remove('is-unknown');
  document.getElementById('moonPhase').textContent = moon.phase_name_th || 'ไม่มีข้อมูลข้างขึ้นข้างแรม';
  document.getElementById('moonIllum').textContent = isFiniteNumber(moon.illumination_pct)
    ? `แสงจันทร์ ${roundTo(moon.illumination_pct, 0)}%`
    : 'ไม่มีค่าความสว่าง';

  /* เลื่อนเงาบนดวงจันทร์ตามเปอร์เซ็นต์แสงจริง เป็นภาพประกอบคร่าว ๆ ของเสี้ยว
     ไม่ได้อ้างว่าเป็นรูปทรงเงาที่ถูกต้องตามดาราศาสตร์ ตัวเลขจริงอยู่ในบรรทัดข้าง ๆ */
  if (isFiniteNumber(moon.illumination_pct)) {
    const shift = 1 + (Math.min(100, Math.max(0, moon.illumination_pct)) / 100) * 44;
    document.getElementById('moonShade').style.left = `${roundTo(shift, 1)}px`;
  }

  const majorText = majors.map(formatRange).filter(Boolean).join(' · ');
  document.getElementById('majorTime').textContent = majorText || 'ไม่มีช่วง Major ในวันนี้';
  document.getElementById('majorTimeNote').textContent = majors.length ? describeUpcoming(majors) : 'ระบบไม่พบช่วงเวลาสำหรับวันนี้';

  const minorText = minors.map(formatRange).filter(Boolean).join(' · ');
  document.getElementById('minorTime').textContent = minorText || 'ไม่มีช่วง Minor ในวันนี้';
  // จันทร์ขึ้น/จันทร์ตกเป็น null ได้ตามสัญญา ห้ามเดาค่าแทน
  const moonrise = data.moonrise ? `จันทร์ขึ้น ${formatIsoTime(data.moonrise)} น.` : 'วันนี้ไม่มีจันทร์ขึ้น';
  const moonset = data.moonset ? `จันทร์ตก ${formatIsoTime(data.moonset)} น.` : 'วันนี้ไม่มีจันทร์ตก';
  document.getElementById('minorTimeNote').textContent = `${moonrise} · ${moonset}`;

  const upcoming = nextPeriod(majors);
  document.getElementById('majorWindowValue').textContent = upcoming
    ? `${formatRange(upcoming)} · ${describeUpcoming([upcoming])}`
    : 'วันนี้ไม่มีช่วง Major';
  document.getElementById('majorWindow').classList.toggle('is-unknown', !upcoming);

  renderState(document.getElementById('solunarState'), null);
  renderSource(document.getElementById('solunarSource'), payload.meta);
}

async function loadSolunar() {
  const slot = document.getElementById('solunarState');
  setSolunarPlaceholder('กำลังโหลด…');
  document.getElementById('majorWindow').classList.remove('is-unknown');
  renderState(slot, { kind: 'loading', title: 'กำลังคำนวณช่วง Solunar…' });

  try {
    const payload = await fetchJson('api/solunar.php', {
      lat: activeLocation.lat,
      lon: activeLocation.lon,
      date: isoDate(new Date()),
    });
    renderSolunar(payload);
  } catch (error) {
    setSolunarPlaceholder('ยังไม่มีข้อมูล');
    document.getElementById('majorWindow').classList.add('is-unknown');
    renderState(slot, {
      kind: 'error',
      title: 'ยังไม่มีข้อมูลดวงจันทร์และ Solunar',
      detail: error.message,
      retry: 'solunar',
    });
    renderSource(document.getElementById('solunarSource'), null);
  }
}
retryActions.solunar = loadSolunar;

/* ═══ น้ำขึ้นน้ำลง — GET /api/tides.php ════════════════════════════════
   ⚠️ ค่าที่ได้อ้างอิงระดับน้ำทะเลปานกลาง (MSL) ไม่ใช่ระดับน้ำลงต่ำสุดแบบตารางน้ำทางการ
   ตัวเลขความลึกจึงเทียบกับตารางของกรมอุทกศาสตร์ไม่ได้ สิ่งที่ใช้ได้คือ "จังหวะ" น้ำขึ้นน้ำลง
   คำเตือนนี้มาจาก data.notice ของ API — ต้องแสดงให้ผู้ใช้เห็นเสมอ ห้ามซ่อนเพราะกินที่ */

/* ขนาดของ viewBox ในกราฟ SVG ต้องตรงกับที่เขียนไว้ใน index.html */
const TIDE_CHART_W = 700;
const TIDE_CHART_H = 165;

/* เว้นขอบบน-ล่างไว้ ไม่ให้ยอดคลื่นแตะขอบกราฟพอดีจนดูเหมือนโดนตัด */
const TIDE_CHART_PAD = 18;

function tideTrendLabel(trend) {
  if (trend === 'rising') return 'น้ำกำลังขึ้น';
  if (trend === 'falling') return 'น้ำกำลังลง';
  return 'ระดับน้ำคงที่';
}

/* สร้าง path ของเส้นกราฟจากชุดข้อมูลจริง
   ปรับสเกลแนวตั้งตามค่าต่ำสุด-สูงสุดของวันนั้น ไม่ใช่ค่าคงที่
   เพราะพิสัยน้ำอ่าวไทยเปลี่ยนตามน้ำเกิดน้ำตาย ถ้าตรึงสเกลไว้ วันน้ำตายจะดูแบนจนอ่านไม่ออก */
function tideChartPaths(series) {
  const heights = series.map((point) => point.height_m).filter(isFiniteNumber);
  if (heights.length < 2) return null;

  const min = Math.min(...heights);
  const max = Math.max(...heights);
  const span = max - min || 1; // กันหารศูนย์ในวันที่น้ำแทบไม่ขยับ
  const usable = TIDE_CHART_H - TIDE_CHART_PAD * 2;

  const points = series.map((point, index) => {
    const x = (index / (series.length - 1)) * TIDE_CHART_W;
    // แกน y ของ SVG ชี้ลง ค่าน้ำสูงจึงต้องได้ y น้อย
    const y = TIDE_CHART_PAD + (1 - (point.height_m - min) / span) * usable;
    return { x: roundTo(x, 1), y: roundTo(y, 1) };
  });

  const line = points.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x},${p.y}`).join(' ');
  return {
    line,
    area: `${line} L${TIDE_CHART_W},${TIDE_CHART_H} L0,${TIDE_CHART_H}Z`,
    points,
    min,
    max,
  };
}

function renderTideChart(series, currentIso) {
  const paths = tideChartPaths(series);
  const nowPoint = document.getElementById('tideNowPoint');
  const nowLine = document.getElementById('tideNowLine');

  if (!paths) {
    document.getElementById('tideLine').setAttribute('d', '');
    document.getElementById('tideArea').setAttribute('d', '');
    nowPoint.hidden = true;
    nowLine.hidden = true;
    document.getElementById('tideChartLabel').textContent = '';
    return;
  }

  document.getElementById('tideLine').setAttribute('d', paths.line);
  document.getElementById('tideArea').setAttribute('d', paths.area);
  document.getElementById('tideChartLabel').textContent =
    `สูงสุด ${roundTo(paths.max, 2)} m · ต่ำสุด ${roundTo(paths.min, 2)} m`;

  // เส้น "ตอนนี้" วางตามชั่วโมงปัจจุบันที่ API บอกมา ไม่ใช่เวลาเครื่องผู้ใช้
  // เพราะผู้ใช้อาจเปิดดูจากคนละเขตเวลา แต่ข้อมูลเป็นเวลาหน้างานเสมอ
  const index = currentIso
    ? series.findIndex((point) => point.time === currentIso)
    : -1;

  if (index >= 0 && paths.points[index]) {
    const p = paths.points[index];
    nowPoint.setAttribute('cx', String(p.x));
    nowPoint.setAttribute('cy', String(p.y));
    nowLine.setAttribute('x1', String(p.x));
    nowLine.setAttribute('x2', String(p.x));
    nowPoint.hidden = false;
    nowLine.hidden = false;
  } else {
    nowPoint.hidden = true;
    nowLine.hidden = true;
  }
}

function renderTideEvents(extremes) {
  const box = document.getElementById('tideEvents');
  if (!extremes.length) {
    box.innerHTML = '<p class="tide-empty">วันนี้ไม่พบจุดน้ำขึ้นหรือน้ำลงเต็มที่จากแบบจำลอง</p>';
    return;
  }

  box.innerHTML = extremes.map((event) => {
    const isHigh = event.type === 'high';
    const height = isFiniteNumber(event.height_m) ? `${roundTo(event.height_m, 2)}m` : '—';
    return '<div>'
      + `<span class="event-icon ${isHigh ? 'up' : 'down'}">${isHigh ? '↑' : '↓'}</span>`
      + `<p><small>${isHigh ? 'น้ำขึ้น' : 'น้ำลง'}</small><b>${escapeHtml(formatIsoTime(event.time))}</b></p>`
      + `<strong>${escapeHtml(height)}</strong>`
      + '</div>';
  }).join('');
}

/* หาจุดยอดถัดไปจากเวลาปัจจุบัน ใช้บอกว่า "น้ำกำลังขึ้นจนถึงกี่โมง" */
function nextTideEvent(extremes) {
  const stamp = Date.now();
  return extremes.find((event) => Date.parse(event.time) > stamp) || null;
}

function renderTides(payload) {
  const data = payload.data || {};
  const series = Array.isArray(data.series) ? data.series.filter((p) => p && isFiniteNumber(p.height_m)) : [];
  const extremes = Array.isArray(data.extremes) ? data.extremes : [];
  const current = data.current || null;

  document.getElementById('tideState').hidden = true;
  document.getElementById('tideState').innerHTML = '';
  document.getElementById('tideBody').hidden = false;

  // คำเตือนเรื่อง datum มาจาก API ตรง ๆ ไม่ได้เขียนซ้ำไว้ในหน้า
  // ถ้าวันหนึ่ง backend แก้คำเตือน หน้าเว็บจะเปลี่ยนตามเองโดยไม่ต้องแก้สองที่
  const notice = document.getElementById('tideNotice');
  if (data.notice) {
    notice.textContent = data.notice;
    notice.hidden = false;
    document.getElementById('tideDatumBadge').hidden = false;
  } else {
    notice.hidden = true;
    document.getElementById('tideDatumBadge').hidden = true;
  }

  if (current && isFiniteNumber(current.height_m)) {
    document.getElementById('tideTrend').textContent = tideTrendLabel(current.trend);
    setMetric(document.getElementById('tideHeight'), roundTo(current.height_m, 2), 'm');

    const upcoming = nextTideEvent(extremes);
    document.getElementById('tideTrendNote').textContent = upcoming
      ? `${upcoming.type === 'high' ? 'น้ำขึ้นเต็มที่' : 'น้ำลงเต็มที่'} ${formatIsoTime(upcoming.time)} น.`
      : 'ไม่มีจุดน้ำขึ้นน้ำลงเหลือในวันนี้';
  } else {
    // ดูวันอื่นที่ไม่ใช่วันนี้ — API ไม่ส่ง current มาให้โดยตั้งใจ ห้ามเดาเองว่า "ตอนนี้" คือเมื่อไหร่
    document.getElementById('tideTrend').textContent = 'ดูล่วงหน้า';
    document.getElementById('tideTrendNote').textContent = 'ไม่ใช่วันนี้ จึงไม่มีระดับน้ำ ณ ขณะนี้';
    setMetric(document.getElementById('tideHeight'), '—', '');
  }

  renderTideChart(series, current ? current.time : null);
  renderTideEvents(extremes);
  renderSource(document.getElementById('tideSource'), payload.meta);
}

async function loadTides() {
  const slot = document.getElementById('tideState');
  document.getElementById('tideBody').hidden = true;
  document.getElementById('tideNotice').hidden = true;
  document.getElementById('tideDatumBadge').hidden = true;
  renderState(slot, {
    kind: 'loading',
    title: 'กำลังโหลดระดับน้ำ…',
    detail: `พิกัด ${activeLocation.lat}, ${activeLocation.lon}`,
  });

  try {
    const payload = await fetchJson('api/tides.php', {
      lat: activeLocation.lat,
      lon: activeLocation.lon,
      date: isoDate(new Date()),
    });
    renderTides(payload);
  } catch (error) {
    document.getElementById('tideBody').hidden = true;
    renderState(slot, {
      kind: error.status === 400 ? 'empty' : 'error',
      title: error.status === 400 ? 'จุดนี้ยังไม่มีข้อมูลระดับน้ำ' : 'ยังไม่มีข้อมูลระดับน้ำ',
      detail: error.message,
      retry: 'tides',
    });
    renderSource(document.getElementById('tideSource'), null);
  }
}
retryActions.tides = loadTides;

/* ═══ หมายตกปลา — GET /api/spots.php ═══════════════════════════════════
   ตาราง fishing_spots ยังว่าง สถานะ "ไม่มีข้อมูล" คือสถานะปกติของตอนนี้
   ห้ามเติมหมายตัวอย่างลงไป ผู้ใช้จะเข้าใจผิดว่ามีพิกัดจริงให้ใช้ */

let loadedSpots = [];
let selectedSpot = null;

function spotStyleLabel(style) {
  if (style === 'shore') return 'ชายฝั่ง';
  if (style === 'boat') return 'เรือ';
  return style || 'ไม่ระบุรูปแบบ';
}

function spotCardMarkup(spot) {
  const depth = spot.depth;
  const depthLine = depth && isFiniteNumber(depth.typical_m)
    ? `<p class="spot-depth">ความลึกทั่วไป ${roundTo(depth.typical_m, 1)} ม.`
      + (depth.source ? ` · ที่มา ${escapeHtml(depth.source)}` : '')
      + `<small>${escapeHtml(depth.notice || 'ข้อมูลความลึกใช้เพื่อวางแผนตกปลาเท่านั้น ห้ามใช้เพื่อการเดินเรือ')}</small></p>`
    : '<p class="spot-depth">ยังไม่มีข้อมูลความลึกของหมายนี้</p>';

  return `<article class="spot-item glass-lite">`
    + `<p class="eyebrow">FISHING SPOT</p>`
    + `<h3>${escapeHtml(spot.name)}</h3>`
    + `<p class="spot-meta">${escapeHtml(spot.province || 'ไม่ระบุจังหวัด')} · ${escapeHtml(spotStyleLabel(spot.fishing_style))}</p>`
    + `<p class="spot-coords">${escapeHtml(`${roundTo(spot.coordinates.lat, 4)}, ${roundTo(spot.coordinates.lon, 4)}`)}</p>`
    + depthLine
    + `<button type="button" class="choose-spot press" data-spot-id="${escapeHtml(spot.id)}">เลือกหมายนี้ <span>→</span></button>`
    + '</article>';
}

function renderSpotPicker() {
  const picker = document.getElementById('spotPicker');
  const slot = document.getElementById('spotPickerState');
  const showCalendar = document.getElementById('showCalendar');

  if (!loadedSpots.length) {
    picker.hidden = true;
    picker.innerHTML = '';
    showCalendar.disabled = true;
    renderState(slot, {
      kind: 'empty',
      title: 'ยังไม่มีหมายให้เลือก',
      detail: 'ตารางหมายตกปลายังว่าง ระบบจะไม่ใส่พิกัดสมมติแทน',
      retry: 'spots',
    });
    return;
  }

  picker.innerHTML = loadedSpots.map((spot, index) => {
    const isSelected = selectedSpot ? spot.id === selectedSpot.id : index === 0;
    return `<button type="button" class="spot-option press glass-lite${isSelected ? ' selected' : ''}" data-spot-id="${escapeHtml(spot.id)}">`
      + '<span>⌖</span>'
      + `<span class="btn-text"><b>${escapeHtml(spot.name)}</b><small>${escapeHtml(spot.province || 'ไม่ระบุจังหวัด')} · ${escapeHtml(spotStyleLabel(spot.fishing_style))}</small></span>`
      + '<i>✓</i></button>';
  }).join('');

  picker.hidden = false;
  showCalendar.disabled = false;
  renderState(slot, null);
  if (!selectedSpot) selectSpot(loadedSpots[0].id, false);
}

function renderSpotList() {
  const list = document.getElementById('spotList');
  if (!loadedSpots.length) {
    renderState(list, {
      kind: 'empty',
      title: 'ยังไม่มีหมายตกปลาในระบบ',
      detail: 'ต้องรอผู้ดูแลกรอกพิกัดจริงก่อน ระบบจะไม่แสดงพิกัดที่ประมาณเอง',
      retry: 'spots',
    });
    list.hidden = false;
    return;
  }
  list.hidden = false;
  list.innerHTML = `<div class="spot-items">${loadedSpots.map(spotCardMarkup).join('')}</div>`;
}

function selectSpot(spotId, refresh = true) {
  const spot = loadedSpots.find((item) => String(item.id) === String(spotId));
  if (!spot) return;
  selectedSpot = spot;

  document.getElementById('selectedSpotLabel').textContent = spot.name;
  const picker = document.getElementById('spotPicker');
  picker.querySelector('.spot-option.selected')?.classList.remove('selected');
  picker.querySelector(`[data-spot-id="${CSS.escape(String(spot.id))}"]`)?.classList.add('selected');

  if (!refresh) return;

  // หมายจริงมีพิกัดจริง จึงย้ายจุดคิวรีไปที่นั่นแล้วโหลดสภาพอากาศใหม่
  activeLocation = {
    lat: spot.coordinates.lat,
    lon: spot.coordinates.lon,
    label: spot.name,
    isReference: false,
  };
  renderLocation();
  loadWeather();
  loadSolunar();
  loadTides();

  if (spot.depth && isFiniteNumber(spot.depth.typical_m)) {
    document.getElementById('gearDepth').value = String(roundTo(spot.depth.typical_m, 1));
  }
}

async function loadSpots() {
  renderState(document.getElementById('spotList'), { kind: 'loading', title: 'กำลังโหลดหมายตกปลา…' });
  renderState(document.getElementById('spotPickerState'), { kind: 'loading', title: 'กำลังโหลดรายการหมาย…' });
  document.getElementById('spotPicker').hidden = true;
  document.getElementById('showCalendar').disabled = true;

  try {
    const payload = await fetchJson('api/spots.php');
    loadedSpots = Array.isArray(payload.data) ? payload.data.filter((spot) => spot && spot.coordinates) : [];
    renderSpotList();
    renderSpotPicker();
  } catch (error) {
    loadedSpots = [];
    renderState(document.getElementById('spotList'), {
      kind: 'error',
      title: 'โหลดรายการหมายไม่สำเร็จ',
      detail: error.message,
      retry: 'spots',
    });
    renderState(document.getElementById('spotPickerState'), {
      kind: 'error',
      title: 'โหลดรายการหมายไม่สำเร็จ',
      detail: error.message,
      retry: 'spots',
    });
    document.getElementById('showCalendar').disabled = true;
  }
}
retryActions.spots = loadSpots;

document.getElementById('spotPicker').addEventListener('click', (event) => {
  const option = event.target.closest('.spot-option');
  if (option) selectSpot(option.dataset.spotId);
});

document.getElementById('spotList').addEventListener('click', (event) => {
  const button = event.target.closest('.choose-spot');
  if (!button) return;
  selectSpot(button.dataset.spotId);
  openPlanner();
});

/* ═══ อุปกรณ์แนะนำ — GET /api/gear.php ═════════════════════════════════ */

let gearStyle = 'shore';

function gearItemMarkup(rule) {
  const range = rule.depth_range_m
    ? `${roundTo(rule.depth_range_m.min, 1)}-${roundTo(rule.depth_range_m.max, 1)} ม.`
    : 'ไม่ระบุช่วงความลึก';
  const rows = [
    ['คัน', rule.rod],
    ['รอก', rule.reel],
    ['สายและลีดเดอร์', rule.line_and_leader],
    ['เหยื่อ / ริก', rule.lure_or_rig],
  ].filter((row) => row[1]);

  return '<li class="gear-item glass-lite">'
    + `<p class="gear-range">${escapeHtml(spotStyleLabel(rule.fishing_style))} · ${escapeHtml(range)}</p>`
    + `<dl>${rows.map((row) => `<dt>${escapeHtml(row[0])}</dt><dd>${escapeHtml(row[1])}</dd>`).join('')}</dl>`
    + (rule.safety_note ? `<p class="gear-safety"><span aria-hidden="true">⚠</span>${escapeHtml(rule.safety_note)}</p>` : '')
    + '</li>';
}

async function loadGear() {
  const list = document.getElementById('gearList');
  const slot = document.getElementById('gearState');
  const rawDepth = document.getElementById('gearDepth').value.trim();

  if (rawDepth === '') {
    list.hidden = true;
    list.innerHTML = '';
    renderState(slot, {
      kind: 'empty',
      title: 'ยังไม่ได้ระบุความลึก',
      detail: 'กรอกความลึกโดยประมาณเป็นเมตร แล้วกดดูอุปกรณ์',
    });
    return;
  }

  list.hidden = true;
  renderState(slot, { kind: 'loading', title: 'กำลังค้นกติกาอุปกรณ์…' });

  try {
    const payload = await fetchJson('api/gear.php', { style: gearStyle, depth: rawDepth });
    const rules = Array.isArray(payload.data) ? payload.data : [];
    if (!rules.length) {
      list.hidden = true;
      list.innerHTML = '';
      renderState(slot, {
        kind: 'empty',
        title: 'ไม่มีกติกาอุปกรณ์ที่ครอบคลุมความลึกนี้',
        detail: `${spotStyleLabel(gearStyle)} ที่ความลึก ${escapeHtml(rawDepth)} เมตร ยังไม่มีคำแนะนำในฐานข้อมูล`,
      });
      return;
    }
    list.innerHTML = rules.map(gearItemMarkup).join('');
    list.hidden = false;
    renderState(slot, null);
  } catch (error) {
    list.hidden = true;
    list.innerHTML = '';
    renderState(slot, {
      kind: 'error',
      title: 'ดึงคำแนะนำอุปกรณ์ไม่สำเร็จ',
      detail: error.message,
      retry: 'gear',
    });
  }
}
retryActions.gear = loadGear;

document.querySelectorAll('.gear-style').forEach((button) => {
  button.addEventListener('click', () => {
    gearStyle = button.dataset.style;
    document.querySelectorAll('.gear-style').forEach((other) => {
      const isActive = other === button;
      other.classList.toggle('selected', isActive);
      other.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
  });
});

document.getElementById('gearSubmit').addEventListener('click', loadGear);
document.getElementById('gearDepth').addEventListener('keydown', (event) => {
  if (event.key === 'Enter') loadGear();
});

/* ═══ บันทึกปลา — สคีมาผูก catch_logs กับ trip_plans เสมอ จึงต้องมีทริปก่อน ═══ */

let savedTrip = null;

document.getElementById('logCatch').addEventListener('click', () => {
  if (!savedTrip) {
    showToast('บันทึกปลาต้องอยู่ในทริป — เลือกหมายและวันก่อน');
    openPlanner();
    return;
  }
  showToast(`เปิดฟอร์มบันทึกปลา · ${savedTrip.spot} ${savedTrip.label}`);
});

document.querySelectorAll('.nav-item').forEach((link) => {
  link.addEventListener('click', () => {
    document.querySelector('.nav-item.active')?.classList.remove('active');
    link.classList.add('active');
  });
});

/* ═══ ปฏิทินทริป ══════════════════════════════════════════════════════ */

const planner = document.getElementById('planner');
const spotStep = document.getElementById('spotStep');
const calendarStep = document.getElementById('calendarStep');
const calendarGrid = document.getElementById('calendarGrid');
const pickedDate = document.getElementById('pickedDate');
const pickedReason = document.getElementById('pickedReason');
let pickedDay = null;

function openPlanner() {
  spotStep.hidden = false;
  calendarStep.hidden = true;
  planner.showModal();
}

document.querySelectorAll('.planner-link').forEach((button) => {
  button.addEventListener('click', (event) => {
    event.preventDefault();
    openPlanner();
  });
});

document.querySelector('.close-planner').addEventListener('click', () => planner.close());
document.querySelector('.change-spot').addEventListener('click', () => {
  spotStep.hidden = false;
  calendarStep.hidden = true;
});

document.getElementById('showCalendar').addEventListener('click', () => {
  if (!selectedSpot) {
    showToast('ยังไม่มีหมายให้เลือก จึงยังวางแผนวันไม่ได้');
    return;
  }
  document.getElementById('selectedSpotLabel').textContent = selectedSpot.name;
  spotStep.hidden = true;
  calendarStep.hidden = false;
});

function scoreTier(score) {
  if (score >= 85) return 'excellent';
  if (score >= 70) return 'good';
  if (score >= 55) return 'fair';
  return 'poor';
}

function scoreReason(score) {
  if (score >= 85) return 'จังหวะน้ำและ Solunar โดดเด่น เหมาะวางทริปล่วงหน้า';
  if (score >= 70) return 'สภาพรวมดี ควรตรวจลมและคลื่นใกล้ออกเดินทาง';
  return 'เงื่อนไขยังไม่เด่น แนะนำเลือกช่วงเวลาให้เหมาะกับหมาย';
}

function selectDay(day) {
  pickedDay = day;
  document.querySelector('.day.selected')?.classList.remove('selected');
  calendarGrid.querySelector(`[data-day="${day}"]`)?.classList.add('selected');
  const date = new Date(CALENDAR_YEAR, CALENDAR_MONTH, day);
  const score = DAY_SCORES[day - 1];
  // ย้ำคำว่าตัวอย่างซ้ำตรงบรรทัดที่ผู้ใช้อ่านก่อนกดบันทึกทริป
  pickedDate.textContent = `${formatThaiDate(date)} · คะแนนตัวอย่าง ${score}/100`;
  pickedReason.textContent = `${scoreReason(score)} (คำอธิบายจากคะแนนตัวอย่าง ยังไม่ใช่ผลคำนวณจริง)`;
}

function buildCalendar() {
  document.getElementById('calendarMonth').textContent = TH_MONTH_FULL[CALENDAR_MONTH];
  document.getElementById('calendarYear').textContent = CALENDAR_YEAR + 543;

  const isCurrentMonth = now.getFullYear() === CALENDAR_YEAR && now.getMonth() === CALENDAR_MONTH;
  const leadingBlanks = new Date(CALENDAR_YEAR, CALENDAR_MONTH, 1).getDay();
  const markup = [];

  for (let i = 0; i < leadingBlanks; i += 1) {
    markup.push('<button type="button" class="blank" disabled></button>');
  }
  DAY_SCORES.forEach((score, index) => {
    const day = index + 1;
    const today = isCurrentMonth && now.getDate() === day ? ' today' : '';
    markup.push(`<button type="button" class="day press ${scoreTier(score)}${today}" data-day="${day}"><b>${day}</b><span>${score}</span></button>`);
  });

  calendarGrid.innerHTML = markup.join('');
  calendarGrid.querySelectorAll('.day').forEach((button) => {
    button.addEventListener('click', () => selectDay(Number(button.dataset.day)));
  });

  selectDay(isCurrentMonth ? now.getDate() : 1);
}

buildCalendar();

document.getElementById('saveTrip').addEventListener('click', () => {
  if (!selectedSpot) {
    showToast('ยังไม่มีหมายให้บันทึกทริป');
    return;
  }
  savedTrip = {
    spot: selectedSpot.name,
    label: formatThaiDate(new Date(CALENDAR_YEAR, CALENDAR_MONTH, pickedDay)),
  };
  planner.close();
  showToast(`บันทึกทริป ${savedTrip.spot} · ${savedTrip.label} แล้ว`);
});

/* ขอบเรืองตามเคอร์เซอร์ — เขียนตำแหน่งเมาส์ให้ .spot::after ใน design.css
   จอสัมผัสไม่มีเคอร์เซอร์จริง ไม่ผูก listener เลย จะได้ไม่เปลืองแรงเครื่อง
   และคนที่ตั้งค่าลดการเคลื่อนไหวไว้ก็ไม่ต้องคำนวณอะไรทั้งนั้น */
if (window.matchMedia('(pointer: fine)').matches
    && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
  document.querySelectorAll('.spot').forEach((card) => {
    card.addEventListener('pointermove', (event) => {
      const box = card.getBoundingClientRect();
      card.style.setProperty('--mx', `${event.clientX - box.left}px`);
      card.style.setProperty('--my', `${event.clientY - box.top}px`);
    });
  });
}

/* ═══ เริ่มโหลดข้อมูลจริง ══════════════════════════════════════════════
   แต่ละก้อนจับ error ของตัวเองไว้แล้ว ตัวที่ล้มจึงไม่ลากตัวอื่นล้มตาม */
loadWeather();
loadSolunar();
loadTides();
loadSpots();
loadGear();
