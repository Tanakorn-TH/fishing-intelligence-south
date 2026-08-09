#!/usr/bin/env python3
"""สร้างระเบียนปลาไทยที่ตกได้ด้วยเบ็ด

    python scripts/build-species.py
    python scripts/build-species.py --refresh    ทิ้งแคชแล้วดึงใหม่ทั้งหมด

ออกไฟล์เดียว:
    data/species-south.json

ขอบเขต: ปลาที่พบในไทยตามบัญชีของ FishBase (native / endemic / introduced)
และติดธงว่าเป็นปลาเกม หรือจับได้ด้วยเบ็ดและสาย — ตอนวัดได้ 469 ชนิด

ทำไมแยกตามสภาพน้ำ:
FishBase คำนวณ "อุณหภูมิที่ปลาชอบ" จากแบบจำลองการกระจายตัวในทะเล ปลาน้ำจืดจึงได้
ศูนย์เปอร์เซ็นต์ทั้งกลุ่ม ไม่ใช่ขาดเป็นหย่อม ๆ แต่ขาดอย่างเป็นระบบ ระเบียนนี้จึงแยก
สภาพน้ำเป็นชั้นแรก เพื่อให้หน้าเว็บเลือกได้ว่าจะแสดงอะไรกับกลุ่มไหน แทนที่จะโชว์
ตารางเดียวที่มีช่องว่างเต็มไปหมดโดยไม่บอกว่าทำไม

⚠️ กติกาของโปรเจคนี้ที่ใช้กับไฟล์นี้ด้วย:
ทุกค่าต้องมาจากแหล่งที่เผยแพร่จริง และต้องบอกได้ว่ามาจากแหล่งไหน
ค่าไหนไม่มีให้ปล่อยว่าง ห้ามเติมค่าประมาณ ห้ามเดาชื่อไทยจากคำทับศัพท์

⚠️ สัญญาอนุญาต:
FishBase เป็น CC BY-NC — ห้ามใช้เชิงพาณิชย์ เว็บมหาวิทยาลัยใช้ได้
ถ้าวันหนึ่งโครงการนี้จะหารายได้ ต้องติดต่อทีม FishBase ก่อน
รูปทุกภาพเก็บสัญญาอนุญาตและชื่อผู้ถ่ายไว้รายภาพ ไม่ใช่เขียนรวมว่า "จากวิกิพีเดีย"
"""
import argparse
import csv
import io
import json
import os
import re
import sys
import time
import unicodedata
import urllib.error
import urllib.parse
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(HERE)
CACHE = os.path.join(HERE, ".cache", "species")

UA = "fishing-intelligence-south/build-species (fishing.yru.ac.th)"

# ---------------------------------------------------------------- แหล่งข้อมูล

FISHBASE_VERSION = "v24.07"
FISHBASE_BASE = f"https://data.source.coop/cboettig/fishbase/fb/{FISHBASE_VERSION}/csv"
FISHBASE_LICENSE = "CC BY-NC 4.0 — FishBase.org Team, snapshot โดย Carl Boettiger"
FISHBASE_TABLES = ("species", "country", "estimate", "comnames", "swimming")

# รหัสประเทศไทยในตาราง country ของ FishBase
THAILAND_C_CODE = "764"
THAILAND_STATUS = ("native", "endemic", "introduced")

DOF_NAMES_URL = (
    "https://data.go.th/dataset/62462211-e805-496e-a5aa-0c92f9e3fc98/"
    "resource/ce7f4a78-71db-4754-9084-edca971903bd/download/dof_fishbase.csv"
)
DOF_LICENSE = "Creative Commons Attribution (data.go.th)"

# หน่วงระหว่างคำขอวิกิพีเดีย — ยิงถี่กว่านี้แล้วโดน 429 ตอนทดสอบ
WIKI_PAUSE = 1.0

WIKI_API = "https://en.wikipedia.org/w/api.php"
COMMONS_API = "https://commons.wikimedia.org/w/api.php"
INAT_API = "https://api.inaturalist.org/v1/taxa"

# ------------------------------------------------------- เพดานความครอบคลุม
# ตัวเลขที่วัดได้จริงตอนสร้างครั้งแรก ถ้ารอบหลังตกต่ำกว่านี้แปลว่าแหล่งข้อมูลเปลี่ยน
# หรือโค้ดพัง ต้องดังทันที ไม่ใช่เขียนทับไฟล์เดิมด้วยข้อมูลที่บางลงเงียบ ๆ
#
# เคยเจอมาแล้วกับคลอโรฟิลล์: ตัวแยก CSV พังแล้วคืน null ทุกจุด ระบบไม่ร้องอะไรเลย
# กว่าจะรู้ก็ผ่านไปหลายรอบดีพลอย ด่านนี้มีไว้กันเรื่องเดิมซ้ำ
FLOORS = {
    "species": 440,        # วัดได้ 469
    "thai_name": 180,      # วัดได้ 200
    "image": 400,          # วัดได้ ~441
    "temp_pref_marine": 0.95,   # วัดได้ 0.99 เฉพาะกลุ่มที่แตะน้ำเค็ม
}

THAI_CHARS = re.compile(r"[฀-๿]")
BINOMIAL = re.compile(r"^([A-Z][a-z]+)\s+([a-z][a-z\-]+)")

# รูปที่ใช้ได้ต้องเปิดให้ใช้ซ้ำจริง
# ปฏิเสธ:
#   - ไม่มีข้อมูลสัญญาอนุญาต (iNaturalist คืน null แปลว่าสงวนลิขสิทธิ์เต็ม)
#   - ND (no derivatives) เพราะเราต้องย่อขนาดรูปเพื่อแสดงบนมือถือ ซึ่งนับเป็นดัดแปลง
INAT_ALLOWED = ("cc0", "pd", "cc-by", "cc-by-sa", "cc-by-nc", "cc-by-nc-sa")


def clean(value):
    """ไฟล์ของกรมประมงมี non-breaking space ปนมาทุกช่อง และเว้นวรรคซ้อนในชื่อวิทยาศาสตร์"""
    text = unicodedata.normalize("NFKC", value or "").replace("\xa0", " ")
    # ไฟล์ต้นทางเขียนสระ "ำ" เป็น นิคหิต + สระอา (U+0E4D U+0E32) แทน สระอำ (U+0E33)
    # บนจอดูเกือบเหมือนกัน แต่เป็นคนละอักขระ ค้นหาไม่เจอ เรียงลำดับผิด และตัดคำผิด
    # เช่น "สัตว์น้ําทั่วไป" ที่ควรเป็น "สัตว์น้ำทั่วไป"  — NFKC ไม่รวมสองตัวนี้ให้
    text = text.replace("ํา", "ำ")
    return re.sub(r"\s+", " ", text).strip()


def binomial(value):
    """ตัดชื่อวิทยาศาสตร์ให้เหลือแค่ genus + species ใช้เป็นกุญแจเชื่อมทุกแหล่ง"""
    match = BINOMIAL.match(clean(value))
    return f"{match.group(1)} {match.group(2)}" if match else None


def fetch(url, label="", timeout=300):
    print(f"  ดึง {label or url} …", flush=True)
    request = urllib.request.Request(url, headers={"User-Agent": UA})
    with urllib.request.urlopen(request, timeout=timeout) as response:
        return response.read()


def cached(name, url, label="", refresh=False):
    """เก็บไฟล์ดิบไว้ในเครื่อง — ชุด FishBase รวมกันเกิน 150 MB ไม่ควรดึงซ้ำทุกครั้ง"""
    os.makedirs(CACHE, exist_ok=True)
    path = os.path.join(CACHE, name)
    if refresh or not os.path.exists(path):
        with open(path, "wb") as f:
            f.write(fetch(url, label=label))
    else:
        print(f"  ใช้แคช {name} ({os.path.getsize(path) / 1e6:.1f} MB)")
    return path


def cached_json(name, produce, refresh=False):
    """แคชผลการถามปลายทางที่ต้องยิงหลายสิบรอบ

    การค้นชื่อไทยกับรูปใช้เวลาเป็นนาทีและต้องหน่วงตามที่ปลายทางกำหนด
    ถ้าไม่เก็บไว้ ทุกครั้งที่แก้โค้ดส่วนประกอบข้อมูลก็ต้องยิงใหม่ทั้งชุด
    ซึ่งช้าสำหรับเราและไม่สุภาพกับเซิร์ฟเวอร์ที่เขาเปิดให้ใช้ฟรี
    """
    os.makedirs(CACHE, exist_ok=True)
    path = os.path.join(CACHE, name)
    if not refresh and os.path.exists(path):
        with io.open(path, encoding="utf-8") as f:
            data = json.load(f)
        print(f"  ใช้แคช {name} ({len(data)} รายการ)")
        return data
    data = produce()
    with io.open(path, "w", encoding="utf-8", newline="\n") as f:
        json.dump(data, f, ensure_ascii=False)
    return data


def read_csv(path, encoding="utf-8"):
    with io.open(path, encoding=encoding, errors="replace", newline="") as f:
        return list(csv.DictReader(f))


def api_get(endpoint, params, timeout=45, attempts=5):
    """เรียก API แบบ JSON พร้อมถอยแล้วลองใหม่

    วิกิพีเดียตอบ 429 เมื่อยิงถี่เกินไป และงานนี้ต้องยิงราวสามสิบรอบติดกัน
    ถ้าไม่ถอยให้เป็น การสร้างจะล้มกลางคัน แล้วรอบหน้าก็ล้มซ้ำที่เดิม
    เคารพ Retry-After ถ้าปลายทางบอกมา ไม่งั้นถอยเป็นเท่าตัว
    """
    url = endpoint + "?" + urllib.parse.urlencode(params)
    delay = 2.0
    for attempt in range(1, attempts + 1):
        request = urllib.request.Request(url, headers={"User-Agent": UA})
        try:
            with urllib.request.urlopen(request, timeout=timeout) as response:
                return json.loads(response.read().decode("utf-8"))
        except urllib.error.HTTPError as error:
            if error.code not in (429, 503) or attempt == attempts:
                raise
            wait = float(error.headers.get("Retry-After") or 0) or delay
            print(f"    ปลายทางขอให้ช้าลง ({error.code}) — รอ {wait:.0f} วิ "
                  f"แล้วลองใหม่ครั้งที่ {attempt + 1}", file=sys.stderr, flush=True)
            time.sleep(wait)
            delay *= 2


# --------------------------------------------------------------- FishBase

def flag(row, key):
    return (row.get(key) or "").strip() == "1"


def value(row, key):
    """คืน None ให้ค่าที่ FishBase ใช้แทน 'ไม่มีข้อมูล' — ปล่อยว่างดีกว่าเติมศูนย์"""
    raw = (row.get(key) or "").strip()
    return None if raw in ("", "NA", "NULL", "-9999") else raw


def number(row, key):
    raw = value(row, key)
    if raw is None:
        return None
    try:
        return float(raw)
    except ValueError:
        return None


WATER_LABEL = {
    (1, 0, 0): "น้ำจืด",
    (0, 1, 0): "น้ำกร่อย",
    (0, 0, 1): "น้ำเค็ม",
    (1, 1, 0): "น้ำจืด–กร่อย",
    (0, 1, 1): "น้ำกร่อย–เค็ม",
    (1, 0, 1): "น้ำจืด–เค็ม",
    (1, 1, 1): "ทุกสภาพน้ำ",
}


def water_of(row):
    key = (int(flag(row, "Fresh")), int(flag(row, "Brack")), int(flag(row, "Saltwater")))
    return key, WATER_LABEL.get(key)


def load_fishbase(refresh):
    print("FishBase " + FISHBASE_VERSION)
    paths = {
        table: cached(f"{table}.csv", f"{FISHBASE_BASE}/{table}.csv",
                      label=f"FishBase {table}", refresh=refresh)
        for table in FISHBASE_TABLES
    }

    species = {row["SpecCode"]: row for row in read_csv(paths["species"])}
    estimate = {row["SpecCode"]: row for row in read_csv(paths["estimate"])}
    swimming = {row["SpecCode"]: row for row in read_csv(paths["swimming"])}

    in_thailand = {
        row["SpecCode"]
        for row in read_csv(paths["country"])
        if (row.get("C_Code") or "").strip().lstrip("0") == THAILAND_C_CODE
        and (row.get("Status") or "").strip() in THAILAND_STATUS
    }

    catchable = []
    for code in sorted(in_thailand, key=lambda c: int(c) if c.isdigit() else 0):
        row = species.get(code)
        if row and (flag(row, "GameFish") or flag(row, "MHooksLines")):
            catchable.append(row)

    print(f"  ปลาไทย {len(in_thailand)} ชนิด → ตกได้ด้วยเบ็ด {len(catchable)} ชนิด")

    # ชื่อไทยที่เขียนด้วยอักษรไทยจริงเท่านั้น
    # FishBase เก็บคำทับศัพท์อักษรโรมันไว้ในภาษา Thai ด้วย เช่น "Pla ai ba", "Chalarm Gob"
    # ซึ่งถอดกลับเป็นอักษรไทยไม่ได้โดยไม่เดา จึงไม่เอามาเป็นชื่อ
    wanted = {row["SpecCode"] for row in catchable}
    thai_names = {}
    with io.open(paths["comnames"], encoding="utf-8", errors="replace", newline="") as f:
        for row in csv.DictReader(f):
            if (row.get("Language") or "").strip() != "Thai" or row["SpecCode"] not in wanted:
                continue
            for field in ("ComName", "UnicodeText"):
                text = clean(row.get(field))
                if text and THAI_CHARS.search(text):
                    thai_names.setdefault(row["SpecCode"], []).append(text)

    # รายชื่อสกุลและชื่อชนิดทั้งฐาน ใช้ตรวจว่าชื่อไฟล์รูปพูดถึงปลาชนิดไหน
    genera = {row["Genus"] for row in species.values() if row.get("Genus")}
    binomials = {f"{row['Genus']} {row['Species']}" for row in species.values()
                 if row.get("Genus") and row.get("Species")}

    return catchable, estimate, swimming, thai_names, genera, binomials


# ------------------------------------------------------------- กรมประมง

def load_dof(refresh):
    """บัญชีสัตว์น้ำของกรมประมง — ให้ชื่อไทย ชื่อท้องถิ่น และสถานภาพ

    เป็นบัญชีสัตว์น้ำหายาก 162 ชนิด ไม่ใช่บัญชีชื่อทั้งหมด และเอียงไปทางน้ำจืด
    ตรงกับปลาของเราแค่ราว 31 ชนิด แต่เป็นแหล่งเดียวที่ให้ชื่อท้องถิ่นได้เลย
    """
    path = cached("dof_fishbase.csv", DOF_NAMES_URL, label="กรมประมง — ชื่อสัตว์น้ำ", refresh=refresh)
    rows = read_csv(path, encoding="utf-8-sig")
    table = {}
    for row in rows:
        key = binomial(row.get("ชื่อวิทยาศาสตร์"))
        if not key:
            continue
        local = clean(row.get("ชื่อท้องถิ่น"))
        table[key] = {
            "thai": clean(row.get("ชื่อไทย")) or None,
            # ในไฟล์ต้นทางใช้ "-" แทนช่องว่าง ต้องไม่ปล่อยให้กลายเป็นชื่อจริง
            "local": [p for p in re.split(r"[,、]| +(?=[฀-๿])", local)
                      if p.strip() and p.strip() != "-"] if local and local != "-" else [],
            "status": clean(row.get("สถานะภาพ")) or None,
            "family": clean(row.get("ชื่อวงศ์สัตว์น้ำ")) or None,
        }
    print(f"  กรมประมง: {len(rows)} แถว → แยกชื่อวิทยาศาสตร์ได้ {len(table)} ชนิด")
    return table


# ------------------------------------------------- วิกิพีเดีย: ชื่อไทย + รูป

def wiki_batches(titles, size=50):
    for i in range(0, len(titles), size):
        yield titles[i:i + size]


def resolve(payload, titles):
    """วิกิพีเดียเปลี่ยนชื่อเรื่องสองชั้น — normalize แล้วตามด้วย redirect
    ชื่อวิทยาศาสตร์ส่วนใหญ่เป็นหน้า redirect ไปหน้าชื่อสามัญ เช่น Lates calcarifer → Barramundi
    ถ้าไม่ตามให้ครบทั้งสองชั้นจะจับคู่ผลลัพธ์กลับไม่ได้เลย
    """
    query = payload.get("query", {})
    normalized = {x["from"]: x["to"] for x in query.get("normalized", [])}
    redirects = {x["from"]: x["to"] for x in query.get("redirects", [])}
    return {title: redirects.get(normalized.get(title, title), normalized.get(title, title))
            for title in titles}


def load_wiki_thai(names):
    print("วิกิพีเดียภาษาไทย — ชื่อไทยผ่านลิงก์ข้ามภาษา")
    found = {}
    for chunk in wiki_batches(names):
        payload = api_get(WIKI_API, {
            "action": "query", "format": "json", "redirects": "1",
            "prop": "langlinks", "lllang": "th", "lllimit": "500",
            "titles": "|".join(chunk),
        })
        target = {page["title"]: page["langlinks"][0]["*"]
                  for page in payload.get("query", {}).get("pages", {}).values()
                  if page.get("langlinks")}
        for name, title in resolve(payload, chunk).items():
            if target.get(title):
                found[name] = clean(target[title])
        time.sleep(WIKI_PAUSE)
    print(f"  ได้ชื่อไทย {len(found)}/{len(names)}")
    return found


def load_wiki_images(names):
    print("วิกิพีเดีย + Commons — รูปและสัญญาอนุญาตรายภาพ")
    lead, file_of = {}, {}
    for chunk in wiki_batches(names):
        payload = api_get(WIKI_API, {
            "action": "query", "format": "json", "redirects": "1",
            "prop": "pageimages", "piprop": "original|thumbnail|name",
            "pithumbsize": "640", "pilimit": "50", "titles": "|".join(chunk),
        })
        pages = {p["title"]: p for p in payload.get("query", {}).get("pages", {}).values()
                 if int(p.get("pageid", -1) or -1) > 0}
        for name, title in resolve(payload, chunk).items():
            page = pages.get(title)
            if not page or not page.get("pageimage"):
                continue
            url = (page.get("original") or {}).get("source") or (page.get("thumbnail") or {}).get("source")
            if url:
                lead[name] = url
                # pageimage คืนชื่อไฟล์แบบมีขีดล่าง แต่ Commons คืนหัวเรื่องแบบเว้นวรรค
                # ถ้าไม่แปลงให้ตรงกันตั้งแต่ตรงนี้ การจับคู่สัญญาอนุญาตจะพลาดเกือบหมด
                # เหลือรอดแค่ไฟล์ที่ชื่อไม่มีขีดล่าง ซึ่งดูเผิน ๆ เหมือน "ส่วนใหญ่ไม่มีสัญญาอนุญาต"
                file_of[name] = "File:" + page["pageimage"].replace("_", " ")
        time.sleep(WIKI_PAUSE)
    print(f"  พบรูปนำ {len(lead)} ชนิด — กำลังตรวจสัญญาอนุญาตทีละไฟล์")

    # ต้องถาม Commons ทีละไฟล์จริง ๆ ห้ามเหมาว่า "อยู่บนวิกิพีเดีย = ใช้ได้"
    # เพราะ en.wikipedia เก็บไฟล์ fair-use ไว้ในเครื่องตัวเองด้วย ซึ่งเอามาใช้ไม่ได้
    meta = {}
    files = sorted(set(file_of.values()))
    for chunk in wiki_batches(files, 40):
        payload = api_get(COMMONS_API, {
            "action": "query", "format": "json", "prop": "imageinfo",
            "iiprop": "extmetadata|url",
            "iiextmetadatafilter": "LicenseShortName|License|Artist|Credit",
            "titles": "|".join(chunk),
        })
        for page in payload.get("query", {}).get("pages", {}).values():
            if "imageinfo" not in page:
                continue
            extra = page["imageinfo"][0].get("extmetadata", {})
            meta[page["title"].replace("_", " ")] = {
                "license": (extra.get("LicenseShortName", {}).get("value")
                            or extra.get("License", {}).get("value")),
                "artist": strip_html(extra.get("Artist", {}).get("value")),
            }
        time.sleep(WIKI_PAUSE)

    images = {}
    rejected = 0
    for name, filename in file_of.items():
        info = meta.get(filename)
        if not info or not info.get("license"):
            rejected += 1
            continue
        images[name] = {
            "url": lead[name],
            "source": "wikimedia",
            "file": filename,
            "license": info["license"],
            "credit": info.get("artist"),
        }
    print(f"  ใช้ได้ {len(images)} ชนิด · ตัดออกเพราะไม่มีสัญญาอนุญาตบน Commons {rejected}")

    # ด่านจับ "จับคู่พลาด" ให้ต่างจาก "ไม่มีสัญญาอนุญาตจริง"
    # ไฟล์บนวิกิพีเดียเกือบทั้งหมดอยู่บน Commons และมีสัญญาอนุญาตเสรี
    # ถ้าจู่ ๆ ตกไปเกินครึ่ง แปลว่ากุญแจที่ใช้จับคู่เพี้ยน ไม่ใช่ความจริงเรื่องลิขสิทธิ์
    # เคยพลาดมาแล้วจากเรื่องขีดล่างกับเว้นวรรคในชื่อไฟล์ ซึ่งเงียบมากถ้าไม่มีด่านนี้
    if lead and len(images) < len(lead) * 0.5:
        raise SystemExit(
            f"จับคู่สัญญาอนุญาตได้แค่ {len(images)} จาก {len(lead)} รูป — "
            "น่าจะเป็นบั๊กการจับคู่ ไม่ใช่เรื่องลิขสิทธิ์ ตรวจก่อนสร้างต่อ")
    return images


# ชื่อไฟล์ที่บอกว่าไม่ใช่รูปตัวปลา — ต้องมีขอบเขตคำทั้งสองด้าน
# ไม่งั้น "Diagramma" (ชื่อสกุลปลา) จะโดนคำว่า diagram
# และ "Orange-spotted" จะโดนคำว่า range
NOT_A_SPECIMEN = re.compile(
    r"distmap|distribution|\bmap\b|\brange\b|\bsize\b|\bdiagram\b|\bskeleton\b|\bjaw\b|\bstamp\b",
    re.IGNORECASE)
FILENAME_BINOMIAL = re.compile(r"\b([A-Z][a-z]{3,})[ _]+([a-z]{3,})\b")


def drop_wrong_images(images, genera, binomials):
    """ตัดรูปที่ชื่อไฟล์บอกว่าเป็นปลาคนละชนิด หรือไม่ใช่รูปตัวปลา

    วิกิพีเดียเปลี่ยนเส้นทางชื่อวิทยาศาสตร์ไปหน้าอื่นได้ และรูปนำของหน้านั้น
    อาจเป็นปลาญาติ ๆ หรือเป็นแผนที่การกระจายตัว ในระเบียนที่คนใช้ดูเพื่อจำแนกชนิด
    การโชว์ปลาผิดตัวแย่กว่าการไม่มีรูป

    ตัดเฉพาะเมื่อ "รู้แน่" คือชื่อไฟล์มีชื่อวิทยาศาสตร์ที่มีอยู่จริงใน FishBase
    และไม่ใช่ชนิดนี้ ถ้าชื่อไฟล์เป็นชื่อสามัญล้วนก็ปล่อยผ่าน เพราะเดาไม่ได้
    รูปจาก iNaturalist ไม่ต้องตรวจ เพราะจับคู่ด้วยชื่อวิทยาศาสตร์เป๊ะมาแล้ว
    """
    dropped = {"ชนิดอื่น": [], "ไม่ใช่รูปปลา": []}
    for name in list(images):
        image = images[name]
        if image.get("source") != "wikimedia":
            continue
        filename = (image.get("file") or "").replace("File:", "").replace("_", " ")
        if NOT_A_SPECIMEN.search(filename):
            dropped["ไม่ใช่รูปปลา"].append((name, filename))
            del images[name]
            continue
        named = {f"{m.group(1)} {m.group(2)}" for m in FILENAME_BINOMIAL.finditer(filename)
                 if m.group(1) in genera and f"{m.group(1)} {m.group(2)}" in binomials}
        if named and name not in named:
            dropped["ชนิดอื่น"].append((name, filename))
            del images[name]

    for reason, items in dropped.items():
        print(f"  ตัดรูป{reason} {len(items)} ภาพ")
        for name, filename in items:
            print(f"    {name} ← {filename[:56]}")
    return images


def load_commons_search(names, refresh=False):
    """ค้นรูปจาก Commons ตรง ๆ สำหรับชนิดที่ไม่มีบทความวิกิพีเดีย

    หลายชนิดไม่มีหน้าวิกิพีเดียของตัวเอง แต่มีภาพวาดทางวิทยาศาสตร์เก่าอยู่บน Commons
    ซึ่งเป็นสาธารณสมบัติ ภาพวาดที่เห็นลักษณะเด่นชัดใช้จำแนกชนิดได้ดีพอ ๆ กับภาพถ่าย

    ผลจากตรงนี้ยังต้องผ่านด่านตรวจชนิดเหมือนกัน เพราะการค้นด้วยชื่ออาจคืนภาพของ
    ปลาที่ถูกกล่าวถึงในคำบรรยายไฟล์ ไม่ใช่ปลาในภาพ
    """
    os.makedirs(CACHE, exist_ok=True)
    path = os.path.join(CACHE, "commons-search.json")
    known = {}
    if not refresh and os.path.exists(path):
        with io.open(path, encoding="utf-8") as f:
            known = json.load(f)

    todo = [n for n in names if n not in known]
    print(f"Wikimedia Commons — ค้นตรงอีก {len(names)} ชนิด "
          f"(มีในแคชแล้ว {len(names) - len(todo)} · ต้องถามใหม่ {len(todo)})")
    for i, name in enumerate(todo, 1):
        known[name] = None
        payload = api_get(COMMONS_API, {
            "action": "query", "format": "json", "generator": "search",
            "gsrsearch": f'file: "{name}"', "gsrnamespace": "6", "gsrlimit": "4",
            "prop": "imageinfo", "iiprop": "url|extmetadata",
            "iiextmetadatafilter": "LicenseShortName|Artist", "iiurlwidth": "640",
        })
        for page in (payload.get("query", {}).get("pages") or {}).values():
            info = (page.get("imageinfo") or [{}])[0]
            extra = info.get("extmetadata", {})
            license_name = extra.get("LicenseShortName", {}).get("value")
            if not license_name or not info.get("thumburl"):
                continue
            known[name] = {
                "url": info["thumburl"],
                "source": "wikimedia",
                "file": page["title"].replace("_", " "),
                "license": license_name,
                "credit": strip_html(extra.get("Artist", {}).get("value")),
            }
            break
        if i % 10 == 0:
            print(f"    …{i}/{len(todo)}", flush=True)
        time.sleep(1.2)

    if todo:
        with io.open(path, "w", encoding="utf-8", newline="\n") as f:
            json.dump(known, f, ensure_ascii=False)

    found = {name: known[name] for name in names if known.get(name)}
    print(f"  เจอ {len(found)} ชนิด")
    return found


def strip_html(value):
    if not value:
        return None
    text = re.sub(r"<[^>]+>", " ", value)
    return clean(text) or None


def load_inat_images(names, refresh=False):
    """เติมช่องว่างจาก iNaturalist — ทีละชนิด เพราะ API ไม่รับค้นเป็นชุด

    ต้องกรองสัญญาอนุญาตเข้ม: ภาพจำนวนไม่น้อยคืน license เป็น null
    ซึ่งแปลว่าสงวนลิขสิทธิ์เต็ม ไม่ใช่ "ไม่ระบุ"

    แคชรายชนิด ไม่ใช่แคชทั้งก้อน เพราะรายชื่อที่ต้องเติมเปลี่ยนได้ทุกครั้งที่
    ด่านตัดรูปทำงาน ถ้าแคชทั้งก้อนแล้วชนิดใหม่โผล่มา มันจะไม่ถูกค้นเลยเงียบ ๆ
    เก็บผลว่างไว้ด้วย (null) จะได้ไม่ถามซ้ำทุกรอบสำหรับชนิดที่ไม่มีรูปให้ใช้
    """
    os.makedirs(CACHE, exist_ok=True)
    path = os.path.join(CACHE, "inat-images.json")
    known = {}
    if not refresh and os.path.exists(path):
        with io.open(path, encoding="utf-8") as f:
            known = json.load(f)

    todo = [n for n in names if n not in known]
    print(f"iNaturalist — เติมช่องว่าง {len(names)} ชนิด "
          f"(มีในแคชแล้ว {len(names) - len(todo)} · ต้องถามใหม่ {len(todo)})")
    images = {}
    rejected = {}
    for i, name in enumerate(todo, 1):
        try:
            payload = api_get(INAT_API, {"q": name, "rank": "species", "per_page": "1"}, timeout=25)
        except Exception as error:                      # noqa: BLE001 - ข้ามชนิดนี้พอ
            print(f"    ข้าม {name}: {type(error).__name__}", file=sys.stderr)
            time.sleep(1.05)
            continue
        results = payload.get("results") or []
        known[name] = None
        # ต้องตรงชื่อเป๊ะ ไม่งั้นจะได้รูปปลาคนละชนิดมาแปะ
        if results and (results[0].get("name") or "").lower() == name.lower():
            photo = results[0].get("default_photo") or {}
            code = (photo.get("license_code") or "").lower()
            if photo.get("medium_url"):
                if code in INAT_ALLOWED:
                    known[name] = {
                        "url": photo["medium_url"],
                        "source": "inaturalist",
                        "license": code,
                        "credit": clean(photo.get("attribution")),
                    }
                else:
                    rejected[code or "สงวนลิขสิทธิ์เต็ม"] = rejected.get(code or "สงวนลิขสิทธิ์เต็ม", 0) + 1
        if i % 20 == 0:
            print(f"    …{i}/{len(todo)}", flush=True)
        time.sleep(1.05)          # iNaturalist ขอไม่เกิน 60 ครั้งต่อนาที

    if todo:
        with io.open(path, "w", encoding="utf-8", newline="\n") as f:
            json.dump(known, f, ensure_ascii=False)

    images = {name: known[name] for name in names if known.get(name)}
    print(f"  ใช้ได้ {len(images)} · ตัดออกเพราะสัญญาอนุญาต {rejected}")
    return images


# ------------------------------------------------------------------ ประกอบ

def pick_thai_name(key, dof, wiki, fishbase_names):
    """ลำดับความน่าเชื่อถือ: กรมประมง > วิกิพีเดียไทย > FishBase

    กรมประมงมาก่อนเพราะเป็นหน่วยงานทางการ และตอนวัดพบว่าสี่ชนิดที่ทั้งสองแหล่งมี
    ชื่อไม่ตรงกัน เช่น Epinephelus fuscoguttatus วิกิว่า "ปลาเก๋าเสือ" กรมประมงว่า
    "ปลากะรังเสือ" ทั้งคู่เป็นชื่อที่คนใช้จริง จึงไม่ทิ้งอันไหน แต่ต้องเลือกอันหลัก
    ให้แน่นอนและบอกที่มาไว้ ใครเห็นว่าผิดจะได้รู้ว่าไปแก้ที่ไหน
    """
    candidates = []
    if dof and dof.get("thai"):
        candidates.append((dof["thai"], "กรมประมง"))
    if wiki:
        candidates.append((wiki, "วิกิพีเดียไทย"))
    for name in fishbase_names or []:
        candidates.append((name, "FishBase"))

    if not candidates:
        return None, None, []
    primary, source = candidates[0]
    seen = {primary}
    alternates = []
    for name, origin in candidates[1:]:
        if name not in seen:
            seen.add(name)
            alternates.append({"name": name, "source": origin})
    return primary, source, alternates


def build_records(catchable, estimate, swimming, fb_thai, dof, wiki_thai, images):
    records = []
    for row in catchable:
        code = row["SpecCode"]
        key = f"{row['Genus']} {row['Species']}".strip()
        est = estimate.get(code, {})
        dof_row = dof.get(key)

        thai, thai_source, alternates = pick_thai_name(
            key, dof_row, wiki_thai.get(key), fb_thai.get(code))

        water_key, water_label = water_of(row)
        # ต้องมีการกระจายตัวในทะเลจริง ไม่ใช่แค่แตะน้ำกร่อย
        # FishBase คำนวณอุณหภูมิที่ชอบจากแบบจำลองทะเล กลุ่มน้ำจืด–กร่อย 27 ชนิด
        # จึงไม่มีค่านี้เลย ถ้านับรวมเข้ามาจะเห็นเป็น "ข้อมูลขาด" ทั้งที่มันไม่เคยมี
        marine = bool(water_key[2])

        record = {
            "spec_code": code,
            "scientific_name": key,
            "family_code": value(row, "FamCode"),
            "name_th": thai,
            "name_th_source": thai_source,
            "names_alt": alternates,
            "names_local": (dof_row or {}).get("local") or [],
            "name_en": value(row, "FBname"),

            "water": water_label,
            "water_fresh": bool(water_key[0]),
            "water_brackish": bool(water_key[1]),
            "water_salt": bool(water_key[2]),

            "habitat": value(row, "DemersPelag"),
            "body_shape": value(row, "BodyShapeI"),
            "migration": value(row, "AnaCat"),
            "depth_min_m": number(row, "DepthRangeShallow"),
            "depth_max_m": number(row, "DepthRangeDeep"),
            "length_max_cm": number(row, "Length"),
            "length_common_cm": number(row, "CommonLength"),
            "weight_max_g": number(row, "Weight"),
            "trophic_level": number(est, "Troph"),

            # อุณหภูมิที่ชอบ มีเฉพาะปลาที่แตะน้ำเค็ม ดูหมายเหตุหัวไฟล์
            "temp_pref_min_c": number(est, "TempPrefMin") if marine else None,
            "temp_pref_mean_c": number(est, "TempPrefMean") if marine else None,
            "temp_pref_max_c": number(est, "TempPrefMax") if marine else None,

            "vulnerability": number(row, "Vulnerability"),
            "vulnerability_climate": number(row, "VulnerabilityClimate"),
            "is_gamefish": flag(row, "GameFish"),
            "caught_by_hook": flag(row, "MHooksLines"),
            "dangerous": value(row, "Dangerous"),
            "price_category": value(row, "PriceCateg"),

            # อัตราส่วนหางปลาสัมพันธ์กับความเร็วว่ายน้ำ (Pauly 1989, Sambilay 1990)
            # แต่ FishBase มีค่านี้แค่ 145 ชนิดทั่วโลก ตรงกับปลาไทยของเรา 17 ชนิด
            # เก็บไว้เฉย ๆ ใช้เป็นฐานคำนวณอะไรไม่ได้ ความครอบคลุมต่ำเกินไป
            "caudal_aspect_ratio": number(swimming.get(code, {}), "AspectRatio"),

            "status_dof": (dof_row or {}).get("status"),
            "image": images.get(key),
        }
        records.append(record)
    return records


def summarise(records):
    buckets = {}
    for record in records:
        label = record["water"] or "ไม่ระบุ"
        buckets[label] = buckets.get(label, 0) + 1

    marine = [r for r in records if r["water_salt"]]
    counted = {
        "species": len(records),
        "thai_name": sum(1 for r in records if r["name_th"]),
        "local_name": sum(1 for r in records if r["names_local"]),
        "image": sum(1 for r in records if r["image"]),
        "status_dof": sum(1 for r in records if r["status_dof"]),
        "temp_pref_marine": (sum(1 for r in marine if r["temp_pref_mean_c"] is not None) / len(marine)
                             if marine else 0.0),
    }

    print("\nสรุป")
    for label, count in sorted(buckets.items(), key=lambda x: -x[1]):
        print(f"  {label:16} {count:5}")
    print(f"  {'— ชื่อไทย':16} {counted['thai_name']:5}"
          f"  ({100 * counted['thai_name'] / len(records):.1f}%)")
    print(f"  {'— ชื่อท้องถิ่น':16} {counted['local_name']:5}")
    print(f"  {'— รูป':16} {counted['image']:5}"
          f"  ({100 * counted['image'] / len(records):.1f}%)")
    print(f"  {'— สถานภาพ':16} {counted['status_dof']:5}")
    print(f"  {'— อุณหภูมิ(ทะเล)':16} {100 * counted['temp_pref_marine']:5.1f}%")

    failures = [f"{name}: ได้ {got} ต่ำกว่าเกณฑ์ {FLOORS[name]}"
                for name, got in counted.items()
                if name in FLOORS and got < FLOORS[name]]
    if failures:
        raise SystemExit("ความครอบคลุมตกจากที่วัดไว้ — ไม่เขียนทับไฟล์เดิม\n  " + "\n  ".join(failures))
    return buckets, counted


def write_json(path, payload):
    full = os.path.join(REPO, path)
    os.makedirs(os.path.dirname(full), exist_ok=True)
    with io.open(full, "w", encoding="utf-8", newline="\n") as f:
        json.dump(payload, f, ensure_ascii=False, separators=(",", ":"))
    print(f"wrote {path}  ({os.path.getsize(full) / 1024:.1f} KB)")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--refresh", action="store_true", help="ทิ้งแคชแล้วดึงใหม่ทั้งหมด")
    args = parser.parse_args()

    print("สร้างระเบียนปลาไทยที่ตกได้")
    catchable, estimate, swimming, fb_thai, genera, binomials = load_fishbase(args.refresh)
    if not catchable:
        raise SystemExit("ไม่ได้ปลาสักชนิด — ตรวจแหล่งข้อมูลก่อนเขียนทับไฟล์เดิม")

    dof = load_dof(args.refresh)
    names = [f"{row['Genus']} {row['Species']}".strip() for row in catchable]

    wiki_thai = cached_json("wiki-thai.json", lambda: load_wiki_thai(names), args.refresh)
    images = cached_json("wiki-images.json", lambda: load_wiki_images(names), args.refresh)
    images = drop_wrong_images(images, genera, binomials)
    missing = [name for name in names if name not in images]
    images.update(load_inat_images(missing, args.refresh))

    # ยังเหลือชนิดที่ไม่มีทั้งบทความวิกิพีเดียและรูปบน iNaturalist
    # ค้น Commons ตรง ๆ เป็นด่านสุดท้าย แล้วให้ผ่านด่านตรวจชนิดอีกรอบ
    still_missing = [name for name in names if name not in images]
    images.update(load_commons_search(still_missing, args.refresh))
    images = drop_wrong_images(images, genera, binomials)

    records = build_records(catchable, estimate, swimming, fb_thai, dof, wiki_thai, images)
    buckets, counted = summarise(records)

    write_json("data/species-south.json", {
        "metadata": {
            "scope": "ปลาที่พบในไทยตาม FishBase (native/endemic/introduced) "
                     "และเป็นปลาเกมหรือจับได้ด้วยเบ็ดและสาย",
            "generated_by": "scripts/build-species.py",
            "counts": {"total": len(records), "by_water": buckets},
            "sources": [
                {"name": f"FishBase {FISHBASE_VERSION}", "license": FISHBASE_LICENSE,
                 "url": FISHBASE_BASE,
                 "provides": "รายชื่อชนิด สภาพน้ำ ถิ่นอาศัย ความลึก ขนาด อุณหภูมิที่ชอบ "
                             "ระดับห่วงโซ่อาหาร ธงปลาเกม ธงอันตราย"},
                {"name": "กรมประมง — ข้อมูลสัตว์น้ำของไทย", "license": DOF_LICENSE,
                 "url": DOF_NAMES_URL,
                 "provides": "ชื่อไทย ชื่อท้องถิ่น สถานภาพการอนุรักษ์"},
                {"name": "วิกิพีเดียภาษาไทย", "license": "CC BY-SA 4.0",
                 "url": "https://th.wikipedia.org/",
                 "provides": "ชื่อไทยผ่านลิงก์ข้ามภาษา"},
                {"name": "Wikimedia Commons", "license": "ดูรายภาพในช่อง image.license",
                 "url": "https://commons.wikimedia.org/",
                 "provides": "รูปภาพ"},
                {"name": "iNaturalist", "license": "ดูรายภาพในช่อง image.license",
                 "url": "https://www.inaturalist.org/",
                 "provides": "รูปภาพเติมช่องว่าง"},
            ],
            "caveats": [
                "อุณหภูมิที่ปลาชอบมีเฉพาะชนิดที่แตะน้ำเค็ม ปลาน้ำจืดไม่มีค่านี้ทั้งกลุ่ม "
                "เพราะ FishBase คำนวณจากแบบจำลองการกระจายตัวในทะเล",
                "ชื่อไทยได้จากแหล่งเปิดเพียงบางส่วน ชนิดที่ไม่มีชื่อไทยให้แสดงชื่อสามัญแทน "
                "ห้ามถอดคำทับศัพท์อักษรโรมันกลับเป็นอักษรไทยเอง",
                "สถานภาพจากกรมประมงอ้าง พ.ร.บ. สงวนและคุ้มครองสัตว์ป่า พ.ศ. 2535 "
                "ซึ่งถูกแทนที่ด้วยฉบับ พ.ศ. 2562 แล้ว ห้ามใช้ตอบว่าจับได้หรือจับไม่ได้",
                "อัตราส่วนหางปลาครอบคลุมน้อยเกินกว่าจะใช้คำนวณอะไร เก็บไว้อ้างอิงเท่านั้น",
                "ระเบียนนี้ใช้วางแผนตกปลา ไม่ใช่คู่มือจำแนกชนิดทางอนุกรมวิธาน",
            ],
        },
        "species": records,
    })


if __name__ == "__main__":
    main()
