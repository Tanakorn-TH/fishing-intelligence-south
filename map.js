/* แผนที่เลือกหมาย — วาดด้วย SVG ล้วน ไม่มี library ภายนอก

   ทำไมไม่ใช้ Leaflet: ประโยชน์หลักของมันคือจัดการ tile จากเซิร์ฟเวอร์แผนที่
   แต่เราวาดชายฝั่งเองจากไฟล์ในเครื่อง (map/coastline-south.json) จึงไม่ได้ใช้ส่วนนั้นเลย
   เหลือแค่ pan/zoom กับหมุด ซึ่งเขียนเองสั้นกว่าและคุมหน้าตาได้ตรงตามที่ออกแบบ
   ทั้งโปรเจคยังไม่มี dependency ฝั่งหน้าเว็บเลย ไม่อยากให้ตัวแรกเป็นตัวที่เราใช้ไม่คุ้ม

   ทำไมไม่ใช้ tile จากภายนอก: นโยบายของ OpenStreetMap ระบุว่าใช้หนักต้องขออนุญาต
   และตัดการเข้าถึงได้โดยไม่แจ้งล่วงหน้า — เว็บที่คนเปิดดูก่อนออกทะเลไม่ควรพึ่งของแบบนั้น

   ⚠️ แผนที่นี้ใช้เลือกจุดดูสภาพอากาศเท่านั้น ไม่ใช่แผนที่เดินเรือ
   ไม่มีหินโสโครก ไม่มีทุ่น ไม่มีร่องน้ำ และเส้นชายฝั่งเป็นข้อมูลความละเอียดต่ำ */

/* สีทั้งชุดผ่านการตรวจ contrast มาแล้ว ดูเหตุผลของแต่ละค่าใน design.css หัวข้อแผนที่ */
const MAP_COLORS = {
  sea: '#f5f0e4',
  land: '#e8dfc9',
  coast: '#8a7350',
  pin: '#c8542a',
  pinActive: '#1d9e75',
  label: '#7a3419',
};

/* กรอบเริ่มต้น = ภาคใต้ทั้งภาค ผู้ใช้ค่อยซูมเข้าหาที่ของตัวเอง */
const MAP_HOME = { west: 96.0, east: 103.5, south: 4.5, north: 12.5 };

/* ซูมได้ลึกสุดเท่าไหร่ — จำกัดไว้เพราะข้อมูลชายฝั่งละเอียดราว 400 ม.
   ปล่อยให้ซูมลึกกว่านี้จะเห็นเส้นเป็นเหลี่ยม ๆ และหลอกให้คิดว่าแผนที่ละเอียดกว่าความจริง */
const MAP_MIN_SPAN_DEG = 0.35;

/**
 * ฉายพิกัดลงระนาบแบบ equirectangular ที่ยืดแกน x ตาม cos(ละติจูดกลาง)
 * พอสำหรับพื้นที่ขนาดภาคเดียว และคำนวณกลับไปมาได้ตรงไปตรงมา
 * ไม่ใช้ Mercator เพราะที่ละติจูด 5-12 องศา ความต่างแทบมองไม่เห็น
 * แต่ Mercator ทำให้สูตรผกผันยุ่งขึ้นโดยไม่ได้อะไรกลับมา
 */
const MAP_LAT0 = (MAP_HOME.north + MAP_HOME.south) / 2;
const MAP_KX = Math.cos((MAP_LAT0 * Math.PI) / 180);

function mapProjectX(lon) {
  return lon * MAP_KX;
}

function mapUnprojectX(x) {
  return x / MAP_KX;
}

/* แกน y ของ SVG ชี้ลง ละติจูดชี้ขึ้น จึงกลับเครื่องหมาย */
function mapProjectY(lat) {
  return -lat;
}

function mapUnprojectY(y) {
  return -y;
}

class SpotMap {
  constructor(svg) {
    this.svg = svg;
    this.coast = null;
    this.places = [];
    this.selectedId = null;
    this.origin = null;      // ตำแหน่งผู้ใช้ ถ้ามี
    this.onPick = null;

    this.view = {
      x: mapProjectX(MAP_HOME.west),
      y: mapProjectY(MAP_HOME.north),
      w: mapProjectX(MAP_HOME.east) - mapProjectX(MAP_HOME.west),
      h: mapProjectY(MAP_HOME.south) - mapProjectY(MAP_HOME.north),
    };

    this.bindGestures();
  }

  applyView() {
    const { x, y, w, h } = this.view;
    this.svg.setAttribute('viewBox', `${x} ${y} ${w} ${h}`);
    // เส้นและหมุดต้องคงขนาดที่ตาเห็นไว้ ไม่ว่าจะซูมเท่าไหร่
    // จึงคำนวณขนาดกลับตามอัตราส่วนของ viewBox ทุกครั้งที่มุมมองเปลี่ยน
    this.svg.style.setProperty('--map-scale', String(w / this.view0w));
    this.renderPins();
  }

  get view0w() {
    return mapProjectX(MAP_HOME.east) - mapProjectX(MAP_HOME.west);
  }

  /** แปลงพิกัดหน้าจอเป็นพิกัดใน viewBox */
  toViewPoint(clientX, clientY) {
    const box = this.svg.getBoundingClientRect();
    return {
      x: this.view.x + ((clientX - box.left) / box.width) * this.view.w,
      y: this.view.y + ((clientY - box.top) / box.height) * this.view.h,
    };
  }

  zoomAt(factor, clientX, clientY) {
    const before = this.toViewPoint(clientX, clientY);

    let w = this.view.w * factor;
    const maxW = this.view0w;
    const minW = mapProjectX(MAP_MIN_SPAN_DEG);
    w = Math.max(minW, Math.min(maxW, w));
    const ratio = w / this.view.w;
    if (ratio === 1) return;

    this.view.w = w;
    this.view.h *= ratio;

    // ตรึงจุดที่นิ้ว/เมาส์ชี้อยู่ให้อยู่กับที่ ไม่งั้นการซูมจะรู้สึกเหมือนแผนที่ไถลหนี
    const after = this.toViewPoint(clientX, clientY);
    this.view.x += before.x - after.x;
    this.view.y += before.y - after.y;

    this.clampView();
    this.applyView();
  }

  /* ไม่ให้เลื่อนออกนอกพื้นที่ที่มีข้อมูล ไม่งั้นผู้ใช้จะเจอจอครีมเปล่า ๆ แล้วคิดว่าแอปพัง */
  clampView() {
    const west = mapProjectX(MAP_HOME.west);
    const east = mapProjectX(MAP_HOME.east);
    const north = mapProjectY(MAP_HOME.north);
    const south = mapProjectY(MAP_HOME.south);

    this.view.x = Math.max(west, Math.min(east - this.view.w, this.view.x));
    this.view.y = Math.max(north, Math.min(south - this.view.h, this.view.y));
  }

  bindGestures() {
    let dragging = false;
    let last = null;
    let moved = 0;
    const pointers = new Map();
    let pinchDistance = 0;

    this.svg.addEventListener('pointerdown', (event) => {
      pointers.set(event.pointerId, event);
      this.svg.setPointerCapture(event.pointerId);
      if (pointers.size === 1) {
        dragging = true;
        moved = 0;
        last = { x: event.clientX, y: event.clientY };
      }
    });

    this.svg.addEventListener('pointermove', (event) => {
      if (!pointers.has(event.pointerId)) return;
      pointers.set(event.pointerId, event);

      if (pointers.size === 2) {
        const [a, b] = [...pointers.values()];
        const distance = Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
        if (pinchDistance) {
          const midX = (a.clientX + b.clientX) / 2;
          const midY = (a.clientY + b.clientY) / 2;
          this.zoomAt(pinchDistance / distance, midX, midY);
        }
        pinchDistance = distance;
        dragging = false;
        return;
      }

      if (!dragging || !last) return;
      const box = this.svg.getBoundingClientRect();
      const dx = ((event.clientX - last.x) / box.width) * this.view.w;
      const dy = ((event.clientY - last.y) / box.height) * this.view.h;
      moved += Math.abs(event.clientX - last.x) + Math.abs(event.clientY - last.y);
      this.view.x -= dx;
      this.view.y -= dy;
      last = { x: event.clientX, y: event.clientY };
      this.clampView();
      this.applyView();
    });

    const release = (event) => {
      pointers.delete(event.pointerId);
      if (pointers.size < 2) pinchDistance = 0;
      if (pointers.size === 0) {
        dragging = false;
        last = null;
      }
    };
    this.svg.addEventListener('pointerup', release);
    this.svg.addEventListener('pointercancel', release);

    this.svg.addEventListener('wheel', (event) => {
      event.preventDefault();
      this.zoomAt(event.deltaY > 0 ? 1.18 : 1 / 1.18, event.clientX, event.clientY);
    }, { passive: false });

    // แยกการลากออกจากการแตะเลือก ไม่งั้นลากแผนที่แล้วหมุดจะถูกเลือกโดยไม่ตั้งใจ
    this.svg.addEventListener('click', (event) => {
      if (moved > 8) return;
      const pin = event.target.closest('[data-place-id]');
      if (pin && this.onPick) this.onPick(pin.dataset.placeId);
    });
  }

  setCoastline(geojson) {
    this.coast = geojson;
    this.renderLand();
  }

  setPlaces(places) {
    this.places = places;
    this.renderPins();
  }

  setOrigin(origin) {
    this.origin = origin;
    this.renderPins();
  }

  setSelected(id) {
    this.selectedId = id;
    this.renderPins();
  }

  renderLand() {
    const layer = this.svg.querySelector('#mapLand');
    if (!layer || !this.coast) return;

    const paths = this.coast.features.map((feature) => {
      const d = feature.geometry.coordinates.map((ring) => {
        const points = ring.map(([lon, lat]) =>
          `${mapProjectX(lon).toFixed(4)},${mapProjectY(lat).toFixed(4)}`);
        return `M${points.join('L')}Z`;
      }).join('');
      return `<path d="${d}" />`;
    }).join('');

    layer.innerHTML = paths;
  }

  renderPins() {
    const layer = this.svg.querySelector('#mapPins');
    if (!layer) return;

    // ขนาดหมุดคงที่ในสายตาผู้ใช้ จึงต้องหารด้วยอัตราซูมปัจจุบัน
    const scale = this.view.w / this.view0w;
    const r = 0.045 * scale;
    const ring = 0.014 * scale;
    const fontSize = 0.12 * scale;

    let markup = '';

    if (this.origin) {
      const x = mapProjectX(this.origin.lon);
      const y = mapProjectY(this.origin.lat);
      markup += `<circle cx="${x}" cy="${y}" r="${r * 1.1}" fill="${MAP_COLORS.pinActive}"`
        + ` stroke="${MAP_COLORS.sea}" stroke-width="${ring}" />`;
    }

    // ป้ายชื่อเฉพาะตอนซูมเข้ามาพอสมควร ไม่งั้นทั้งภาคจะเป็นตัวหนังสือทับกัน
    const showLabels = scale < 0.4;

    this.places.forEach((place) => {
      const x = mapProjectX(place.lon);
      const y = mapProjectY(place.lat);
      const active = place.id === this.selectedId;
      markup += `<g data-place-id="${escapeHtml(place.id)}" style="cursor:pointer">`
        + `<circle cx="${x}" cy="${y}" r="${active ? r * 1.35 : r}"`
        + ` fill="${active ? MAP_COLORS.pinActive : MAP_COLORS.pin}"`
        + ` stroke="${MAP_COLORS.sea}" stroke-width="${ring}" />`
        + (showLabels || active
          ? `<text x="${x + r * 1.6}" y="${y + fontSize * 0.35}" font-size="${fontSize}"`
            + ` fill="${MAP_COLORS.label}">${escapeHtml(place.name)}</text>`
          : '')
        + '</g>';
    });

    layer.innerHTML = markup;
  }

  /** เลื่อนมุมมองไปที่พิกัดหนึ่ง โดยคงระดับซูมเดิม */
  panTo(lat, lon) {
    this.view.x = mapProjectX(lon) - this.view.w / 2;
    this.view.y = mapProjectY(lat) - this.view.h / 2;
    this.clampView();
    this.applyView();
  }

  /** ซูมเข้าไปหาจุดหนึ่งในระดับที่เห็นรายละเอียดชายฝั่ง */
  focus(lat, lon, spanDeg = 1.2) {
    const w = mapProjectX(spanDeg);
    const ratio = w / this.view.w;
    this.view.w = w;
    this.view.h *= ratio;
    this.panTo(lat, lon);
  }

  reset() {
    this.view.x = mapProjectX(MAP_HOME.west);
    this.view.y = mapProjectY(MAP_HOME.north);
    this.view.w = this.view0w;
    this.view.h = mapProjectY(MAP_HOME.south) - mapProjectY(MAP_HOME.north);
    this.applyView();
  }
}
