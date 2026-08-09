#!/usr/bin/env python3
"""สร้างชั้นหมุดสองชั้นบนแผนที่ — ปะการังเทียม และหมายตกปลา

    python scripts/build-spots.py

ออกสามไฟล์:
    map/reefs-south.json        ปะการังเทียม (กรมประมง)
    map/marks-south.json        หมายตกปลา — ซากเรือ กองหิน แนวปะการัง (OpenStreetMap)
    db/seed-fishing-spots.sql   คำสั่งเติมตาราง fishing_spots ด้วยหมายชุดเดียวกัน

ทำไมต้องมีทั้ง JSON และ SQL:
บัญชีบนเซิร์ฟเวอร์เป็น sftp-only รันคำสั่ง SQL ปลายทางไม่ได้ แผนที่จึงต้องอ่าน
จากไฟล์สแตติกเพื่อให้ทำงานได้แม้ตารางยังว่าง ส่วนไฟล์ SQL เก็บไว้ให้คนที่เข้าถึง
ฐานข้อมูลได้รันเมื่อพร้อม ทั้งสองไฟล์สร้างจากรอบเดียวกันจึงไม่มีทางหลุดจากกัน

⚠️ กติกาเหล็กของโปรเจคนี้: ห้ามกรอกพิกัดที่เดาเอง
ทุกจุดในไฟล์ผลลัพธ์ต้องมาจากพิกัดที่แหล่งข้อมูลเผยแพร่ไว้จริง
ถ้าแหล่งไหนไม่มีพิกัด (เช่นแนวปะการังธรรมชาติของ ทช. ที่มีแต่ชื่อตำบล)
ให้ตัดทิ้งทั้งชุด ไม่ใช่หาพิกัดมาเติมให้ครบ
"""
import io
import json
import math
import os
import re
import sys
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
import zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(HERE)

BBOX = {"west": 96.0, "east": 103.5, "south": 4.5, "north": 12.5}

SOUTH_PROVINCES = {
    "ชุมพร", "ระนอง", "สุราษฎร์ธานี", "นครศรีธรรมราช", "พังงา", "ภูเก็ต", "กระบี่",
    "ตรัง", "พัทลุง", "สตูล", "สงขลา", "ปัตตานี", "ยะลา", "นราธิวาส",
}

REEF_SOURCE = (
    "https://catalog.fisheries.go.th/dataset/70ef193b-8bf0-4537-a7ce-a71c8b5e8b61/"
    "resource/4dd62cbf-70f5-4d41-b48a-7dcb653fc113/download/artificialcoral.xlsx"
)
REEF_LICENSE = "Open Data Common (data.go.th)"

OVERPASS = "https://overpass-api.de/api/interpreter"

# ชั้นที่ยอมรับเป็น "หมาย" — ต้องเป็นโครงสร้างใต้น้ำที่ปลารวมตัวจริง ๆ
# ไม่เอา leisure=fishing เพราะใน OSM แถบนี้เกือบทั้งหมดเป็นบ่อตกปลาบนบกและบ่อในมาเลเซีย
# ซึ่งไม่เกี่ยวกับการวางแผนออกทะเลเลย
OVERPASS_QUERY = """
[out:json][timeout:180];
(
  nwr["seamark:type"="wreck"]({s},{w},{n},{e});
  nwr["historic"="wreck"]({s},{w},{n},{e});
  nwr["natural"="reef"]({s},{w},{n},{e});
  nwr["seamark:type"="rock"]({s},{w},{n},{e});
  nwr["seamark:type"="obstruction"]({s},{w},{n},{e});
);
out center tags;
"""

NE_ADMIN1 = (
    "https://raw.githubusercontent.com/nvkelso/natural-earth-vector/master/geojson/"
    "ne_10m_admin_1_states_provinces.geojson"
)

NS = "{http://schemas.openxmlformats.org/spreadsheetml/2006/main}"


def fetch(url, data=None, label=""):
    print(f"  ดึง {label or url} …", flush=True)
    request = urllib.request.Request(
        url,
        data=data,
        headers={"User-Agent": "fishing-intelligence-south/build-spots (fishing.yru.ac.th)"},
    )
    with urllib.request.urlopen(request, timeout=300) as response:
        return response.read()


# ---------------------------------------------------------------- ปะการังเทียม

def column_index(ref):
    letters = re.match(r"([A-Z]+)", ref).group(1)
    n = 0
    for ch in letters:
        n = n * 26 + ord(ch) - 64
    return n - 1


def read_xlsx(blob):
    """อ่าน xlsx ด้วยไลบรารีมาตรฐานล้วน โปรเจคนี้ไม่มี dependency ฝั่ง build ที่ต้องติดตั้ง"""
    z = zipfile.ZipFile(io.BytesIO(blob))
    shared = []
    if "xl/sharedStrings.xml" in z.namelist():
        root = ET.fromstring(z.read("xl/sharedStrings.xml"))
        shared = ["".join(t.text or "" for t in si.iter(f"{NS}t")) for si in root.findall(f"{NS}si")]

    sheet = sorted(n for n in z.namelist() if re.match(r"xl/worksheets/sheet\d+\.xml$", n))[0]
    rows = []
    for row in ET.fromstring(z.read(sheet)).iter(f"{NS}row"):
        cells = {}
        for c in row.findall(f"{NS}c"):
            v = c.find(f"{NS}v")
            if v is None:
                inline = c.find(f"{NS}is")
                value = "".join(x.text or "" for x in inline.iter(f"{NS}t")) if inline is not None else ""
            else:
                value = shared[int(v.text)] if c.get("t") == "s" else v.text
            cells[column_index(c.get("r"))] = value
        if cells:
            rows.append([cells.get(i, "") for i in range(max(cells) + 1)])
    return rows


def build_reefs():
    rows = read_xlsx(fetch(REEF_SOURCE, label="ปะการังเทียม กรมประมง"))
    header, data = rows[0], rows[1:]
    col = {name: i for i, name in enumerate(header)}

    def cell(row, name):
        i = col.get(name)
        return (row[i].strip() if i is not None and i < len(row) and row[i] is not None else "")

    def number(row, name):
        raw = cell(row, name)
        try:
            return float(raw)
        except ValueError:
            return None

    reefs = []
    dropped = {"ไม่ใช่ภาคใต้": 0, "พิกัดอ่านไม่ได้": 0, "พิกัดนอกกรอบ": 0}

    for row in data:
        province = cell(row, "จังหวัดที่จัดวาง")
        if province not in SOUTH_PROVINCES:
            dropped["ไม่ใช่ภาคใต้"] += 1
            continue

        lat, lon = number(row, "Latitude"), number(row, "Longitude")
        if lat is None or lon is None:
            dropped["พิกัดอ่านไม่ได้"] += 1
            continue
        # กันไฟล์ต้นทางสลับแกนหรือพิมพ์ผิด — จุดที่หลุดกรอบภาคใต้ต้องไม่เงียบ ๆ ผ่านไป
        if not (BBOX["south"] <= lat <= BBOX["north"] and BBOX["west"] <= lon <= BBOX["east"]):
            dropped["พิกัดนอกกรอบ"] += 1
            print(f"    ข้าม: {province} พิกัด {lat},{lon} อยู่นอกกรอบภาคใต้", file=sys.stderr)
            continue

        reefs.append({
            "site": cell(row, "ชื่อแหล่งอาศัย"),
            "site_code": cell(row, "รหัสแหล่งอาศัย"),
            "province": province,
            "amphoe": cell(row, "อำเภอที่จัดวาง"),
            "tambon": cell(row, "ตำบลที่จัดวาง"),
            "lat": round(lat, 6),
            "lon": round(lon, 6),
            "material": cell(row, "วัสดุที่ใช้จัดวาง"),
            "unit_size": cell(row, "ขนาดวัสดุที่จัดวาง"),
            "unit_count": number(row, "จำนวนวัสดุที่จัดวาง"),
            "depth_m": number(row, "ความลึกน้ำ"),
            "distance_from_shore_km": number(row, "ระยะห่างฝั่งกิโลเมตร"),
            "budget_year": cell(row, "ปีงบประมาณ"),
            "datum": cell(row, "พื้นหลักฐานพิกัด"),
        })

    print(f"  ปะการังเทียม: เก็บ {len(reefs)} จุด, ตัดออก {dropped}")
    return reefs


# ------------------------------------------------------------------ หมายจาก OSM

def build_marks(provinces):
    query = OVERPASS_QUERY.format(
        s=BBOX["south"], w=BBOX["west"], n=BBOX["north"], e=BBOX["east"]
    )
    blob = fetch(OVERPASS, data=urllib.parse.urlencode({"data": query}).encode(), label="OpenStreetMap")
    elements = json.loads(blob)["elements"]

    marks = []
    dropped = {"ไม่มีชื่อ": 0, "ไม่มีพิกัด": 0, "ไม่ใช่น่านน้ำไทย": 0}
    seen = set()

    for element in elements:
        tags = element.get("tags", {})
        name = tags.get("name:th") or tags.get("name")
        if not name:
            # วงแนวปะการังที่ไม่มีชื่อไม่ใช่ "หมาย" ที่คนนัดกันได้ จึงไม่เอา
            dropped["ไม่มีชื่อ"] += 1
            continue

        lat = element.get("lat") or (element.get("center") or {}).get("lat")
        lon = element.get("lon") or (element.get("center") or {}).get("lon")
        if lat is None or lon is None:
            dropped["ไม่มีพิกัด"] += 1
            continue

        nearest = nearest_admin(lat, lon, provinces)
        if nearest is None or nearest["country"] != "Thailand" or nearest["province"] not in SOUTH_PROVINCES:
            # กรอบสี่เหลี่ยมของเรากินน่านน้ำมาเลเซียและเมียนมาไปด้วย
            # ต้องคัดออก ไม่งั้นจะมีหมายฝั่งกลันตันโผล่มาในรายการของคนสงขลา
            dropped["ไม่ใช่น่านน้ำไทย"] += 1
            continue

        key = (name, round(lat, 4), round(lon, 4))
        if key in seen:
            continue
        seen.add(key)

        marks.append({
            "name": name,
            "name_en": tags.get("name:en") or (tags.get("name") if tags.get("name") != name else None),
            "kind": mark_kind(tags),
            "province": nearest["province"],
            "lat": round(float(lat), 6),
            "lon": round(float(lon), 6),
            "osm_type": element.get("type"),
            "osm_id": element.get("id"),
        })

    marks.sort(key=lambda m: (m["province"], m["name"]))
    print(f"  หมายจาก OSM: เก็บ {len(marks)} จุด, ตัดออก {dropped}")
    return marks


def mark_kind(tags):
    if tags.get("seamark:type") == "wreck" or tags.get("historic") == "wreck":
        return "wreck"
    if tags.get("seamark:type") in ("rock", "obstruction"):
        return "rock"
    return "reef"


# --------------------------------------------------- จับหมุดเข้าจังหวัดที่ใกล้ที่สุด

def load_admin1():
    """โหลดขอบเขตระดับจังหวัด/รัฐ ของไทยและเพื่อนบ้าน ไว้ใช้ระบุว่าหมุดอยู่ประเทศไหน"""
    blob = fetch(NE_ADMIN1, label="ขอบเขตจังหวัด Natural Earth (ไฟล์ใหญ่ ใจเย็น ๆ)")
    collection = json.loads(blob)

    keep = []
    for feature in collection["features"]:
        props = feature["properties"]
        if props.get("admin") not in ("Thailand", "Malaysia", "Myanmar"):
            continue

        rings = []
        geom = feature["geometry"]
        parts = [geom["coordinates"]] if geom["type"] == "Polygon" else geom["coordinates"]
        for polygon in parts:
            for ring in polygon:
                if any(BBOX["west"] - 2 <= x <= BBOX["east"] + 2
                       and BBOX["south"] - 2 <= y <= BBOX["north"] + 2 for x, y in ring):
                    rings.append(ring)
        if not rings:
            continue

        keep.append({
            "country": props.get("admin"),
            # Natural Earth เก็บชื่อไทยไว้ใน name_local เป็น "จังหวัดสงขลา"
            # ต้องตัดคำนำหน้าออกให้เหลือ "สงขลา" เพื่อให้ตรงกับชื่อที่ทั้งโปรเจคใช้
            "province": re.sub(r"^จังหวัด", "", (props.get("name_local") or props.get("name") or "").strip()),
            "rings": rings,
        })

    # ถ้าคีย์ชื่อในไฟล์ต้นทางเปลี่ยน ตัวกรองจะไม่ตรงสักจังหวัดแล้วหมุดจะหายเงียบ ๆ
    # เคยเกิดมาแล้วรอบหนึ่ง จึงตรวจตรงนี้ให้พังเสียงดังแทนที่จะได้ไฟล์ว่าง
    found = {p["province"] for p in keep if p["country"] == "Thailand"}
    missing = SOUTH_PROVINCES - found
    if missing:
        raise SystemExit(f"จับคู่ชื่อจังหวัดไม่ได้: {sorted(missing)} — ตรวจคีย์ชื่อใน Natural Earth")

    print(f"  ขอบเขตที่ใช้จับคู่: {len(keep)} จังหวัด/รัฐ (ภาคใต้ครบ 14)")
    return keep


def nearest_admin(lat, lon, provinces):
    """หาจังหวัดที่ใกล้จุดนี้ที่สุด

    หมุดกลางทะเลไม่ได้อยู่ในรูปหลายเหลี่ยมของจังหวัดไหนเลย เพราะขอบเขตการปกครอง
    ของ Natural Earth จบที่ชายฝั่ง การถามว่า "อยู่ในจังหวัดอะไร" จึงตอบไม่ได้ตรง ๆ
    สิ่งที่ตอบได้อย่างซื่อสัตย์คือ "จังหวัดที่ใกล้ที่สุด" ซึ่งพอสำหรับการจัดกลุ่มให้คนอ่าน
    และเพียงพอสำหรับคัดหมุดฝั่งมาเลเซียออกด้วย
    """
    best = None
    best_distance = float("inf")
    kx = math.cos(math.radians(lat))

    for province in provinces:
        for ring in province["rings"]:
            for x, y in ring:
                dx = (x - lon) * kx
                dy = y - lat
                distance = dx * dx + dy * dy
                if distance < best_distance:
                    best_distance = distance
                    best = province
    return best


# ------------------------------------------------------------------------ เขียนไฟล์

def load_coast_rings():
    path = os.path.join(REPO, "map", "coastline-south.json")
    if not os.path.exists(path):
        print("  ข้ามการตรวจว่าอยู่ในน้ำ: ยังไม่มี map/coastline-south.json")
        return []
    with io.open(path, encoding="utf-8") as f:
        land = json.load(f)
    return [ring for feature in land["features"] for ring in feature["geometry"]["coordinates"]]


def point_in_ring(ring, lon, lat):
    hit = False
    j = len(ring) - 1
    for i in range(len(ring)):
        xi, yi = ring[i]
        xj, yj = ring[j]
        if (yi > lat) != (yj > lat) and lon < (xj - xi) * (lat - yi) / (yj - yi) + xi:
            hit = not hit
        j = i
    return hit


def km_to_coast(rings, lon, lat):
    kx = math.cos(math.radians(lat))
    best = float("inf")
    for ring in rings:
        for x, y in ring:
            distance = math.hypot((x - lon) * kx, y - lat)
            if distance < best:
                best = distance
    return best * 111.0


# หมุดที่โผล่กลางเกาะคือพิกัดผิด และบนแผนที่ตกปลามันคือความผิดที่คนเห็นทันที
# แต่เส้นชายฝั่งของเราเป็น 1:10m ที่ลดจุดไว้ 400 ม. หมายชิดฝั่งจึงตกในแผ่นดินได้ตามปกติ
# จึงตัดทิ้งเฉพาะจุดที่ลึกเข้าไปเกินกว่าความคลาดเคลื่อนของเส้นจะอธิบายได้
INLAND_LIMIT_KM = 2.0


def drop_inland(points, rings, label):
    if not rings:
        return points
    kept, dropped = [], []
    for point in points:
        if point_in_ring_any(rings, point["lon"], point["lat"]):
            inland = km_to_coast(rings, point["lon"], point["lat"])
            if inland > INLAND_LIMIT_KM:
                dropped.append((point, inland))
                continue
        kept.append(point)
    for point, inland in dropped:
        name = point.get("name") or point.get("site") or "?"
        print(f"    ตัดออก ({label}): {name} {point['lat']},{point['lon']} "
              f"อยู่ลึกในแผ่นดิน {inland:.1f} กม.", file=sys.stderr)
    print(f"  ตรวจว่าอยู่ในน้ำ ({label}): เก็บ {len(kept)}, ตัด {len(dropped)}")
    return kept


def point_in_ring_any(rings, lon, lat):
    return any(point_in_ring(ring, lon, lat) for ring in rings)


def write_json(path, payload):
    full = os.path.join(REPO, path)
    os.makedirs(os.path.dirname(full), exist_ok=True)
    with io.open(full, "w", encoding="utf-8", newline="\n") as f:
        json.dump(payload, f, ensure_ascii=False, separators=(",", ":"))
    print(f"wrote {path}  ({os.path.getsize(full) / 1024:.1f} KB)")


def sql_string(value):
    return "'" + str(value).replace("\\", "\\\\").replace("'", "''") + "'"


def write_seed_sql(marks):
    lines = [
        "-- db/seed-fishing-spots.sql — สร้างโดย scripts/build-spots.py ห้ามแก้ด้วยมือ",
        "--",
        "-- เติมตาราง fishing_spots ด้วยหมายที่มีพิกัดเผยแพร่จริงจาก OpenStreetMap",
        "-- ทุกแถวมี osm_type/osm_id อยู่ในคอมเมนต์ ตรวจย้อนกลับได้ทีละจุด",
        "--",
        "-- ที่มา: OpenStreetMap contributors, ODbL 1.0 (https://www.openstreetmap.org/copyright)",
        "-- fishing_style ตั้งเป็น bottom เพราะซากเรือ กองหิน และแนวปะการังคืองานหน้าดินทั้งหมด",
        "-- ถ้าหมายไหนเหมาะกับงานอื่นให้ผู้ดูแลแก้ทีหลัง อย่าเดาแทนคนที่เคยไปจริง",
        "--",
        "-- MySQL เก็บ SRID 4326 แบบละติจูดก่อน จึงต้องใส่ ST_GeomFromText('POINT(lat lon)')",
        "-- ไม่ใช่ลำดับ lon lat อย่างที่คนคุ้น PostGIS คาด",
        "",
        "INSERT INTO fishing_spots (name, province, fishing_style, geom, is_public) VALUES",
    ]
    values = []
    for mark in marks:
        point = f"POINT({mark['lat']} {mark['lon']})"
        values.append(
            f"  ({sql_string(mark['name'])}, {sql_string(mark['province'])}, 'bottom', "
            f"ST_GeomFromText({sql_string(point)}, 4326), TRUE)"
            f"  -- {mark['kind']} · {mark['osm_type']}/{mark['osm_id']}"
        )
    lines.append(",\n".join(values))
    # ชุดข้อมูลอัปเดตได้ รันซ้ำต้องไม่ระเบิดเพราะ unique key ชื่อ+จังหวัด
    lines.append("ON DUPLICATE KEY UPDATE geom = VALUES(geom), is_public = VALUES(is_public);")
    lines.append("")

    full = os.path.join(REPO, "db", "seed-fishing-spots.sql")
    os.makedirs(os.path.dirname(full), exist_ok=True)
    with io.open(full, "w", encoding="utf-8", newline="\n") as f:
        f.write("\n".join(lines))
    print(f"wrote db/seed-fishing-spots.sql  ({len(marks)} แถว)")


def main():
    print("สร้างชั้นหมุดบนแผนที่")
    reefs = build_reefs()
    provinces = load_admin1()
    marks = build_marks(provinces)

    rings = load_coast_rings()
    reefs = drop_inland(reefs, rings, "ปะการังเทียม")
    marks = drop_inland(marks, rings, "หมาย")

    if not reefs:
        raise SystemExit("ไม่ได้ปะการังเทียมสักจุด — ตรวจแหล่งข้อมูลก่อนเขียนทับไฟล์เดิม")
    if not marks:
        raise SystemExit("ไม่ได้หมายสักจุด — ตรวจ Overpass ก่อนเขียนทับไฟล์เดิม")

    write_json("map/reefs-south.json", {
        "metadata": {
            "source": "กรมประมง — ข้อมูลแหล่งการจัดสร้างแหล่งอาศัยสัตว์ทะเลปะการังเทียม",
            "source_url": REEF_SOURCE,
            "license": REEF_LICENSE,
            "datum": "WGS1984",
            "note": "พิกัดจุดจัดวางตามที่กรมประมงเผยแพร่ ความลึกเป็นค่าที่บันทึกตอนสำรวจ "
                    "ไม่ใช่ค่าปัจจุบันและไม่ใช่ข้อมูลเดินเรือ",
        },
        "reefs": reefs,
    })

    write_json("map/marks-south.json", {
        "metadata": {
            "source": "OpenStreetMap contributors",
            "source_url": "https://www.openstreetmap.org/copyright",
            "license": "ODbL 1.0",
            "note": "ซากเรือ กองหิน และแนวปะการังที่มีชื่อและมีพิกัดใน OpenStreetMap "
                    "จังหวัดคือจังหวัดที่ใกล้ที่สุด เพราะหมายกลางทะเลไม่ได้อยู่ในเขตปกครองใด",
        },
        "marks": marks,
    })

    write_seed_sql(marks)


if __name__ == "__main__":
    main()
