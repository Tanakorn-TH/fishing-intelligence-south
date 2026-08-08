/* คลื่นดี — Fishing Intelligence South
   Prototype interactions. คะแนนรายวันยังเป็นชุดข้อมูลตัวอย่าง
   จนกว่า backend จะคำนวณจากน้ำ Solunar ลม คลื่น และชั้นความลึกจริง */

const TH_DAY_ABBR = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];
const TH_MONTH_ABBR = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
const TH_MONTH_FULL = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

// ตรึงปฏิทินไว้ที่เดือนของชุดข้อมูลตัวอย่าง ปุ่มเลื่อนเดือนจะใช้ได้เมื่อมี API คะแนนรายวัน
const CALENDAR_YEAR = 2026;
const CALENDAR_MONTH = 7; // 0-indexed = สิงหาคม
const DAY_SCORES = [78, 65, 61, 42, 67, 74, 82, 91, 88, 79, 63, 69, 76, 80, 94, 89, 75, 68, 49, 64, 77, 83, 90, 73, 66, 45, 62, 76, 81, 87, 78];

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

/* วันที่บนแถบบน */
const now = new Date();
document.getElementById('todayLabel').textContent = formatThaiDate(now);

/* บันทึกปลา — สคีมาผูก catch_logs กับ trip_plans เสมอ จึงต้องมีทริปก่อน */
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

/* ปฏิทินทริป */
const planner = document.getElementById('planner');
const spotStep = document.getElementById('spotStep');
const calendarStep = document.getElementById('calendarStep');
const selectedSpotLabel = document.getElementById('selectedSpotLabel');
const calendarGrid = document.getElementById('calendarGrid');
const pickedDate = document.getElementById('pickedDate');
const pickedReason = document.getElementById('pickedReason');
let selectedSpot = 'อ่าวปัตตานี';
let pickedDay = null;

function openPlanner() {
  spotStep.hidden = false;
  calendarStep.hidden = true;
  planner.showModal();
}

document.querySelectorAll('.choose-spot, .planner-link').forEach((button) => {
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

document.querySelectorAll('.spot-option').forEach((option) => {
  option.addEventListener('click', () => {
    document.querySelector('.spot-option.selected')?.classList.remove('selected');
    option.classList.add('selected');
    selectedSpot = option.dataset.spot;
  });
});

document.getElementById('showCalendar').addEventListener('click', () => {
  selectedSpotLabel.textContent = selectedSpot;
  spotStep.hidden = true;
  calendarStep.hidden = false;
});

function selectDay(day) {
  pickedDay = day;
  document.querySelector('.day.selected')?.classList.remove('selected');
  calendarGrid.querySelector(`[data-day="${day}"]`).classList.add('selected');
  const date = new Date(CALENDAR_YEAR, CALENDAR_MONTH, day);
  const score = DAY_SCORES[day - 1];
  pickedDate.textContent = `${formatThaiDate(date)} · คะแนน ${score}/100`;
  pickedReason.textContent = scoreReason(score);
}

function buildCalendar() {
  document.getElementById('calendarMonth').textContent = TH_MONTH_FULL[CALENDAR_MONTH];
  document.getElementById('calendarYear').textContent = CALENDAR_YEAR + 543;

  const isCurrentMonth = now.getFullYear() === CALENDAR_YEAR && now.getMonth() === CALENDAR_MONTH;
  const leadingBlanks = new Date(CALENDAR_YEAR, CALENDAR_MONTH, 1).getDay();
  const markup = [];

  for (let i = 0; i < leadingBlanks; i += 1) {
    markup.push('<button class="blank" disabled></button>');
  }
  DAY_SCORES.forEach((score, index) => {
    const day = index + 1;
    const today = isCurrentMonth && now.getDate() === day ? ' today' : '';
    markup.push(`<button class="day press ${scoreTier(score)}${today}" data-day="${day}"><b>${day}</b><span>${score}</span></button>`);
  });

  calendarGrid.innerHTML = markup.join('');
  calendarGrid.querySelectorAll('.day').forEach((button) => {
    button.addEventListener('click', () => selectDay(Number(button.dataset.day)));
  });

  selectDay(isCurrentMonth ? now.getDate() : 1);
}

buildCalendar();

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

document.getElementById('saveTrip').addEventListener('click', () => {
  savedTrip = {
    spot: selectedSpot,
    label: formatThaiDate(new Date(CALENDAR_YEAR, CALENDAR_MONTH, pickedDay)),
  };
  planner.close();
  showToast(`บันทึกทริป ${savedTrip.spot} · ${savedTrip.label} แล้ว`);
});
