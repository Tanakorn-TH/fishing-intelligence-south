"""สร้าง api/lib/places-data.php — รายชื่อสถานที่ภาคใต้พร้อมพิกัด

    python scripts/build-places.py            ใช้ข้อมูลที่ดึงไว้แล้ว (scripts/places.json)
    python scripts/build-places.py --refresh   ดึงใหม่จาก Open-Meteo Geocoding

แยกเป็นสองขั้นตอนเพราะการดึงต้องยิงปลายทางหลายสิบครั้ง
การแก้ตารางฝั่งทะเลหรือรูปแบบไฟล์ PHP จึงไม่ควรต้องไปรบกวนปลายทางซ้ำ
ผลการดึงเก็บไว้ที่ scripts/places.json ให้ตรวจทานใน diff ได้ว่าพิกัดไหนเปลี่ยนไปบ้าง

⚠️ ห้ามกรอกพิกัดเอง ทุกค่ามาจากปลายทางจริง
สถานที่ที่ค้นไม่เจอจะถูกตัดออก ตามกติกาในสัญญาที่ห้ามใส่ค่าที่ประมาณเอง
"""
import json, os, sys, time, urllib.parse, urllib.request
from math import asin, cos, radians, sin, sqrt

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(HERE)
RAW = os.path.join(HERE, "places.json")
OUT = os.path.join(REPO, "api", "lib", "places-data.php")

PROVINCES = ["ชุมพร", "ระนอง", "สุราษฎร์ธานี", "พังงา", "ภูเก็ต", "กระบี่", "นครศรีธรรมราช",
             "ตรัง", "พัทลุง", "สตูล", "สงขลา", "ปัตตานี", "ยะลา", "นราธิวาส"]

TOWNS = [
    # ฝั่งอ่าวไทย
    "ปะทิว", "หลังสวน", "ท่าชนะ", "ไชยา", "ดอนสัก", "เกาะสมุย", "เกาะพะงัน", "เกาะเต่า",
    "ขนอม", "สิชล", "ท่าศาลา", "ปากพนัง", "หัวไทร", "ระโนด", "สทิงพระ", "สิงหนคร",
    "หาดใหญ่", "จะนะ", "เทพา", "หนองจิก", "ยะหริ่ง", "ปะนาเระ", "สายบุรี", "ไม้แก่น", "ตากใบ",
    # ฝั่งอันดามัน
    "กะเปอร์", "สุขสำราญ", "คุระบุรี", "ตะกั่วป่า", "ท้ายเหมือง", "ตะกั่วทุ่ง", "เกาะยาว",
    "ถลาง", "กะทู้", "อ่าวนาง", "เกาะลันตา", "คลองท่อม", "สิเกา", "กันตัง", "ปะเหลียน",
    "ละงู", "ทุ่งหว้า", "เกาะพีพี", "เกาะหลีเป๊ะ",
]

# ขอบเขตภาคใต้โดยประมาณ กันผลที่หลุดไปภาคอื่นหรือประเทศอื่น
LAT_MIN, LAT_MAX = 5.4, 11.5
LON_MIN, LON_MAX = 97.0, 102.5

# ฝั่งทะเลของแต่ละจังหวัด — ข้อมูลภูมิศาสตร์ ไม่ใช่ค่าที่คำนวณได้
# สองฝั่งมีสภาพคลื่นลมและฤดูมรสุมต่างกันคนละแบบ คนวางแผนออกเรือต้องรู้ก่อนเลือกจุด
#
# ข้อจำกัดที่รู้ตัว: ค่านี้บอก "อยู่ฝั่งไหนของคาบสมุทร" ไม่ได้แปลว่าตัวอำเภอติดทะเล
# เช่นหาดใหญ่อยู่ลึกเข้ามาราว 30 กม. แต่ทะเลที่ใกล้ที่สุดคืออ่าวไทย
COAST_BY_PROVINCE = {
    # ฝั่งอันดามัน
    "ระนอง": "andaman", "พังงา": "andaman", "ภูเก็ต": "andaman",
    "กระบี่": "andaman", "ตรัง": "andaman", "สตูล": "andaman",
    # ฝั่งอ่าวไทย
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
    "": "ไม่ระบุฝั่ง",
}


def fetch(name, require_province):
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
        # รายการในหน้าเว็บจัดกลุ่มและอธิบายด้วยจังหวัด ผลที่ไม่มีจังหวัดจึงใช้ไม่ได้เต็มที่
        # ลองหาผลถัดไปที่ระบุจังหวัดก่อน ค่อยตกมาใช้ผลที่ไม่มีจังหวัดเป็นทางสำรอง
        if require_province and not (r.get("admin1") or "").strip():
            continue
        return {"name": r["name"], "province": (r.get("admin1") or "").strip(),
                "lat": round(r["latitude"], 4), "lon": round(r["longitude"], 4)}
    return None


def haversine(lat1, lon1, lat2, lon2):
    dlat, dlon = radians(lat2 - lat1), radians(lon2 - lon1)
    a = sin(dlat / 2) ** 2 + cos(radians(lat1)) * cos(radians(lat2)) * sin(dlon / 2) ** 2
    return 6371.0 * 2 * asin(sqrt(a))


def nearby_provinces(lat, lon, points, count=2):
    """ชื่อจังหวัดใกล้เคียงที่สุด ใช้เมื่อปลายทางไม่ได้บอกจังหวัดมา

    เกาะกลางทะเลบางแห่งไม่มีจังหวัดติดมา ถ้าตัดทิ้งจะเสียหมายที่คนไปตกจริง
    ถ้าเดาจังหวัดเดียวก็เสี่ยงผิดเพราะบางเกาะอยู่กึ่งกลางระหว่างสองจังหวัดพอดี
    จึงบอกไปทั้งสองชื่อแล้วให้ผู้ใช้ตัดสินเอง — คำนวณจากพิกัดจริงทั้งสองฝั่ง ไม่ใช่การเดา
    """
    ranked = sorted(points, key=lambda p: haversine(lat, lon, p["lat"], p["lon"]))
    return " / ".join(p["name"] for p in ranked[:count])


def collect():
    rows, missed, province_points = [], [], []
    seen = set()

    for kind, names in (("province", PROVINCES), ("town", TOWNS)):
        for name in names:
            hit = fetch(name, require_province=True)
            time.sleep(0.35)

            if hit is None and kind == "town" and province_points:
                hit = fetch(name, require_province=False)
                time.sleep(0.35)
                if hit is not None:
                    hit["province"] = nearby_provinces(hit["lat"], hit["lon"], province_points)
                    hit["province_derived"] = True

            if hit is None:
                missed.append(name)
                continue

            key = (round(hit["lat"], 2), round(hit["lon"], 2))
            if key in seen:
                missed.append(name + " (พิกัดซ้ำกับที่มีแล้ว)")
                continue
            seen.add(key)

            hit["kind"] = kind
            hit["query"] = name
            rows.append(hit)

            if kind == "province":
                province_points.append({"name": name, "lat": hit["lat"], "lon": hit["lon"]})

    return {"places": rows, "missed": missed}


def coast_for(province):
    """หาฝั่งทะเลจากชื่อจังหวัด รองรับกรณีที่จังหวัดเป็นชื่อผสม 'ก / ข'"""
    plain = province.replace("จังหวัด", "").strip()
    if not plain:
        return ""
    names = [n.strip() for n in plain.split("/")]
    coasts = {COAST_BY_PROVINCE.get(n, "") for n in names}
    coasts.discard("")
    if len(coasts) == 1:
        return coasts.pop()
    # อยู่คาบเกี่ยวสองฝั่ง (เช่นเกาะที่อยู่ระหว่างสองจังหวัดคนละฝั่ง) — ไม่ฟันธง
    return ""


def emit(data):
    places = data["places"]
    for p in places:
        p["coast"] = coast_for(p["province"])

    places.sort(key=lambda p: (0 if p["kind"] == "province" else 1, p["province"], p["name"]))

    lines = [
        "<?php",
        "declare(strict_types=1);",
        "",
        "/**",
        " * places-data.php — รายชื่อสถานที่ภาคใต้พร้อมพิกัด สำหรับ /api/places.php",
        " *",
        " * ⚠️ ไฟล์นี้สร้างด้วยเครื่อง อย่าแก้มือ",
        " *    สร้างด้วย: python scripts/build-places.py",
        " *    พิกัดมาจาก Open-Meteo Geocoding API",
        " *    (https://open-meteo.com/en/docs/geocoding-api · CC BY 4.0)",
        " *",
        " * ทุกพิกัดมาจากแหล่งข้อมูลจริง ไม่มีค่าที่ประมาณเอง",
        " * สถานที่ที่ค้นไม่เจอถูกตัดออกโดยตั้งใจ ตามกติกาที่ห้ามกรอกพิกัดประมาณเอง",
        " *",
        " * coast บอกว่าอยู่ฝั่งไหนของคาบสมุทร: andaman / gulf / lake / inland",
        " * สองฝั่งมีคลื่นลมและฤดูมรสุมคนละแบบ คนวางแผนออกเรือต้องรู้ก่อนเลือกจุด",
        " * ค่านี้มาจากตารางจังหวัดในสคริปต์สร้าง ไม่ได้คำนวณจากพิกัด",
        " *",
        " * ชุดนี้เป็น 'จุดอ้างอิงสำหรับดูสภาพอากาศ' ไม่ใช่หมายตกปลา",
        " * หมายจริงอยู่ในตาราง fishing_spots ซึ่งต้องได้พิกัดจากผู้ดูแลเท่านั้น",
        " */",
        "",
        "/**",
        " * @return list<array{name:string, province:string, lat:float, lon:float,"
        " kind:string, coast:string}>",
        " */",
        "function fis_places_dataset(): array",
        "{",
        "    static $places = [",
    ]

    for p in places:
        lines.append(
            "        ['name' => '%s', 'province' => '%s', 'lat' => %.4f, 'lon' => %.4f,"
            " 'kind' => '%s', 'coast' => '%s'],"
            % (p["name"].replace("'", "\\'"), p["province"].replace("'", "\\'"),
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
        if key:
            lines.append("        '%s' => '%s'," % (key, label))
    lines += [
        "    ];",
        "",
        "    return $labels[$coast] ?? '%s';" % COAST_LABEL[""],
        "}",
        "",
    ]

    with open(OUT, "w", encoding="utf-8", newline="\n") as f:
        f.write("\n".join(lines))

    counts = {}
    for p in places:
        counts[p["coast"] or "(ไม่ระบุ)"] = counts.get(p["coast"] or "(ไม่ระบุ)", 0) + 1
    print("wrote %s" % OUT)
    print("  %d places: %s" % (len(places), ", ".join("%s=%d" % kv for kv in sorted(counts.items()))))
    if data["missed"]:
        print("  missed %d: %s" % (len(data["missed"]), ", ".join(data["missed"])))


if __name__ == "__main__":
    if "--refresh" in sys.argv or not os.path.exists(RAW):
        print("fetching from Open-Meteo Geocoding ...")
        data = collect()
        with open(RAW, "w", encoding="utf-8") as f:
            json.dump(data, f, ensure_ascii=False, indent=1)
    else:
        print("using cached %s (pass --refresh to re-fetch)" % os.path.basename(RAW))
        with open(RAW, encoding="utf-8") as f:
            data = json.load(f)

    emit(data)
