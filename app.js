const toast = document.getElementById('toast');
document.getElementById('logCatch').addEventListener('click', () => {
  toast.classList.add('show');
  window.setTimeout(() => toast.classList.remove('show'), 2600);
});

document.querySelectorAll('.nav-item').forEach((link) => {
  link.addEventListener('click', () => {
    document.querySelector('.nav-item.active')?.classList.remove('active');
    link.classList.add('active');
  });
});

const planner = document.getElementById('planner');
const spotStep = document.getElementById('spotStep');
const calendarStep = document.getElementById('calendarStep');
const selectedSpotLabel = document.getElementById('selectedSpotLabel');
let selectedSpot = 'อ่าวปัตตานี';

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

document.querySelectorAll('.day:not(.blank)').forEach((day) => {
  day.addEventListener('click', () => {
    const score = Number(day.querySelector('span').textContent);
    const date = day.querySelector('b').textContent;
    document.querySelectorAll('.day').forEach((item) => item.classList.remove('today'));
    day.classList.add('today');
    document.getElementById('pickedDate').textContent = `ส. ${date} ส.ค. · คะแนน ${score}/100`;
    document.getElementById('pickedReason').textContent = score >= 85 ? 'จังหวะน้ำและ Solunar โดดเด่น เหมาะวางทริปล่วงหน้า' : score >= 70 ? 'สภาพรวมดี ควรตรวจลมและคลื่นใกล้ออกเดินทาง' : 'เงื่อนไขยังไม่เด่น แนะนำเลือกช่วงเวลาให้เหมาะกับหมาย';
  });
});

document.getElementById('saveTrip').addEventListener('click', () => {
  planner.close();
  toast.textContent = 'บันทึกทริปลงปฏิทินแล้ว';
  toast.classList.add('show');
  window.setTimeout(() => toast.classList.remove('show'), 2600);
});
