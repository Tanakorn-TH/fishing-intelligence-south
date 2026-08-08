"""สร้าง api/lib/places-data.php — รายชื่อสถานที่ภาคใต้พร้อมพิกัด

    python scripts/build-places.py            ใช้ข้อมูลที่ดึงไว้แล้ว (scripts/places.json)
    python scripts/build-places.py --refresh   ดึงใหม่ทั้งหมด

แหล่งข้อมูลสองชั้น แยกหน้าที่กันชัดเจน:

  1. GeoThai (MIT) — "มีอำเภออะไรบ้าง" ชื่อไทย ชื่ออังกฤษ รหัสราชการ
     https://github.com/GeoThai/data
     เป็นรายชื่อทางการ ครบและสะกดถูก ดีกว่าพิมพ์เอาเองซึ่งเคยพลาดมาแล้ว 4 ชื่อ
     ⚠️ ข้อมูลชุดนี้ "ไม่มีพิกัด" เลยสักระดับ จึงใช้แทน geocoder ไม่ได้

  2. Open-Meteo Geocoding (CC BY 4.0) — "อำเภอนั้นอยู่ตรงไหน"
     ให้ lat/lon ซึ่งเป็นสิ่งที่ทุก endpoint ของเราขาดไม่ได้

ทั้งสองแหล่งถูกดึงตอน build แล้วฝังผลลัพธ์ไว้ในโปรเจค
หน้าเว็บจึงไม่ต้องพึ่งบริการของใครตอนผู้ใช้เปิดดู — เหมือนที่ทำกับเส้นชายฝั่ง

⚠️ ห้ามกรอกพิกัดเอง อำเภอไหน geocoder หาไม่เจอให้ตกไป
   ดีกว่าใส่ค่าที่ประมาณเอง ตามกติกาใน docs/api-contract.md
"""
import json, os, sys, time, urllib.parse, urllib.request
from math import asin, cos, radians, sin, sqrt

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(HERE)
RAW = os.path.join(HERE, "places.json")
OUT = os.path.join(REPO, "api", "lib", "places-data.php")

GEOTHAI = "https://raw.githubusercontent.com/GeoThai/data/main/data/v4/geo.json"

PROVINCES = [
    "นครศรีธรรมราช", "กระบี่", "พังงา", "ภูเก็ต", "ตรัง", "พัทลุง",
    "สงขลา", "สตูล", "ปัตตานี", "ยะลา", "นราธิวาส",
]

# หมายและเกาะที่คนไปตกจริงแต่ไม่ได้เป็นอำเภอ จึงไม่มีในรายชื่อราชการ
# ใส่มือเฉพาะ "ชื่อที่จะเอาไปค้น" ส่วนพิกัดยังมาจาก geocoder เหมือนกันทุกจุด
EXTRA_PLACES = [
    ("เกาะพีพี", "กระบี่"),
    ("อ่าวนาง", "กระบี่"),
    ("เกาะหลีเป๊ะ", "สตูล"),
    ("หาดใหญ่", "สงขลา"),
]

LAT_MIN, LAT_MAX = 5.4, 11.5
LON_MIN, LON_MAX = 97.0, 102.5

# ฝั่งทะเลของแต่ละจังหวัด — ข้อมูลภูมิศาสตร์ ไม่ใช่ค่าที่คำนวณได้
# สองฝั่งมีคลื่นลมและฤดูมรสุมคนละแบบ คนวางแผนออกเรือต้องรู้ก่อนเลือกจุด
#
# ข้อจำกัดที่รู้ตัว: ค่านี้บอก "อยู่ฝั่งไหนของคาบสมุทร" ไม่ได้แปลว่าตัวอำเภอติดทะเล
# เช่นหาดใหญ่อยู่ลึกเข้ามาราว 30 กม. แต่ทะเลที่ใกล้ที่สุดคืออ่าวไทย
COAST_BY_PROVINCE = {
    "ระนอง": "andaman", "พังงา": "andaman", "ภูเก็ต": "andaman",
    "กระบี่": "andaman", "ตรัง": "andaman", "สตูล": "andaman",
    "ชุมพร": "gulf", "สุราษฎร์ธานี": "gulf", "นครศรีธรรมราช": "gulf",
    "สงขลา": "gulf", "ปัตตานี": "gulf", "นราธิวาส": "gulf",
    # พัทลุงติดทะเลสาบสงขลา ไม่ใช่ทะเลเปิด สภาพน้ำและการออกเรือคนละเรื่องกับอ่าวไทย
    "พัทลุง": "lake",
    # ยะลาเป็นจังหวัดเดียวในภาคใต้ที่ไม่ติดทะเล
    "ยะลา": "inland",
}

COAST_LABEL = {
    "andaman": "อันดามัน",
    "gulf": "อ่าวไทย",
    "lake": "ทะเลสาบสงขลา",
    "inland": "ไม่ติดทะเล",
}
COAST_FALLBACK_LABEL = "ไม่ระบุฝั่ง"


def geocode(name, require_province=True):
    url = ("https://geocoding-api.open-meteo.com/v1/search?name=%s&count=8&language=th&format=json"
           % urllib.parse.quote(name))
    try:
        data = json.load(urllib.request.urlopen(url, timeout=20))
    except Exception:
        return None
    for r in (data.get("results") or []):
        if r.get("country_code") != "TH":
            continue
        if not (LAT_MIN <= r.get("latitude", 0) <= LAT_MAX
                and LON_MIN <= r.get("longitude", 0) <= LON_MAX):
            continue
        if require_province and not (r.get("admin1") or "").strip():
            continue
        return {"geocoded_name": r["name"], "admin1": (r.get("admin1") or "").strip(),
                "lat": round(r["latitude"], 4), "lon": round(r["longitude"], 4)}
    return None


def haversine(lat1, lon1, lat2, lon2):
    dlat, dlon = radians(lat2 - lat1), radians(lon2 - lon1)
    a = sin(dlat / 2) ** 2 + cos(radians(lat1)) * cos(radians(lat2)) * sin(dlon / 2) ** 2
    return 6371.0 * 2 * asin(sqrt(a))


def load_geothai():
    with urllib.request.urlopen(GEOTHAI, timeout=60) as response:
        return json.load(response)


def collect():
    """ดึงรายชื่อจาก GeoThai แล้วหาพิกัดของแต่ละแห่งจาก geocoder"""
    geothai = load_geothai()
    by_name = {p["name_th"]: p for p in geothai}

    targets = []
    for province in PROVINCES:
        entry = by_name.get(province)
        if entry is None:
            print("  ข้าม %s: ไม่พบใน GeoThai" % province)
            continue

        targets.append({"query": province, "name_th": province, "name_en": entry["name_en"],
                        "province": province, "kind": "province", "code": entry["code"]})

        for district in entry["districts"]:
            targets.append({"query": district["name_th"], "name_th": district["name_th"],
                            "name_en": district["name_en"], "province": province,
                            "kind": "district", "code": district["code"]})

    for name, province in EXTRA_PLACES:
        targets.append({"query": name, "name_th": name, "name_en": "",
                        "province": province, "kind": "landmark", "code": None})

    rows, missed = [], []
    seen = set()
    total = len(targets)

    for i, target in enumerate(targets, 1):
        hit = geocode(target["query"])
        time.sleep(0.35)

        # ชื่ออำเภอบางแห่งซ้ำกับที่อื่นในประเทศ ลองถามแบบระบุจังหวัดกำกับ
        if hit is not None and target["kind"] == "district":
            got = hit["admin1"].replace("จังหวัด", "").strip()
            if got and got != target["province"]:
                retry = geocode("%s %s" % (target["query"], target["province"]))
                time.sleep(0.35)
                if retry is not None:
                    got_retry = retry["admin1"].replace("จังหวัด", "").strip()
                    if got_retry == target["province"]:
                        hit = retry
                    else:
                        # ยังได้จังหวัดไม่ตรง แปลว่า geocoder ไม่รู้จักอำเภอนี้จริง ๆ
                        hit = None
                else:
                    hit = None

        if hit is None:
            missed.append("%s (%s)" % (target["name_th"], target["province"]))
            continue

        key = (round(hit["lat"], 2), round(hit["lon"], 2))
        if key in seen:
            missed.append("%s (%s) พิกัดซ้ำ" % (target["name_th"], target["province"]))
            continue
        seen.add(key)

        rows.append({
            "name": target["name_th"],
            "name_en": target["name_en"],
            "province": "จังหวัด" + target["province"] if target["kind"] != "province"
                        else "จังหวัด" + target["province"],
            "province_plain": target["province"],
            "lat": hit["lat"],
            "lon": hit["lon"],
            "kind": target["kind"],
            "code": target["code"],
        })

        if i % 20 == 0:
            print("  ...%d/%d" % (i, total))

    return {"places": rows, "missed": missed}


def emit(data):
    places = data["places"]
    for p in places:
        p["coast"] = COAST_BY_PROVINCE.get(p["province_plain"], "")

    order = {"province": 0, "district": 1, "landmark": 2}
    places.sort(key=lambda p: (p["province_plain"], order.get(p["kind"], 3), p["name"]))

    lines = [
        "<?php",
        "declare(strict_types=1);",
        "",
        "/**",
        " * places-data.php — รายชื่อสถานที่ภาคใต้พร้อมพิกัด สำหรับ /api/places.php",
        " *",
        " * ⚠️ ไฟล์นี้สร้างด้วยเครื่อง อย่าแก้มือ",
        " *    สร้างด้วย: python scripts/build-places.py",
        " *",
        " * รายชื่อและรหัสอำเภอ: GeoThai (MIT) https://github.com/GeoThai/data",
        " * พิกัด: Open-Meteo Geocoding API (CC BY 4.0)",
        " *",
        " * GeoThai ไม่มีพิกัดในข้อมูล จึงต้องหาพิกัดจาก geocoder แยกต่างหาก",
        " * อำเภอที่ geocoder หาไม่เจอถูกตัดออก ตามกติกาที่ห้ามกรอกพิกัดประมาณเอง",
        " *",
        " * coast บอกว่าอยู่ฝั่งไหนของคาบสมุทร: andaman / gulf / lake / inland",
        " * มาจากตารางจังหวัดในสคริปต์สร้าง ไม่ได้คำนวณจากพิกัด",
        " *",
        " * ชุดนี้เป็น 'จุดอ้างอิงสำหรับดูสภาพอากาศ' ไม่ใช่หมายตกปลา",
        " * หมายจริงอยู่ในตาราง fishing_spots ซึ่งต้องได้พิกัดจากผู้ดูแลเท่านั้น",
        " */",
        "",
        "/**",
        " * @return list<array{name:string, name_en:string, province:string, lat:float,"
        " lon:float, kind:string, coast:string}>",
        " */",
        "function fis_places_dataset(): array",
        "{",
        "    static $places = [",
    ]

    def esc(text):
        return str(text).replace("\\", "\\\\").replace("'", "\\'")

    for p in places:
        lines.append(
            "        ['name' => '%s', 'name_en' => '%s', 'province' => '%s',"
            " 'lat' => %.4f, 'lon' => %.4f, 'kind' => '%s', 'coast' => '%s'],"
            % (esc(p["name"]), esc(p["name_en"]), esc(p["province"]),
               p["lat"], p["lon"], p["kind"], p["coast"])
        )

    lines += [
        "    ];",
        "",
        "    return $places;",
        "}",
        "",
        "/** ชื่อฝั่งทะเลภาษาไทยสำหรับแสดงผล */",
        "function fis_places_coast_label(string $coast): string",
        "{",
        "    $labels = [",
    ]
    for key, label in COAST_LABEL.items():
        lines.append("        '%s' => '%s'," % (key, label))
    lines += [
        "    ];",
        "",
        "    return $labels[$coast] ?? '%s';" % COAST_FALLBACK_LABEL,
        "}",
        "",
    ]

    with open(OUT, "w", encoding="utf-8", newline="\n") as f:
        f.write("\n".join(lines))

    counts = {}
    for p in places:
        counts[p["coast"] or "(ไม่ระบุ)"] = counts.get(p["coast"] or "(ไม่ระบุ)", 0) + 1
    provinces = len({p["province_plain"] for p in places})

    print("wrote %s" % OUT)
    print("  %d places across %d provinces" % (len(places), provinces))
    print("  by coast: %s" % ", ".join("%s=%d" % kv for kv in sorted(counts.items())))
    if data["missed"]:
        print("  missed %d:" % len(data["missed"]))
        for name in data["missed"]:
            print("    - %s" % name)


if __name__ == "__main__":
    if "--refresh" in sys.argv or not os.path.exists(RAW):
        print("fetching GeoThai + geocoding ...")
        collected = collect()
        with open(RAW, "w", encoding="utf-8") as f:
            json.dump(collected, f, ensure_ascii=False, indent=1)
    else:
        print("using cached %s (pass --refresh to re-fetch)" % os.path.basename(RAW))
        with open(RAW, encoding="utf-8") as f:
            collected = json.load(f)

    emit(collected)
