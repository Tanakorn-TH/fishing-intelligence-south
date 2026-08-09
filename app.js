/* คลื่นดี — Fishing Intelligence South
   หน้าเว็บดึงข้อมูลจริงจาก api/ ตาม docs/api-contract.md
   ส่วนที่ยังไม่มี backend (ปฏิทินคะแนนรายเดือน) ยังเป็นชุดตัวอย่าง
   และถูกติดป้าย "ข้อมูลตัวอย่าง" ไว้ใน index.html ทุกจุด — ห้ามถอดป้ายออกจนกว่าจะมีข้อมูลจริง

   สองส่วนนี้เป็นข้อมูลจริงแล้ว แต่มีเงื่อนไขที่ต้องบอกผู้ใช้เสมอ อ่านก่อนแก้:
   - น้ำขึ้นน้ำลง: อ้างอิง datum คนละฐานกับตารางน้ำทางการ (ดูคำอธิบายเหนือ loadTides)
   - Fishing Score: น้ำหนักทีมเลือกเอง ไม่ได้ปรับจากสถิติการจับปลาจริง (ดูเหนือ loadScore) */

/* เลขเวอร์ชัน — ที่นี่ที่เดียวเป็นแหล่งความจริง
   ปล่อยรุ่น = แก้เลขนี้ + สร้าง git tag ชื่อเดียวกัน (vX.Y.Z) แล้ว push tags
   ค่าใน index.html เป็นแค่ตัวสำรองตอน JS ยังไม่ทำงาน ต้องตรงกับค่านี้เสมอ */
const APP_VERSION = '0.9.2';

const TH_DAY_ABBR = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];
const TH_MONTH_ABBR = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
const TH_MONTH_FULL = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

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

const FAVOURITES_KEY = 'fis.places.favourites.v1';
const LOCATION_KEY = 'fis.location.v1';
const GPS_ASKED_KEY = 'fis.gps.asked.v1';

/* หน่วงก่อนยิงค้นหา — สั้นพอให้รู้สึกทันใจ ยาวพอไม่ยิงทุกตัวอักษรที่พิมพ์ */
const SEARCH_DEBOUNCE_MS = 250;

let spotMap = null;
let mapSites = [];       // ปะการังเทียมและหมาย เก็บไว้หาพิกัดตอนผู้ใช้กดเลือก
let placeRows = [];
let favourites = [];
let searchSeq = 0;       // กันคำตอบเก่ามาทับคำตอบใหม่ตอนพิมพ์เร็ว ๆ
let searchTimer = null;

function readJsonSetting(key, fallback) {
  try {
    const raw = window.localStorage.getItem(key);
    return raw ? JSON.parse(raw) : fallback;
  } catch (error) {
    return fallback;
  }
}

function writeJsonSetting(key, value) {
  try {
    window.localStorage.setItem(key, JSON.stringify(value));
  } catch (error) {
    /* เก็บไม่ได้ก็ใช้งานรอบนี้ได้ ไม่ต้องรบกวนผู้ใช้ */
  }
}

/* ═══ แถบบนและคำทักทาย — คำนวณจาก Date เสมอ ═══════════════════════════ */

const now = new Date();

/* วันที่ที่กำลังดูอยู่ ค่าเริ่มต้นคือวันนี้
   ไม่เก็บลง localStorage โดยตั้งใจ — เปิดเว็บใหม่ควรได้วันนี้เสมอ
   เพราะคนเปิดดูก่อนออกเรือ ถ้าจำวันที่เก่าไว้จะอ่านสภาพอากาศของวันที่ผ่านไปแล้ว */
let activeDate = isoDate(now);

/* ขอบเขตที่เลือกได้ ผูกกับระยะที่แบบจำลองน้ำพยากรณ์ได้ (ดู FIS_TIDES_MAX_AHEAD_DAYS)
   ถ้าปล่อยให้เลือกไกลกว่านี้ ผู้ใช้จะกดแล้วเจอ error แทนที่จะเลือกไม่ได้ตั้งแต่แรก */
const DATE_MAX_AHEAD_DAYS = 7;
const DATE_MAX_BACK_DAYS = 30;
document.getElementById('appVersion').textContent = `v${APP_VERSION}`;

function greetingForHour(hour) {
  if (hour < 12) return 'สวัสดีตอนเช้า, นที';
  if (hour < 16) return 'สวัสดีตอนบ่าย, นที';
  if (hour < 19) return 'สวัสดีตอนเย็น, นที';
  return 'สวัสดีตอนค่ำ, นที';
}
document.getElementById('greeting').textContent = greetingForHour(now.getHours());

/* ตำแหน่งที่ใช้คิวรีตอนนี้
   ลำดับ: จุดที่ผู้ใช้เคยเลือกไว้ > จุดอ้างอิง
   ถ้ายังไม่เคยเลือกและยังไม่เคยถาม GPS จะขอตำแหน่งให้อัตโนมัติหลังหน้าโหลดเสร็จ
   หน้าเว็บไม่รอ GPS — แสดงด้วยจุดสำรองไปก่อนแล้วค่อยขยับ เพราะการรอสิทธิ์
   อาจกินเวลาหลายวินาที หรือผู้ใช้อาจไม่ตอบเลย */
let activeLocation = (() => {
  const saved = readJsonSetting(LOCATION_KEY, null);
  if (saved && isFiniteNumber(saved.lat) && isFiniteNumber(saved.lon)) {
    return saved;
  }
  return { ...REFERENCE_POINT };
})();

favourites = readJsonSetting(FAVOURITES_KEY, []);
if (!Array.isArray(favourites)) favourites = [];

function renderLocation() {
  document.getElementById('locationName').textContent = activeLocation.label;

  // บอกจังหวัดและฝั่งทะเลถ้ารู้ เพราะชื่ออำเภอซ้ำกันข้ามจังหวัดได้
  // และสองฝั่งมีสภาพคลื่นลมคนละแบบ ผู้ใช้ต้องอ่านออกว่ากำลังดูฝั่งไหน
  const parts = [];
  if (activeLocation.province) parts.push(String(activeLocation.province).replace('จังหวัด', 'จ.'));
  if (activeLocation.coast) parts.push(activeLocation.coast);

  // กองกลางทะเลต้องเห็นตัวเลขพิกัด เพราะคนจะเอาไปกดใส่เครื่องหาปลาต่อ
  // ชื่อจังหวัดอย่างเดียวพาไปถึงกองไม่ได้
  if (activeLocation.detail) parts.push(activeLocation.detail);

  if (parts.length) {
    document.getElementById('locationCoords').textContent = parts.join(' · ');
    return;
  }

  const coords = `${roundTo(activeLocation.lat, 4)}, ${roundTo(activeLocation.lon, 4)}`;
  document.getElementById('locationCoords').textContent = activeLocation.isReference
    ? `พิกัดอ้างอิง ${coords}`
    : coords;
}
renderLocation();

/* ═══ สภาพอากาศ — GET /api/weather.php ═════════════════════════════════ */

const metricFields = [
  { value: 'metricWind', note: 'metricWindNote' },
  { value: 'metricWave', note: 'metricWaveNote' },
  { value: 'metricRain', note: 'metricRainNote' },
  { value: 'metricPressure', note: 'metricPressureNote' },
  { value: 'metricSea', note: 'metricSeaNote' },
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

    /* อุณหภูมิน้ำมาจาก Marine API ซึ่งอาจไม่มีค่าที่จุดชิดฝั่งมาก ๆ
       ต้องบอกตรง ๆ เหมือนที่ทำกับความสูงคลื่น ห้ามเดาค่าให้ */
    if (isFiniteNumber(current.sea_temperature_c)) {
      setMetric(document.getElementById('metricSea'), formatSeaTemp(current.sea_temperature_c), '°C');
      document.getElementById('metricSeaNote').textContent = 'ผิวน้ำทะเล';
    } else {
      setMetric(document.getElementById('metricSea'), 'ไม่มีข้อมูล', '');
      document.getElementById('metricSeaNote').textContent = 'จุดนี้ไม่มีข้อมูลอุณหภูมิน้ำ';
    }

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
  renderSeaOutlook((payload.data && payload.data.sea_temperature_daily) || []);
  renderSeaFront(payload.data && payload.data.sea_front);
  renderChlorophyll(payload.data && payload.data.chlorophyll);
  renderSource(document.getElementById('weatherSource'), payload.meta);
}

/**
 * คลอโรฟิลล์-เอ — ตัวชี้แหล่งอาหาร
 *
 * บอก "แถวนี้มีฐานอาหารแค่ไหน" ซึ่งเป็นคุณสมบัติของ *พื้นที่* ไม่ใช่ของ *วัน*
 * จึงไม่ได้เอาเข้าสูตรคะแนน เพราะคะแนนตอบคำถามว่า "วันไหนควรไป"
 * ส่วนตัวเลขนี้ตอบว่า "ที่ไหนควรไป" ซึ่งเป็นคนละคำถาม
 *
 * เกณฑ์แบ่งระดับใช้ช่วงมาตรฐานทางสมุทรศาสตร์ ไม่ใช่ตั้งเอง
 */
function describeChlorophyll(value) {
  if (value < 0.2) return 'น้ำใสแบบทะเลเปิด ฐานอาหารน้อย';
  if (value < 1.0) return 'ผลผลิตปานกลาง';
  if (value < 3.0) return 'ผลผลิตสูง มีฐานอาหารดี';
  return 'ผลผลิตสูงมาก อาจขุ่นหรือมีแพลงก์ตอนบูม';
}

function renderChlorophyll(chl) {
  const row = document.getElementById('chlRow');

  if (!chl || !isFiniteNumber(chl.value_mg_m3)) {
    row.hidden = true;
    return;
  }

  row.hidden = false;
  const value = chl.value_mg_m3;

  document.getElementById('chlValue').textContent =
    `คลอโรฟิลล์ ${value.toFixed(2)} mg/m³ · ${describeChlorophyll(value)}`;

  const bits = [];
  if (chl.observed_month) bits.push(`ค่าเฉลี่ยเดือน ${chl.observed_month}`);
  if (isFiniteNumber(chl.min_mg_m3) && isFiniteNumber(chl.max_mg_m3)) {
    bits.push(`ในกรอบ 9 กม. อยู่ระหว่าง ${chl.min_mg_m3}–${chl.max_mg_m3}`);
  }
  // บอกว่าเป็นค่ารายเดือน ไม่ใช่ของวันนี้ ไม่งั้นคนจะอ่านผิดว่าเป็นสภาพวันนี้
  bits.push('เป็นค่ารายเดือนจากดาวเทียม ไม่ใช่สภาพของวันนี้');
  // ค่าที่เสิร์ฟจากของเก่าเพราะปลายทางล้ม ต้องบอก ไม่ใช่ปล่อยให้เข้าใจว่าเพิ่งดึงมา
  if (chl.is_stale) bits.push('ดึงใหม่ไม่สำเร็จ กำลังแสดงค่าที่เคยดึงไว้');
  document.getElementById('chlDetail').textContent = bits.join(' · ');

  // จุดสีไล่ตามความเข้มข้น ใช้สเกลเขียวเพราะคลอโรฟิลล์คือรงควัตถุสีเขียว
  const dot = document.getElementById('chlDot');
  dot.style.background = value < 0.2 ? '#8fd4e8'
    : (value < 1.0 ? '#7fc98f' : (value < 3.0 ? '#4da35c' : '#2f7a3d'));
}

/**
 * แนวน้ำ — ความชันของอุณหภูมิรอบจุดที่เลือก
 *
 * แสดงทั้งความแรงและทิศที่น้ำอุ่นกว่าอยู่ เพราะทิศคือสิ่งที่เอาไปใช้ได้จริง
 * รู้ว่า "มีแนวน้ำ" อย่างเดียวแล้วไม่รู้ว่าอยู่ทางไหน ก็ทำอะไรต่อไม่ได้
 *
 * เกณฑ์ 0.06 องศา/กม. มาจากงานวิจัย ไม่ใช่จากค่าที่เราวัดได้เอง
 * น่านน้ำเราวัดได้ราว 0.004-0.06 จึงมักขึ้นว่า "แทบไม่มีแนวน้ำ" ซึ่งเป็นความจริง
 */
const FRONT_FULL_GRADIENT = 0.06;

function renderSeaFront(front) {
  const box = document.getElementById('seaFront');

  if (!front || !isFiniteNumber(front.gradient_c_per_km)) {
    box.hidden = true;
    return;
  }

  box.hidden = false;

  const gradient = front.gradient_c_per_km;
  const share = gradient / FRONT_FULL_GRADIENT;
  const strength = share >= 1 ? 'แนวน้ำชัด'
    : (share >= 0.5 ? 'มีแนวน้ำอ่อน ๆ' : 'แทบไม่มีแนวน้ำ');

  document.getElementById('seaFrontStrength').textContent =
    `${strength} · ${gradient.toFixed(3)} °C/กม.`;

  const bits = [];
  if (front.warmer_toward_label) bits.push(`น้ำอุ่นกว่าอยู่ทาง${front.warmer_toward_label}`);
  if (isFiniteNumber(front.baseline_km)) bits.push(`วัดจากจุดห่างกัน ${roundTo(front.baseline_km, 1)} กม.`);
  // บอกตรง ๆ เมื่อได้ข้อมูลแค่แกนเดียว เพราะความมั่นใจต่างกันจริง
  if (front.axes_used === 1) bits.push('มีข้อมูลแกนเดียว (อีกด้านเป็นแผ่นดิน)');
  document.getElementById('seaFrontDetail').textContent = bits.join(' · ');

  // เข็มชี้ทิศที่น้ำอุ่นกว่า หมุนตามองศาจริง 0 องศาคือทิศเหนือ
  const dial = document.getElementById('seaFrontDial');
  if (isFiniteNumber(front.warmer_toward_deg)) {
    dial.style.transform = `rotate(${front.warmer_toward_deg}deg)`;
    dial.hidden = false;
  } else {
    dial.hidden = true;
  }
}

/* อุณหภูมิน้ำต้องมีทศนิยมหนึ่งตำแหน่งเสมอ
   roundTo ตัดศูนย์ท้ายทิ้ง ทำให้ 32.0 กลายเป็น 32 แล้วเลขในแถวเดียวกันกว้างไม่เท่ากัน
   ที่สำคัญกว่านั้นคือความต่างระดับ 0.1 องศาเป็นสิ่งที่เราตั้งใจให้เห็น จึงห้ามตัดทิ้ง */
function formatSeaTemp(value) {
  return isFiniteNumber(value) ? Number(value).toFixed(1) : '—';
}

/**
 * พยากรณ์อุณหภูมิน้ำรายวัน
 *
 * ทำไมแสดงเป็นแถบรายวัน ไม่ใช่กราฟรายชั่วโมง:
 * วัดจริงจากข้อมูลปัจจุบันแล้ว อุณหภูมิน้ำแกว่งในวันเดียวราว 0.4 องศา
 * และต่างกันแค่ 0.01 องศาระหว่างวันแรกกับวันที่แปด
 * กราฟรายชั่วโมงจึงเป็นเส้นแบนที่ทำให้คนคิดว่าระบบพัง ทั้งที่ข้อมูลถูก
 *
 * แถบรายวันพร้อมช่วงต่ำสุด-สูงสุดบอกความจริงข้อนั้นตรง ๆ ว่า "นิ่ง"
 * และจะเห็นความต่างชัดตอนเปลี่ยนฤดูมรสุมซึ่งเป็นตอนที่ตัวเลขนี้มีความหมายจริง
 */
function renderSeaOutlook(daily) {
  const box = document.getElementById('seaOutlook');

  if (!Array.isArray(daily) || daily.length === 0) {
    box.hidden = true;
    return;
  }

  const means = daily.map((day) => day.mean_c).filter(isFiniteNumber);
  if (means.length === 0) {
    box.hidden = true;
    return;
  }

  box.hidden = false;

  const low = Math.min(...means);
  const high = Math.max(...means);
  const spread = high - low;

  document.getElementById('seaOutlookRange').textContent =
    `${formatSeaTemp(low)}–${formatSeaTemp(high)} °C ตลอด ${daily.length} วัน`;

  const today = isoDate(new Date());
  document.getElementById('seaDays').innerHTML = daily.map((day) => {
    const date = dateFromIso(day.date);
    const mean = formatSeaTemp(day.mean_c);
    const range = isFiniteNumber(day.min_c) && isFiniteNumber(day.max_c)
      ? `${formatSeaTemp(day.min_c)}–${formatSeaTemp(day.max_c)}`
      : '—';
    const on = day.date === activeDate ? ' on' : '';
    const label = day.date === today ? 'วันนี้' : TH_DAY_ABBR[date.getDay()];
    return `<div class="sea-day${on}">`
      + `<small>${escapeHtml(label)}</small>`
      + `<b>${escapeHtml(String(mean))}°</b>`
      + `<span>${escapeHtml(range)}</span>`
      + '</div>';
  }).join('');

  /* บอกความหมายของตัวเลข ไม่ใช่โยนตัวเลขทิ้งไว้เฉย ๆ
     ครึ่งองศาในหนึ่งสัปดาห์แปลว่าไม่มีแนวน้ำเปลี่ยนให้ตามในช่วงนี้ */
  const change = spread < 0.3
    ? 'แทบไม่เปลี่ยนตลอดช่วงนี้'
    : `เปลี่ยนได้ถึง ${formatSeaTemp(spread)}°C ระหว่างวันในช่วงนี้`;

  document.getElementById('seaNote').textContent =
    `อุณหภูมิน้ำ${change} · ตัวเลขนี้เป็นค่าที่จุดเดียว `
    + 'จึงบอกได้แค่ว่าน้ำตรงนี้อุ่นหรือเย็นลง '
    + 'บอกไม่ได้ว่ามีแนวน้ำ (thermal front) อยู่ตรงไหน เพราะแนวน้ำคือความต่างตามระยะทาง ไม่ใช่ตามวัน';
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
    // ติดสัญลักษณ์คลื่นไว้หน้าอุณหภูมิน้ำ ให้แยกออกจากอุณหภูมิอากาศที่อยู่บรรทัดบน
    const sea = isFiniteNumber(hour.sea_temperature_c) ? `≋ ${formatSeaTemp(hour.sea_temperature_c)}°` : '';
    return `<div class="hour${index === 0 ? ' active-hour' : ''}">`
      + `<b>${escapeHtml(formatIsoTime(hour.time))}</b>`
      + `<span>${weatherGlyph(hour.weather_code)}</span>`
      + `<strong>${escapeHtml(temperature)}</strong>`
      + `<small>${escapeHtml(wind)}</small>`
      + (sea ? `<em class="hour-sea">${escapeHtml(sea)}</em>` : '')
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
      date: activeDate,
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

/* ═══ เลือกจุด/หมาย — GET /api/places.php ══════════════════════════════
   รายการเรียงตามระยะทางจากจุดที่ดูอยู่ ที่ติดดาวขึ้นก่อนเสมอ
   แผนที่กับรายการเป็นมุมมองสองแบบของข้อมูลชุดเดียวกัน เลือกจากทางไหนก็ได้ผลเหมือนกัน */

function isFavourite(id) {
  return favourites.includes(id);
}

function toggleFavourite(id) {
  favourites = isFavourite(id)
    ? favourites.filter((item) => item !== id)
    : favourites.concat([id]);
  writeJsonSetting(FAVOURITES_KEY, favourites);
  renderPlaceList();
}

/* ที่ติดดาวขึ้นบนสุด ที่เหลือคงลำดับตามระยะทางที่ API ส่งมา */
function sortedPlaceRows() {
  const starred = placeRows.filter((row) => isFavourite(row.id));
  const rest = placeRows.filter((row) => !isFavourite(row.id));
  return { starred, rest };
}

function placeRowMarkup(row) {
  const starred = isFavourite(row.id);
  const distance = isFiniteNumber(row.distance_km)
    ? `<span class="place-distance">${roundTo(row.distance_km, row.distance_km < 10 ? 1 : 0)} กม.</span>`
    : '';
  const active = row.id === activeLocation.id;

  return `<li class="place-row${active ? ' is-active' : ''}" data-place-id="${escapeHtml(row.id)}">`
    + `<button type="button" class="place-star press" data-star="${escapeHtml(row.id)}"`
    + ` aria-pressed="${starred ? 'true' : 'false'}" aria-label="${starred ? 'เอาดาวออกจาก' : 'ติดดาว'} ${escapeHtml(row.name)}">`
    + `${starred ? '★' : '☆'}</button>`
    + `<button type="button" class="place-pick press" data-pick="${escapeHtml(row.id)}">`
    + `<span class="place-name">${escapeHtml(row.name)}</span>`
    + `<small>${escapeHtml(row.province)}${row.coast_label ? ` · ${escapeHtml(row.coast_label)}` : ''}</small>`
    + '</button>'
    + distance
    + '</li>';
}

function renderPlaceList() {
  const list = document.getElementById('placeList');
  const { starred, rest } = sortedPlaceRows();

  if (!placeRows.length) {
    list.innerHTML = '';
    return;
  }

  let markup = '';
  if (starred.length) {
    markup += '<li class="place-group">★ ที่ติดดาวไว้</li>' + starred.map(placeRowMarkup).join('');
    if (rest.length) markup += '<li class="place-group">เรียงตามระยะทาง</li>';
  }
  markup += rest.map(placeRowMarkup).join('');

  list.innerHTML = markup;

  if (spotMap) {
    spotMap.setPlaces(placeRows);
    spotMap.setSelected(activeLocation.id || null);
  }
}

async function loadPlaces(query = '') {
  const slot = document.getElementById('placeState');
  const seq = ++searchSeq;

  renderState(slot, { kind: 'loading', title: 'กำลังค้นหา…' });

  try {
    // ไม่ส่ง limit ตอนเปิดดูรายการ เพื่อให้ได้ครบทุกจุดตามที่ backend ตั้งใจไว้
    // เคยส่ง 100 ไว้ตายตัว พอชุดข้อมูลโตเกินนั้น จังหวัดท้าย ๆ หายไปจากทั้งรายการและแผนที่
    const params = { lat: activeLocation.lat, lon: activeLocation.lon };
    if (query) {
      params.q = query;
      params.limit = 20;
    }
    const payload = await fetchJson('api/places.php', params);

    // คำตอบของคำค้นเก่ามาช้ากว่าคำใหม่ได้ ทิ้งไปเลยไม่ต้องวาด
    if (seq !== searchSeq) return;

    placeRows = Array.isArray(payload.data) ? payload.data : [];
    renderState(slot, placeRows.length ? null : {
      kind: 'empty',
      title: 'ไม่พบสถานที่ที่ค้นหา',
      detail: 'ลองพิมพ์ชื่ออำเภอหรือจังหวัดแทน',
    });
    renderPlaceList();
    document.getElementById('placeNotice').textContent = (payload.meta && payload.meta.notice) || '';
  } catch (error) {
    if (seq !== searchSeq) return;
    placeRows = [];
    document.getElementById('placeList').innerHTML = '';
    renderState(slot, {
      kind: 'error',
      title: 'ค้นหาไม่สำเร็จ',
      detail: error.message,
      retry: 'places',
    });
  }
}
retryActions.places = () => loadPlaces(document.getElementById('placeSearch').value.trim());

/* เปลี่ยนจุดที่กำลังดู แล้วโหลดข้อมูลทุกก้อนใหม่ */
function applyLocation(next, { remember = true } = {}) {
  activeLocation = next;
  renderLocation();
  if (remember) writeJsonSetting(LOCATION_KEY, next);

  loadWeather();
  loadSolunar();
  loadTides();
  loadScore();

  if (spotMap) spotMap.setSelected(next.id || null);
}

function pickPlace(id) {
  const row = placeRows.find((item) => item.id === id);
  if (!row) return;

  // เลือกหมายจากรายการแล้ว ต้องปลดไฮไลต์ของกองที่เคยกดไว้
  // ไม่งั้นแผนที่จะดูเหมือนกำลังเลือกอยู่สองที่พร้อมกัน
  if (spotMap) spotMap.setSelectedSite(null);

  applyLocation({
    id: row.id,
    lat: row.lat,
    lon: row.lon,
    label: row.name,
    province: row.province,
    coast: row.coast_label || '',
    isReference: false,
  });

  if (spotMap) spotMap.focus(row.lat, row.lon);
  renderPlaceList();
}

/* ── ตำแหน่งจาก GPS ────────────────────────────────────────────────────
   ขอครั้งแรกที่เข้าเว็บเท่านั้น ถ้าเคยเลือกจุดไว้แล้วจะไม่รบกวนอีก
   ไม่บล็อกการแสดงผล — หน้าเว็บโหลดด้วยจุดสำรองไปก่อน แล้วค่อยขยับเมื่อได้ตำแหน่ง */
function requestGps({ silent = false } = {}) {
  if (!navigator.geolocation) {
    if (!silent) showToast('อุปกรณ์นี้ไม่รองรับการหาตำแหน่ง');
    return;
  }

  if (!silent) showToast('กำลังหาตำแหน่ง…');

  navigator.geolocation.getCurrentPosition(
    async (position) => {
      const lat = roundTo(position.coords.latitude, 4);
      const lon = roundTo(position.coords.longitude, 4);

      try {
        // แปลงพิกัดดิบเป็นชื่อที่อ่านรู้เรื่อง โดยบอกระยะห่างตรง ๆ
        // ไม่เคลมว่าผู้ใช้อยู่ "ที่" สถานที่นั้น เพราะอาจห่างหลายสิบกิโลเมตร
        const payload = await fetchJson('api/places.php', { lat, lon, limit: 1 });
        const nearest = (payload.data || [])[0];
        const inRegion = payload.meta && payload.meta.in_region;

        applyLocation({
          id: null,
          lat,
          lon,
          label: nearest && inRegion ? `ใกล้ ${nearest.name}` : 'ตำแหน่งของฉัน',
          province: nearest && inRegion ? nearest.province : '',
          coast: nearest && inRegion ? (nearest.coast_label || '') : '',
          isReference: false,
          isGps: true,
          nearestKm: nearest ? nearest.distance_km : null,
        });

        if (spotMap) {
          spotMap.setOrigin({ lat, lon });
          spotMap.focus(lat, lon);
        }
        if (!inRegion) showToast('ตำแหน่งของคุณอยู่นอกภาคใต้ ข้อมูลบางส่วนอาจไม่ครอบคลุม');
        loadPlaces(document.getElementById('placeSearch').value.trim());
      } catch (error) {
        if (!silent) showToast('หาชื่อสถานที่ใกล้เคียงไม่ได้');
      }
    },
    () => {
      if (!silent) showToast('ไม่ได้รับอนุญาตให้ใช้ตำแหน่ง เลือกจุดเองได้จากรายการ');
    },
    { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 }
  );
}

/* คำอธิบายเส้นความลึก — สร้างจากข้อมูลจริงที่โหลดมา ไม่ได้เขียนตัวเลขตายตัวไว้
   ถ้าวันหนึ่งเปลี่ยนระดับความลึกที่วาด คำอธิบายจะเปลี่ยนตามเอง ไม่หลุดจากกัน */
function renderDepthLegend(depth) {
  const legend = document.getElementById('depthLegend');
  const levels = (depth.features || [])
    .map((feature) => feature.properties.depth_m)
    .filter(isFiniteNumber)
    .sort((a, b) => a - b);

  if (!levels.length) {
    legend.hidden = true;
    return;
  }

  legend.hidden = false;
  legend.innerHTML = levels.map((metres) =>
    `<span class="depth-key"><i style="border-top-color:${escapeHtml(DEPTH_COLORS[metres] || '#2b6291')}"></i>${metres}</span>`
  ).join('') + '<span class="depth-unit">เมตร</span>';
}

/* ── ตัวเลือกวันที่บนแถบบน ─────────────────────────────────────────────
   คะแนน น้ำ และ Solunar คิดตามวันที่ที่เลือก ส่วนแถบสภาพอากาศยังเป็นค่าปัจจุบัน
   เพราะ /api/weather.php ตอบสภาพ ณ ตอนนี้ ไม่ได้ตอบรายวัน
   ความต่างนี้ต้องบอกผู้ใช้ตรง ๆ ไม่ใช่ปล่อยให้เดาเอง จึงมีแถบเตือนด้านล่างแถบบน */

function dateFromIso(iso) {
  const [year, month, day] = String(iso).split('-').map(Number);
  return new Date(year, month - 1, day);
}

function shiftDays(base, days) {
  const next = new Date(base.getFullYear(), base.getMonth(), base.getDate() + days);
  return next;
}

function isToday(iso) {
  return iso === isoDate(new Date());
}

function describeDate(iso) {
  const today = isoDate(new Date());
  if (iso === today) return 'วันนี้';
  if (iso === isoDate(shiftDays(new Date(), 1))) return 'พรุ่งนี้';
  if (iso === isoDate(shiftDays(new Date(), -1))) return 'เมื่อวาน';
  return formatThaiDate(dateFromIso(iso));
}

function renderDateControl() {
  document.getElementById('todayLabel').textContent = describeDate(activeDate);

  const banner = document.getElementById('dateBanner');
  if (isToday(activeDate)) {
    banner.hidden = true;
    return;
  }

  banner.hidden = false;
  banner.textContent = `กำลังดู ${formatThaiDate(dateFromIso(activeDate))}`
    + ' · คะแนน น้ำ และ Solunar เป็นของวันที่เลือก'
    + ' ส่วนแถบลม-คลื่น-ฝน ยังเป็นค่า ณ ตอนนี้';
}

function renderDateQuick() {
  const today = new Date();
  const chips = [];
  for (let offset = 0; offset <= DATE_MAX_AHEAD_DAYS; offset++) {
    const day = shiftDays(today, offset);
    const iso = isoDate(day);
    chips.push(
      `<button type="button" class="date-chip press${iso === activeDate ? ' on' : ''}" data-date="${iso}">`
      + `<small>${TH_DAY_ABBR[day.getDay()]}</small><b>${day.getDate()}</b>`
      + `<span>${TH_MONTH_ABBR[day.getMonth()]}</span></button>`
    );
  }
  document.getElementById('dateQuick').innerHTML = chips.join('');
}

function applyDate(iso) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) return;

  // กันไว้ตั้งแต่ฝั่งหน้าเว็บ ดีกว่าปล่อยให้ยิงไปแล้วได้ error 400 กลับมา
  const today = new Date();
  const min = isoDate(shiftDays(today, -DATE_MAX_BACK_DAYS));
  const max = isoDate(shiftDays(today, DATE_MAX_AHEAD_DAYS));
  if (iso < min || iso > max) {
    showToast(`เลือกได้ระหว่าง ${formatThaiDate(dateFromIso(min))} ถึง ${formatThaiDate(dateFromIso(max))}`);
    return;
  }

  activeDate = iso;
  renderDateControl();
  renderDateQuick();
  document.getElementById('dateInput').value = iso;

  // สภาพอากาศไม่ต้องโหลดใหม่ เพราะ endpoint นั้นตอบค่าปัจจุบันอย่างเดียว
  loadSolunar();
  loadTides();
  loadScore();
}

function openDatePicker() {
  const today = new Date();
  const input = document.getElementById('dateInput');
  input.min = isoDate(shiftDays(today, -DATE_MAX_BACK_DAYS));
  input.max = isoDate(shiftDays(today, DATE_MAX_AHEAD_DAYS));
  input.value = activeDate;

  document.getElementById('dateNotice').textContent =
    `เลือกล่วงหน้าได้ ${DATE_MAX_AHEAD_DAYS} วัน เท่าที่แบบจำลองระดับน้ำพยากรณ์ได้`
    + ` และย้อนหลังได้ ${DATE_MAX_BACK_DAYS} วัน`;

  renderDateQuick();
  document.getElementById('datePicker').showModal();
}

document.getElementById('openDatePicker').addEventListener('click', openDatePicker);
document.querySelector('.close-date').addEventListener('click', () => {
  document.getElementById('datePicker').close();
});
document.getElementById('dateQuick').addEventListener('click', (event) => {
  const chip = event.target.closest('[data-date]');
  if (!chip) return;
  applyDate(chip.dataset.date);
  document.getElementById('datePicker').close();
});
document.getElementById('dateInput').addEventListener('change', (event) => {
  applyDate(event.target.value);
  document.getElementById('datePicker').close();
});

renderDateControl();

/* ── ชั้นข้อมูลของแผนที่กลาง ───────────────────────────────────────────
   แผนที่มีอินสแตนซ์เดียวทั้งเว็บ ชั้นข้อมูลจึงโหลดครั้งเดียวตอนเปิดแผงครั้งแรก
   ทุกชั้นยกเว้นชายฝั่งเป็นของเสริม โหลดไม่ได้ก็ต้องยังเลือกจุดจากรายการได้อยู่
   เพราะคนที่เปิดเว็บกลางทะเลอาจมีสัญญาณพอโหลด JSON ก้อนเล็กได้ไม่ครบทุกก้อน */

async function fetchLayer(file) {
  try {
    const response = await fetch(`map/${file}?v=${APP_VERSION}`);
    return response.ok ? await response.json() : null;
  } catch (error) {
    return null;
  }
}

async function loadMapLayers() {
  // ยิงพร้อมกันทุกก้อน ไฟล์รวมกันไม่ถึง 250 KB และไม่มีก้อนไหนต้องรอผลของก้อนอื่น
  const [coast, borders, depth, reefs, marks] = await Promise.all([
    fetchLayer('coastline-south.json'),
    fetchLayer('borders-south.json'),
    fetchLayer('depth-south.json'),
    fetchLayer('reefs-south.json'),
    fetchLayer('marks-south.json'),
  ]);

  if (coast) spotMap.setCoastline(coast);
  if (borders) spotMap.setBorders(borders);
  if (depth) {
    spotMap.setDepth(depth);
    renderDepthLegend(depth);
  }

  mapSites = buildSites(reefs, marks);
  spotMap.setSites(mapSites);
  document.getElementById('mapLegend').hidden = mapSites.length === 0 && !borders;
}

/**
 * รวมปะการังเทียมกับหมายให้เป็นรายการเดียวที่แผนที่วาดได้
 *
 * ปะการังเทียมหนึ่ง "แหล่ง" มีหลายจุดจัดวาง (กรมประมงวางเป็นกลุ่ม)
 * จึงตั้งชื่อป้ายจากตำบลหรือแหล่ง แล้วต่อท้ายด้วยความลึก
 * ซึ่งเป็นตัวเลขที่คนตกปลาใช้ตัดสินใจก่อนอย่างอื่น
 */
function buildSites(reefs, marks) {
  const sites = [];

  (reefs && reefs.reefs ? reefs.reefs : []).forEach((reef, index) => {
    const where = reef.tambon || reef.amphoe || reef.site || reef.province;
    sites.push({
      key: `reef:${index}`,
      kind: 'reef',
      label: isFiniteNumber(reef.depth_m) ? `${where} ${reef.depth_m} ม.` : where,
      name: `ปะการังเทียม ${where}`,
      province: reef.province,
      lat: reef.lat,
      lon: reef.lon,
      depth_m: reef.depth_m,
    });
  });

  (marks && marks.marks ? marks.marks : []).forEach((mark, index) => {
    sites.push({
      key: `mark:${index}`,
      kind: 'mark',
      label: mark.name,
      name: mark.name,
      province: mark.province,
      lat: mark.lat,
      lon: mark.lon,
      depth_m: null,
    });
  });

  return sites;
}

/* เลือกปะการังเทียมหรือหมายเป็นจุดที่จะดูสภาพอากาศและคะแนน
   ใช้พิกัดของจุดนั้นตรง ๆ ไม่ใช่พิกัดอ้างอิงของอำเภอ เพราะห่างกันได้สิบกว่ากิโล
   ซึ่งมากพอให้ความสูงคลื่นต่างกันจริง */
function pickSite(key) {
  const site = mapSites.find((item) => item.key === key);
  if (!site) return;

  spotMap.setSelectedSite(key);
  spotMap.setSelected(null);

  const detail = [
    isFiniteNumber(site.depth_m) ? `ลึก ${site.depth_m} ม.` : '',
    `${roundTo(site.lat, 4)}, ${roundTo(site.lon, 4)}`,
  ].filter(Boolean).join(' · ');

  applyLocation({
    id: '',
    lat: site.lat,
    lon: site.lon,
    label: site.name,
    province: site.province,
    coast: '',
    detail,
    isReference: false,
  });

  spotMap.focus(site.lat, site.lon, 0.5);
  renderPlaceList();
}

/* ── การเปิด-ปิดแผงและการโต้ตอบ ───────────────────────────────────────── */

async function openPlacePicker() {
  const dialog = document.getElementById('placePicker');
  dialog.showModal();

  if (!spotMap) {
    spotMap = new SpotMap(document.getElementById('spotMap'));
    spotMap.onPick = pickPlace;
    spotMap.onPickSite = pickSite;
    spotMap.reset();

    await loadMapLayers();

    if (activeLocation.isGps) spotMap.setOrigin({ lat: activeLocation.lat, lon: activeLocation.lon });
  }

  spotMap.setSelected(activeLocation.id || null);
  await loadPlaces(document.getElementById('placeSearch').value.trim());
}

document.getElementById('openPlacePicker').addEventListener('click', openPlacePicker);
document.querySelector('.close-picker').addEventListener('click', () => {
  document.getElementById('placePicker').close();
});
document.getElementById('mapReset').addEventListener('click', () => spotMap && spotMap.reset());
document.getElementById('useGps').addEventListener('click', () => requestGps());

document.getElementById('placeSearch').addEventListener('input', (event) => {
  const value = event.target.value.trim();
  window.clearTimeout(searchTimer);
  searchTimer = window.setTimeout(() => loadPlaces(value), SEARCH_DEBOUNCE_MS);
});

document.getElementById('placeList').addEventListener('click', (event) => {
  const star = event.target.closest('[data-star]');
  if (star) {
    toggleFavourite(star.dataset.star);
    return;
  }
  const pick = event.target.closest('[data-pick]');
  if (pick) pickPlace(pick.dataset.pick);
});

/* ═══ Fishing Score — GET /api/score.php ═══════════════════════════════
   คะแนนนี้มาจากน้ำหนักที่ทีมเลือกเอง ไม่ได้ปรับจากสถิติการจับปลาจริง
   ที่มาทั้งหมดอยู่ใน docs/fishing-score.md และ API ส่ง breakdown รายปัจจัยมาให้
   จึงต้องเปิดให้ผู้ใช้กดดูได้เสมอ — ตัวเลขที่กดดูที่มาไม่ได้คือตัวเลขที่เชื่อไม่ได้ */

/* เส้นรอบวงของวงกลมรัศมี 82 ใน viewBox 200x200 — ต้องตรงกับ stroke-dasharray ใน styles.css */
const SCORE_RING_CIRCUMFERENCE = 515;

function safetyToneClass(level) {
  if (level === 'dangerous') return 'is-danger';
  if (level === 'caution') return 'is-caution';
  return 'is-safe';
}

/* ── การปรับแต่งของผู้ใช้ ──────────────────────────────────────────────
   ลำดับในรายการ = น้ำหนัก (บนสุดมากสุด) และสวิตช์ = เอามาคิดหรือไม่
   เก็บลง localStorage เพราะเป็นความชอบส่วนตัวของคนใช้เครื่องนั้น ไม่ใช่ข้อมูลของระบบ
   ถ้าเก็บไม่ได้ (โหมดส่วนตัว/ปิดไว้) ให้ทำงานต่อด้วยค่าเริ่มต้น ห้ามพังทั้งการ์ด */
const SCORE_PREFS_KEY = 'fis.score.prefs.v1';

let scorePayload = null;   // คำตอบล่าสุดจาก API เก็บไว้คิดใหม่ตอนผู้ใช้ปรับ
let scorePrefs = null;     // { order: [key], off: [key] }

function readScorePrefs() {
  try {
    const raw = window.localStorage.getItem(SCORE_PREFS_KEY);
    if (!raw) return { order: [], off: [] };
    const parsed = JSON.parse(raw);
    return {
      order: Array.isArray(parsed.order) ? parsed.order.map(String) : [],
      off: Array.isArray(parsed.off) ? parsed.off.map(String) : [],
    };
  } catch (error) {
    return { order: [], off: [] };
  }
}

function saveScorePrefs() {
  try {
    window.localStorage.setItem(SCORE_PREFS_KEY, JSON.stringify(scorePrefs));
  } catch (error) {
    /* เก็บไม่ได้ก็ยังใช้งานได้ในรอบนี้ แค่ไม่จำข้ามรอบ ไม่ต้องรบกวนผู้ใช้ */
  }
}

/* เรียงงานตามลำดับที่ผู้ใช้ตั้งไว้ งานที่ API เพิ่มมาใหม่และยังไม่มีในลำดับให้ต่อท้าย
   (กันกรณี backend เพิ่มประเภทงานแล้วของที่เก็บไว้เดิมทำให้งานใหม่หายไปเงียบ ๆ) */
function orderedStyles() {
  const styles = (scorePayload && scorePayload.data && scorePayload.data.styles) || [];
  const byKey = new Map(styles.map((style) => [style.key, style]));
  const result = [];

  scorePrefs.order.forEach((key) => {
    if (byKey.has(key)) {
      result.push(byKey.get(key));
      byKey.delete(key);
    }
  });
  byKey.forEach((style) => result.push(style));
  return result;
}

function isStyleOn(key) {
  return !scorePrefs.off.includes(key);
}

/* น้ำหนักตามลำดับ: อันดับ i จาก n อันที่เปิดอยู่ ได้น้ำหนัก (n-i)/(1+2+…+n)
   เป็นการลดหลั่นเชิงเส้น — บนสุดได้ 2/(n+1) ของทั้งหมด ซึ่งเห็นความต่างชัดโดยไม่สุดโต่ง
   เลือกแบบนี้เพราะอธิบายให้ผู้ใช้เข้าใจได้ในประโยคเดียว ไม่ต้องมีเลขวิเศษ */
function rankWeights(count) {
  const total = (count * (count + 1)) / 2;
  return Array.from({ length: count }, (_, i) => (count - i) / total);
}

/* คิดคะแนนรวมจากงานที่ผู้ใช้เปิดไว้ ตามลำดับที่ผู้ใช้จัด
   ถ้ายังไม่เคยปรับอะไรเลย จะคืน null เพื่อให้ใช้ค่า overall ของ API ตามสูตรในเอกสาร */
function customOverall() {
  const enabled = orderedStyles().filter((style) => isStyleOn(style.key));
  if (!enabled.length) return null;

  const weights = rankWeights(enabled.length);
  const score = enabled.reduce((sum, style, i) => sum + weights[i] * style.score, 0);
  return { score: Math.round(score), count: enabled.length, top: enabled[0] };
}

/* ผู้ใช้ยังไม่ได้แตะอะไร = ใช้สูตรกลางของ API (เฉลี่ย 3 งานที่ดีที่สุด) */
function prefsAreDefault() {
  return scorePrefs.order.length === 0 && scorePrefs.off.length === 0;
}

function styleRowMarkup(style, index, weightPct) {
  /* แสดงแค่ 3 ปัจจัยแรก เพราะ API เรียงตามแต้มที่ได้จริงมาแล้ว
     ผู้ใช้จึงเห็นตัวชี้ขาดของงานนั้นทันทีโดยไม่ต้องอ่านทั้งหมด */
  const top = (style.breakdown || []).slice(0, 3)
    .map((row) => `${escapeHtml(row.label)} ${Math.round(row.contribution)}`)
    .join(' · ');

  const on = isStyleOn(style.key);
  const key = escapeHtml(style.key);

  return `<li class="style-row${on ? '' : ' is-off'}" data-key="${key}">`
    + `<button type="button" class="style-switch press" role="switch" aria-checked="${on ? 'true' : 'false'}"`
    + ` data-action="toggle" data-key="${key}">`
    + `<span class="switch-track" aria-hidden="true"><i></i></span>`
    + `<span class="sr-only">${escapeHtml(style.name_th)}</span></button>`
    + `<span class="style-score">${escapeHtml(String(style.score))}</span>`
    + '<span class="style-copy">'
    + `<b>${escapeHtml(style.name_th)}</b>`
    + `<small>${escapeHtml(style.tagline || '')}</small>`
    + (top ? `<em>${escapeHtml(top)}</em>` : '')
    + '</span>'
    + `<span class="style-weight">${on ? `${weightPct}%` : 'ปิด'}</span>`
    + '<span class="style-move">'
    + `<button type="button" class="press" data-action="up" data-key="${key}" aria-label="เลื่อน ${escapeHtml(style.name_th)} ขึ้น"${index === 0 ? ' disabled' : ''}>↑</button>`
    + `<button type="button" class="press" data-action="down" data-key="${key}" aria-label="เลื่อน ${escapeHtml(style.name_th)} ลง">↓</button>`
    + '</span>'
    + '</li>';
}

/* วาดเฉพาะส่วนที่ขึ้นกับการปรับแต่ง เรียกซ้ำได้ทุกครั้งที่ผู้ใช้กดอะไร
   โดยไม่ต้องยิง API ใหม่ เพราะคะแนนรายงานมาครบแล้ว เปลี่ยนแค่วิธีรวม */
function renderScoreSelection() {
  if (!scorePayload) return;

  const data = scorePayload.data || {};
  const styles = orderedStyles();
  const custom = customOverall();
  const useCustom = !prefsAreDefault() && custom !== null;

  const apiOverall = data.overall || {};
  const score = useCustom ? custom.score : (isFiniteNumber(apiOverall.score) ? apiOverall.score : 0);

  document.getElementById('scoreValue').textContent = String(score);
  document.getElementById('scoreLabel').textContent = scoreLabelFor(score);

  /* วงแหวนเดินตามคะแนนจริง — dashoffset มาก = วงว่างมาก */
  const ring = document.getElementById('scoreRing');
  const offset = SCORE_RING_CIRCUMFERENCE * (1 - Math.min(100, Math.max(0, score)) / 100);
  ring.style.strokeDashoffset = String(Math.round(offset));

  /* บอกให้ชัดว่าคะแนนของ "วันไหน" และ "เหมาะกับงานอะไร"
     งานที่แนะนำเลือกจากงานที่เปิดไว้เท่านั้น เพราะงานที่ผู้ใช้ปิดไปคือไม่สนใจ */
  const enabled = styles.filter((style) => isStyleOn(style.key));
  const best = enabled.slice().sort((a, b) => b.score - a.score)[0] || null;
  const dayLabel = scoreDayLabel(data.date);

  document.getElementById('scoreBest').textContent = best
    ? `${dayLabel}เหมาะกับ ${best.name_th} ที่สุด ${best.score}/100`
    : 'ยังไม่ได้เลือกงานที่จะนำมาคิด';

  /* ถ้าผู้ใช้ปรับเอง ต้องบอกว่าคะแนนนี้ไม่ใช่สูตรกลางแล้ว ไม่งั้นจะเข้าใจผิดว่าเป็นค่ามาตรฐาน */
  const customEl = document.getElementById('scoreCustom');
  if (useCustom) {
    customEl.hidden = false;
    customEl.textContent = `คิดจาก ${custom.count} งานที่คุณเลือก ถ่วงตามลำดับที่จัดไว้`;
  } else if (custom === null && !prefsAreDefault()) {
    customEl.hidden = false;
    customEl.textContent = 'ปิดทุกงานอยู่ จึงยังไม่มีคะแนนรวม เปิดอย่างน้อยหนึ่งงาน';
  } else {
    customEl.hidden = false;
    customEl.textContent = 'คิดตามสูตรกลาง: เฉลี่ย 3 งานที่คะแนนสูงสุดของวันนี้';
  }

  const weights = rankWeights(Math.max(1, enabled.length));
  let enabledIndex = 0;
  document.getElementById('styleList').innerHTML = styles.map((style, index) => {
    const pct = isStyleOn(style.key) ? Math.round(weights[enabledIndex++] * 100) : 0;
    return styleRowMarkup(style, index, pct);
  }).join('');
}

function scoreLabelFor(score) {
  if (score >= 80) return 'ดีมาก';
  if (score >= 65) return 'ดี';
  if (score >= 50) return 'พอใช้';
  return 'ไม่เด่น';
}

/* "วันนี้" เมื่อเป็นวันปัจจุบัน ไม่งั้นบอกวันที่ไปเลย ผู้ใช้จะได้ไม่สับสนว่ากำลังดูวันไหน */
function scoreDayLabel(date) {
  if (!date) return '';
  if (date === isoDate(new Date())) return 'วันนี้';
  const parts = String(date).split('-').map(Number);
  if (parts.length !== 3) return `${date} `;
  return `${formatThaiDate(new Date(parts[0], parts[1] - 1, parts[2]))} `;
}

function renderScore(payload) {
  scorePayload = payload;
  const data = payload.data || {};
  const safety = data.safety || null;

  renderState(document.getElementById('scoreState'), null);
  document.getElementById('scoreBody').hidden = false;

  /* ความปลอดภัยแยกจากคะแนนโดยเจตนา คะแนนสูงต้องไม่กลบคำเตือนว่าทะเลอันตราย
     และผู้ใช้ปิดงานทิ้งก็ไม่ทำให้คำเตือนหายไป */
  const safetyEl = document.getElementById('scoreSafety');
  if (safety && safety.label) {
    safetyEl.hidden = false;
    safetyEl.className = `score-safety ${safetyToneClass(safety.level)}`;
    const reasons = Array.isArray(safety.reasons) ? safety.reasons.join(' · ') : '';
    safetyEl.textContent = `${safety.label}${reasons ? ` — ${reasons}` : ''}`;
  } else {
    safetyEl.hidden = true;
  }

  document.getElementById('scoreNotice').textContent = data.notice || '';
  renderScoreSelection();
}

/* ── การโต้ตอบในรายการงาน ─────────────────────────────────────────────
   ใช้ event delegation ตัวเดียว เพราะรายการถูกวาดใหม่ทุกครั้งที่มีการเปลี่ยนแปลง
   ถ้าผูก listener รายปุ่มจะต้องผูกใหม่ทุกรอบและหลุดได้ง่าย */
document.getElementById('styleList').addEventListener('click', (event) => {
  const button = event.target.closest('[data-action]');
  if (!button || !scorePayload) return;

  const { action, key } = button.dataset;
  const keys = orderedStyles().map((style) => style.key);
  const index = keys.indexOf(key);
  if (index < 0) return;

  if (action === 'toggle') {
    scorePrefs.off = isStyleOn(key)
      ? scorePrefs.off.concat([key])
      : scorePrefs.off.filter((item) => item !== key);
  } else if (action === 'up' && index > 0) {
    [keys[index - 1], keys[index]] = [keys[index], keys[index - 1]];
    scorePrefs.order = keys;
  } else if (action === 'down' && index < keys.length - 1) {
    [keys[index + 1], keys[index]] = [keys[index], keys[index + 1]];
    scorePrefs.order = keys;
  } else {
    return;
  }

  // ลำดับต้องถูกบันทึกเสมอ ไม่งั้นการกดสวิตช์อย่างเดียวจะทำให้ลำดับกลับไปเป็นค่าเริ่มต้น
  if (!scorePrefs.order.length) scorePrefs.order = keys;

  saveScorePrefs();
  renderScoreSelection();
});

/* เรียงตามคะแนนของวันนั้น — ทางลัดสำหรับคนที่อยากให้วันนี้เอางานที่มาแรงขึ้นก่อน */
document.getElementById('styleSort').addEventListener('click', () => {
  if (!scorePayload) return;
  scorePrefs.order = orderedStyles()
    .slice()
    .sort((a, b) => b.score - a.score)
    .map((style) => style.key);
  saveScorePrefs();
  renderScoreSelection();
});

document.getElementById('styleReset').addEventListener('click', () => {
  scorePrefs = { order: [], off: [] };
  saveScorePrefs();
  renderScoreSelection();
});

async function loadScore() {
  if (scorePrefs === null) scorePrefs = readScorePrefs();

  const slot = document.getElementById('scoreState');
  document.getElementById('scoreBody').hidden = true;
  renderState(slot, { kind: 'loading', title: 'กำลังคิดคะแนนของวันนี้…' });

  try {
    const payload = await fetchJson('api/score.php', {
      lat: activeLocation.lat,
      lon: activeLocation.lon,
      date: activeDate,
    });
    renderScore(payload);
  } catch (error) {
    document.getElementById('scoreBody').hidden = true;
    renderState(slot, {
      kind: error.status === 400 ? 'empty' : 'error',
      title: 'ยังคิดคะแนนให้ไม่ได้',
      detail: error.message,
      retry: 'score',
    });
  }
}
retryActions.score = loadScore;

/* ปุ่ม "ที่มา" — พับรายละเอียดไว้ก่อนเพื่อไม่ให้การ์ดยาวเกิน แต่ต้องกดดูได้เสมอ */
document.getElementById('scoreInfo').addEventListener('click', () => {
  const detail = document.getElementById('scoreDetail');
  const button = document.getElementById('scoreInfo');
  const opening = detail.hidden;
  detail.hidden = !opening;
  button.setAttribute('aria-expanded', opening ? 'true' : 'false');
  button.textContent = opening ? 'ซ่อน' : 'ที่มา';
});

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
      date: activeDate,
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
  loadScore();

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
  loadOutlook();
});

/* ── ปฏิทินคะแนน ───────────────────────────────────────────────────────
   คะแนนมาจาก /api/outlook.php ซึ่งใช้สูตรเดียวกับการ์ดคะแนนบนหน้าแรก

   ⚠️ คิดคะแนนล่วงหน้าได้แค่ราว 7-8 วัน เพราะแบบจำลองระดับน้ำพยากรณ์ได้เท่านั้น
   วันที่เลยจากนั้นจะไม่มีคะแนน และต้องแสดงว่า "ยังไม่รู้" ตรง ๆ
   ห้ามเติมตัวเลขให้เต็มเดือนเพื่อความสวยงาม คนเอาไปเลือกวันออกทะเลจริง */

/* คะแนนรายวันที่โหลดมาแล้ว คีย์เป็นวันที่แบบ YYYY-MM-DD */
let outlookByDate = new Map();

function scoreTier(score) {
  if (score >= 85) return 'excellent';
  if (score >= 70) return 'good';
  if (score >= 55) return 'fair';
  return 'poor';
}

/* เดือนที่ปฏิทินกำลังแสดง — เริ่มที่เดือนปัจจุบันเสมอ */
let calendarYear = now.getFullYear();
let calendarMonth = now.getMonth();

function isoFromParts(year, month, day) {
  return `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

function selectDay(day) {
  pickedDay = day;
  document.querySelector('.day.selected')?.classList.remove('selected');
  calendarGrid.querySelector(`[data-day="${day}"]`)?.classList.add('selected');

  const date = new Date(calendarYear, calendarMonth, day);
  const entry = outlookByDate.get(isoFromParts(calendarYear, calendarMonth, day));

  if (!entry || !isFiniteNumber(entry.score)) {
    pickedDate.textContent = formatThaiDate(date);
    pickedReason.textContent = entry && entry.reason
      ? entry.reason
      : 'ยังคิดคะแนนของวันนี้ไม่ได้ เพราะแบบจำลองน้ำพยากรณ์ล่วงหน้าได้จำกัด';
    return;
  }

  pickedDate.textContent = `${formatThaiDate(date)} · ${entry.score}/100 ${entry.label}`;

  const parts = [];
  if (entry.best_style_name) parts.push(`เด่นที่ ${entry.best_style_name}`);
  if (entry.safety === 'dangerous') parts.push('⚠ ลมหรือคลื่นแรงเกินเกณฑ์เรือเล็ก');
  else if (entry.safety === 'caution') parts.push('ต้องระวังลมและคลื่น');
  pickedReason.textContent = parts.join(' · ') || 'ดูรายละเอียดที่การ์ดคะแนนหน้าแรก';
}

function buildCalendar() {
  document.getElementById('calendarMonth').textContent = TH_MONTH_FULL[calendarMonth];
  document.getElementById('calendarYear').textContent = calendarYear + 543;

  const isCurrentMonth = now.getFullYear() === calendarYear && now.getMonth() === calendarMonth;
  const leadingBlanks = new Date(calendarYear, calendarMonth, 1).getDay();
  const daysInMonth = new Date(calendarYear, calendarMonth + 1, 0).getDate();
  const markup = [];

  for (let i = 0; i < leadingBlanks; i += 1) {
    markup.push('<button type="button" class="blank" disabled></button>');
  }

  for (let day = 1; day <= daysInMonth; day += 1) {
    const entry = outlookByDate.get(isoFromParts(calendarYear, calendarMonth, day));
    const today = isCurrentMonth && now.getDate() === day ? ' today' : '';

    // ไม่มีคะแนน = ยังไม่รู้ ไม่ใช่คะแนนต่ำ จึงไม่ใส่สีระดับใด ๆ และแสดงขีดแทนตัวเลข
    const tier = entry && isFiniteNumber(entry.score) ? scoreTier(entry.score) : 'unknown';
    const value = entry && isFiniteNumber(entry.score) ? String(entry.score) : '–';

    markup.push(`<button type="button" class="day press ${tier}${today}" data-day="${day}">`
      + `<b>${day}</b><span>${value}</span></button>`);
  }

  calendarGrid.innerHTML = markup.join('');
  calendarGrid.querySelectorAll('.day').forEach((button) => {
    button.addEventListener('click', () => selectDay(Number(button.dataset.day)));
  });

  selectDay(isCurrentMonth ? now.getDate() : 1);
}

async function loadOutlook() {
  const slot = document.getElementById('calendarState');
  renderState(slot, { kind: 'loading', title: 'กำลังคิดคะแนนล่วงหน้า…' });

  try {
    const payload = await fetchJson('api/outlook.php', {
      lat: activeLocation.lat,
      lon: activeLocation.lon,
    });

    outlookByDate = new Map();
    (payload.data.days || []).forEach((entry) => outlookByDate.set(entry.date, entry));

    renderState(slot, null);
    document.getElementById('calendarNotice').textContent =
      `${(payload.meta && payload.meta.horizon_note) || ''} วันที่ยังไม่มีคะแนนจะแสดงเป็นขีด`;
    buildCalendar();
  } catch (error) {
    outlookByDate = new Map();
    renderState(slot, {
      kind: 'error',
      title: 'ยังคิดคะแนนล่วงหน้าไม่ได้',
      detail: error.message,
      retry: 'outlook',
    });
    buildCalendar();
  }
}
retryActions.outlook = loadOutlook;

buildCalendar();

document.getElementById('saveTrip').addEventListener('click', () => {
  if (!selectedSpot) {
    showToast('ยังไม่มีหมายให้บันทึกทริป');
    return;
  }
  savedTrip = {
    spot: selectedSpot.name,
    label: formatThaiDate(new Date(calendarYear, calendarMonth, pickedDay)),
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
loadScore();
loadSpots();
loadGear();

/* ขอตำแหน่งครั้งแรกที่เข้าเว็บเท่านั้น
   เคยเลือกจุดไว้แล้ว = เคารพการเลือกนั้น ไม่ต้องถามอีก
   เคยถามแล้วไม่ว่าผลเป็นอย่างไร = ไม่ถามซ้ำ คนที่กดปฏิเสธไปแล้วจะได้ไม่โดนรบกวนทุกครั้ง
   อยากใช้เมื่อไหร่กดปุ่ม "ตำแหน่งของฉัน" ในแผงเลือกจุดได้ตลอด */
if (!readJsonSetting(LOCATION_KEY, null) && !readJsonSetting(GPS_ASKED_KEY, false)) {
  writeJsonSetting(GPS_ASKED_KEY, true);
  requestGps({ silent: true });
}
