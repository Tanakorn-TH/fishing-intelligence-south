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

  /* เส้นเขตแดน — ม่วงอมเทา ไม่ชนกับน้ำตาลของชายฝั่งและน้ำเงินของเส้นความลึก
     เขตประเทศเข้มกว่าเขตจังหวัดโดยตั้งใจ เพราะการข้ามเส้นสองอันนี้
     มีผลต่างกันคนละเรื่อง อันหนึ่งแค่เปลี่ยนป้ายทะเบียน อีกอันคือเข้าน่านน้ำต่างชาติ
     ตรวจ contrast บนพื้นแผ่นดินแล้ว: ประเทศ 7.03:1 จังหวัด 3.19:1 */
  borderCountry: '#5d3a63',
  borderProvince: '#8f7194',

  /* ปะการังเทียมกับหมายใช้สีเดียวกัน แล้วแยกกันด้วยรูปทรง
     เพราะทั้งคู่คือ "โครงสร้างใต้น้ำที่ปลารวมตัว" เหมือนกัน ต่างกันแค่ใครสร้าง
     ให้รูปทรงเป็นตัวบอกชนิด สีจึงไม่ต้องเพิ่มอีกเฉด ตรวจแล้ว 5.61:1 บนพื้นทะเล */
  structure: '#0e6b60',
  structureActive: '#c8542a',
};

/* ไอคอนวาดในระบบพิกัดหน่วยเดียว กว้างยาวราว -1.2 ถึง 1.2 แล้วค่อยย่อขยายตอนวาด
   เขียนเป็น path เดียวต่อหนึ่งหมุด เพราะบนแผนที่มีหมุดกว่าร้อยจุด
   ถ้าแตกเป็นหลาย element ต่อหมุด จำนวน node จะพุ่งขึ้นเป็นหลายเท่าโดยไม่ได้อะไรกลับมา */

/* ปลา — ลำตัวโค้งกับหางสามเหลี่ยม หันหัวไปทางขวา */
const ICON_FISH = 'M1,0C0.45,-0.62 -0.3,-0.58 -0.6,0C-0.3,0.58 0.45,0.62 1,0Z'
  + 'M-0.6,0L-1.15,-0.52L-1.15,0.52Z';

/* ปะการังเทียม — สี่เหลี่ยมสามอันวางซ้อนกัน
   ล้อของจริงตรง ๆ เพราะที่กรมประมงวางคือแท่งคอนกรีตทรงลูกบาศก์ 1.5x1.5x1.5 เมตร
   คนที่เคยเห็นของจริงจะอ่านสัญลักษณ์นี้ออกทันทีโดยไม่ต้องดูคำอธิบาย */
const ICON_REEF = (() => {
  const side = 0.78;
  const box = (x, y) => `M${x},${y}h${side}v${side}h${-side}Z`;
  return box(-0.82, 0.04) + box(0.04, 0.04) + box(-0.39, -0.82);
})();

/* สีเส้นความลึก ผ่านการตรวจ contrast บนพื้นครีมมาแล้ว 3.34:1 ถึง 12.96:1
   ไล่เข้มตามความลึก ซึ่งเป็นสัญชาตญาณที่คนทั่วไปเข้าใจตรงกัน */
const DEPTH_COLORS = {
  10: '#4a89b4',
  20: '#3a77a6',
  30: '#2b6291',
  50: '#1f4d75',
  100: '#163a57',
  200: '#102a3f',
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
    this.depth = null;
    this.borders = null;
    this.places = [];
    this.sites = [];         // ปะการังเทียมและหมาย รวมอยู่ชั้นเดียวกัน
    this.selectedId = null;
    this.selectedSiteKey = null;
    this.origin = null;      // ตำแหน่งผู้ใช้ ถ้ามี
    this.onPick = null;
    this.onPickSite = null;

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
    this.renderBorders();
    this.renderDepth();
    this.renderSites();
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
      if (pin && this.onPick) {
        this.onPick(pin.dataset.placeId);
        return;
      }

      // ปะการังเทียมกับหมายเลือกได้เหมือนกัน — คนตกปลาสนใจพิกัดของกองมากกว่าชื่อตำบล
      const site = event.target.closest('[data-site-key]');
      if (site && this.onPickSite) this.onPickSite(site.dataset.siteKey);
    });
  }

  setCoastline(geojson) {
    this.coast = geojson;
    this.renderLand();
  }

  /**
   * เส้นความลึก
   *
   * ⚠️ ต้องวาดเป็นเส้นประเสมอ ห้ามเปลี่ยนเป็นเส้นทึบ
   * ตามสัญลักษณ์มาตรฐาน IHO เส้นทึบแปลว่า "สำรวจมาแล้ว" ส่วนเส้นประแปลว่า "ประมาณ"
   * ข้อมูลชุดนี้ความละเอียด 1.85 กม. ไม่มีหินโสโครก ไม่มีร่องน้ำ
   * คนที่อ่านแผนที่เดินเรือเป็นจะเข้าใจจากรูปแบบเส้นทันทีโดยไม่ต้องอ่านคำเตือน
   */
  setDepth(geojson) {
    this.depth = geojson;
    this.renderDepth();
  }

  renderDepth() {
    const layer = this.svg.querySelector('#mapDepth');
    if (!layer || !this.depth) return;

    // ความหนาและระยะประคงที่ในสายตา จึงต้องหารด้วยอัตราซูมปัจจุบัน
    const scale = this.view.w / this.view0w;
    const width = 0.011 * scale;
    const dash = `${0.055 * scale} ${0.04 * scale}`;

    layer.innerHTML = this.depth.features.map((feature) => {
      const depth = feature.properties.depth_m;
      const color = DEPTH_COLORS[depth] || '#2b6291';
      const d = feature.geometry.coordinates.map((line) => {
        const points = line.map(([lon, lat]) =>
          `${mapProjectX(lon).toFixed(4)},${mapProjectY(lat).toFixed(4)}`);
        return `M${points.join('L')}`;
      }).join('');

      return `<path d="${d}" fill="none" stroke="${color}" stroke-width="${width}"`
        + ` stroke-dasharray="${dash}" stroke-linecap="round" />`;
    }).join('');
  }

  /**
   * เส้นเขตแดนประเทศและเส้นแบ่งจังหวัด
   *
   * วาดเป็นเส้นทึบ ต่างจากเส้นความลึกที่ต้องเป็นเส้นประเสมอ
   * ไม่ใช่เรื่องความสวย แต่เพราะสองอย่างนี้ตอบคนละคำถาม
   * เขตแดนเป็นข้อตกลงที่มีตำแหน่งแน่นอน ส่วนความลึกเป็นค่าประมาณจากการหยั่ง
   *
   * ⚠️ ในทะเลไม่มีเส้น — Natural Earth ให้เฉพาะเขตแดนบนบก
   * ห้ามใช้ตัดสินว่าเรืออยู่ในน่านน้ำประเทศไหน
   */
  setBorders(geojson) {
    this.borders = geojson;
    this.renderBorders();
  }

  renderBorders() {
    const layer = this.svg.querySelector('#mapBorders');
    if (!layer || !this.borders) return;

    const scale = this.view.w / this.view0w;

    layer.innerHTML = this.borders.features.map((feature) => {
      const country = feature.properties.kind === 'country';
      const color = country ? MAP_COLORS.borderCountry : MAP_COLORS.borderProvince;
      const width = (country ? 0.014 : 0.008) * scale;
      const points = feature.geometry.coordinates.map(([lon, lat]) =>
        `${mapProjectX(lon).toFixed(4)},${mapProjectY(lat).toFixed(4)}`);

      return `<path d="M${points.join('L')}" fill="none" stroke="${color}"`
        + ` stroke-width="${width}" stroke-linecap="round" stroke-linejoin="round" />`;
    }).join('');
  }

  /**
   * ปะการังเทียมและหมาย
   *
   * รับมาเป็นชั้นเดียวเพราะทั้งคู่คือโครงสร้างใต้น้ำที่ใช้ตัดสินใจแบบเดียวกัน
   * แต่ละรายการต้องมี key ที่ไม่ซ้ำ ชนิด (reef | mark) และพิกัด
   */
  setSites(sites) {
    this.sites = sites;
    this.renderSites();
  }

  setSelectedSite(key) {
    this.selectedSiteKey = key;
    this.renderSites();
  }

  renderSites() {
    const layer = this.svg.querySelector('#mapSites');
    if (!layer) return;

    // ขนาดคงที่ในสายตา จึงหารด้วยอัตราซูมเหมือนหมุดและเส้นความลึก
    const scale = this.view.w / this.view0w;
    // ต้องใหญ่พอให้แยกออกว่าเป็นปลาหรือสี่เหลี่ยมซ้อน ไม่งั้นรูปทรงที่ใช้แทนชนิดก็เปล่าประโยชน์
    // เคยตั้งไว้ 0.038 แล้ววัดได้ 4.2 พิกเซล ซึ่งเล็กเกินกว่าจะเห็นเป็นรูปอะไร
    // ค่านี้ให้ราว 11 พิกเซล กองที่อยู่ชิดกันจะทับกันบ้างตอนดูทั้งภาค
    // ซึ่งยอมรับได้ เพราะมันสื่อว่าตรงนั้นมีกองหนาแน่นจริง
    const size = 0.072 * scale;
    const stroke = 0.01 * scale;
    const fontSize = 0.1 * scale;

    // ป้ายชื่อเฉพาะตอนซูมเข้ามาแล้ว ไม่งั้นหมายร้อยกว่าจุดจะทับกันจนอ่านไม่ออก
    const showLabels = scale < 0.22;

    layer.innerHTML = this.sites.map((site) => {
      const x = mapProjectX(site.lon);
      const y = mapProjectY(site.lat);
      const active = site.key === this.selectedSiteKey;
      const color = active ? MAP_COLORS.structureActive : MAP_COLORS.structure;
      const icon = site.kind === 'reef' ? ICON_REEF : ICON_FISH;
      const s = active ? size * 1.3 : size;

      // เส้นขอบสีพื้นทะเลทำให้หมุดไม่จมหายเมื่อทับเส้นความลึกหรือเส้นเขตแดน
      return `<g data-site-key="${escapeHtml(site.key)}" style="cursor:pointer">`
        + `<path transform="translate(${x.toFixed(4)},${y.toFixed(4)}) scale(${s.toFixed(5)})"`
        + ` d="${icon}" fill="${color}" stroke="${MAP_COLORS.sea}" stroke-width="${(stroke / s).toFixed(4)}"`
        + ` stroke-linejoin="round" />`
        + (showLabels || active
          ? `<text x="${(x + s * 1.5).toFixed(4)}" y="${(y + fontSize * 0.35).toFixed(4)}"`
            + ` font-size="${fontSize}" fill="${MAP_COLORS.label}">${escapeHtml(site.label)}</text>`
          : '')
        + '</g>';
    }).join('');
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
