"""
XPLENDOR — OCR de faturas: utilitário de LEITURA LOCAL do ficheiro (sem IA).

Invocado pelo Laravel (worker, que tem o docker socket) por docker exec:

    docker exec -i xplendor-scraper python /scraper/sources/ocr/run.py

Modos (STDIN JSON → STDOUT JSON):

  analyze  {"mode": "analyze", "file_base64": "...", "mime": "application/pdf",
            "max_pages": 10, "text_min_chars": 200, "images": "auto"|"always"|"never"}
     → {"ok": true, "kind": "pdf"|"image", "pages": N, "qr": {"raw", "page", "dpi"}|null,
        "text": "...", "text_chars": n, "copies_dropped": [páginas], "kept_pages": [...],
        "images": [{"page", "mime", "base64"}], "truncated": bool}

     • QR: zxing-cpp em TODAS as páginas (até max_pages) a 200 DPI; se nenhuma tiver
       QR da AT, repete a 300 DPI. Imagens são lidas diretamente (e ampliadas 1,5× se falhar).
     • Texto: pdftotext -layout por página; as vias repetidas (DUPLICADO/TRIPLICADO…)
       depois da 1.ª via são descartadas. text_chars = nº de carateres alfanuméricos úteis.
     • Imagens p/ a IA (só PDF): todas as páginas mantidas a 200 DPI (JPEG), até max_pages.
       "auto" = só se o texto não chegar (text_chars < text_min_chars).

  pdf_to_image (legado) {"mode": "pdf_to_image", "pdf_base64": "..."} → 1.ª página PNG.

A chamada à IA é feita em PHP. Este módulo só faz o que o PHP não consegue sem
poppler/zxing: rasterizar, ler o QR e extrair a camada de texto.
"""
import base64
import io
import json
import re
import subprocess
import sys
import tempfile

QR_DPI = 200
QR_FALLBACK_DPI = 300
IMAGE_DPI = 200
JPEG_QUALITY = 85
DEFAULT_MAX_PAGES = 10
DEFAULT_TEXT_MIN_CHARS = 200
# Marca de via no topo da página (as primeiras linhas do texto).
COPY_RE = re.compile(r"\b(ORIGINAL|DUPLICADO|TRIPLICADO|QUADRUPLICADO)\b", re.IGNORECASE)
COPY_HEAD_LINES = 40
# QR da AT: começa por "A:<NIF>*B:" (Portaria 195/2020).
AT_QR_RE = re.compile(r"^A:[^*]*\*B:")


# ─────────────────────────────────────────────────────────────── PDF (poppler)

def pdf_page_count(pdf_bytes: bytes) -> int:
    from pdf2image import pdfinfo_from_bytes

    return int(pdfinfo_from_bytes(pdf_bytes).get("Pages", 0))


def render_pages(pdf_bytes: bytes, dpi: int, last_page: int):
    """Páginas 1..last_page em PIL (pdftoppm)."""
    from pdf2image import convert_from_bytes

    return convert_from_bytes(pdf_bytes, dpi=dpi, first_page=1, last_page=last_page)


def pdf_text_pages(pdf_bytes: bytes, last_page: int) -> list:
    """Texto por página (pdftotext -layout). Lista vazia se o PDF não tiver camada de texto."""
    with tempfile.NamedTemporaryFile(suffix=".pdf") as tmp:
        tmp.write(pdf_bytes)
        tmp.flush()
        res = subprocess.run(
            ["pdftotext", "-layout", "-enc", "UTF-8", "-f", "1", "-l", str(last_page), tmp.name, "-"],
            capture_output=True, timeout=60,
        )
    if res.returncode != 0:
        return []
    pages = res.stdout.decode("utf-8", errors="replace").split("\f")
    if pages and pages[-1].strip() == "":
        pages = pages[:-1]  # o \f final cria uma "página" vazia

    return pages


def useful_chars(text: str) -> int:
    return sum(1 for ch in text if ch.isalnum())


def copy_marker(page_text: str):
    head = [ln for ln in page_text.splitlines() if ln.strip()][:COPY_HEAD_LINES]
    m = COPY_RE.search("\n".join(head))
    return m.group(1).upper() if m else None


def drop_copies(pages: list) -> tuple:
    """
    Mantém só a 1.ª via. A via da 1.ª página (ex.: ORIGINAL) manda: páginas marcadas
    com OUTRA via (DUPLICADO, TRIPLICADO…) são descartadas; páginas sem marca ficam.
    Devolve (índices mantidos 0-based, páginas descartadas 1-based).
    """
    markers = [copy_marker(p) for p in pages]
    first = next((m for m in markers if m), None)
    kept, dropped = [], []
    for i, m in enumerate(markers):
        if first and m and m != first:
            dropped.append(i + 1)
        else:
            kept.append(i)

    return kept, dropped


# ─────────────────────────────────────────────────────────────── QR (zxing-cpp)

def read_at_qr(img):
    """Texto do 1.º QR da AT na imagem, ou None."""
    import zxingcpp

    for b in zxingcpp.read_barcodes(img, formats=zxingcpp.BarcodeFormat.QRCode):
        text = (b.text or "").strip()
        if AT_QR_RE.match(text):
            return text
    return None


def find_qr_in_pdf(pdf_bytes: bytes, last_page: int, first_render=None):
    """Procura o QR página a página a 200 DPI; se nenhuma tiver, repete a 300 DPI."""
    for dpi, imgs in ((QR_DPI, first_render), (QR_FALLBACK_DPI, None)):
        if imgs is None:
            imgs = render_pages(pdf_bytes, dpi, last_page)
        for i, img in enumerate(imgs):
            raw = read_at_qr(img)
            if raw:
                return {"raw": raw, "page": i + 1, "dpi": dpi}
    return None


def find_qr_in_image(img_bytes: bytes):
    from PIL import Image

    img = Image.open(io.BytesIO(img_bytes))
    img.load()
    raw = read_at_qr(img)
    if raw:
        return {"raw": raw, "page": 1, "dpi": None}
    # Fotografia pequena/desfocada: tenta ampliada (equivalente ao fallback de 300 DPI).
    big = img.resize((int(img.width * 1.5), int(img.height * 1.5)))
    raw = read_at_qr(big)
    return {"raw": raw, "page": 1, "dpi": None, "upscaled": True} if raw else None


def to_jpeg_b64(img) -> str:
    buf = io.BytesIO()
    img.convert("RGB").save(buf, format="JPEG", quality=JPEG_QUALITY)
    return base64.b64encode(buf.getvalue()).decode()


# ─────────────────────────────────────────────────────────────── modos

def analyze(cfg: dict) -> dict:
    data = base64.b64decode(cfg.get("file_base64") or "")
    if not data:
        raise RuntimeError("file_base64 em falta.")
    mime = str(cfg.get("mime") or "").lower()
    max_pages = max(1, int(cfg.get("max_pages") or DEFAULT_MAX_PAGES))
    text_min = int(cfg.get("text_min_chars") or DEFAULT_TEXT_MIN_CHARS)
    want_images = cfg.get("images") or "auto"

    is_pdf = "pdf" in mime or data[:5] == b"%PDF-"
    if not is_pdf:
        return {
            "ok": True, "kind": "image", "pages": 1, "qr": find_qr_in_image(data),
            "text": "", "text_chars": 0, "copies_dropped": [], "kept_pages": [1],
            "images": [], "truncated": False,
        }

    pages = pdf_page_count(data)
    if pages < 1:
        raise RuntimeError("PDF sem páginas legíveis.")
    last = min(pages, max_pages)

    texts = pdf_text_pages(data, last)
    kept, dropped = drop_copies(texts) if texts else (list(range(last)), [])
    text = "\f".join(texts[i] for i in kept) if texts else ""
    chars = useful_chars(text)

    renders = render_pages(data, IMAGE_DPI, last)
    qr = find_qr_in_pdf(data, last, first_render=renders)

    images = []
    if want_images == "always" or (want_images == "auto" and chars < text_min):
        images = [{"page": i + 1, "mime": "image/jpeg", "base64": to_jpeg_b64(renders[i])}
                  for i in kept if i < len(renders)]

    return {
        "ok": True, "kind": "pdf", "pages": pages, "qr": qr,
        "text": text, "text_chars": chars, "copies_dropped": dropped,
        "kept_pages": [i + 1 for i in kept], "images": images, "truncated": pages > max_pages,
    }


def pdf_to_image(pdf_bytes: bytes) -> bytes:
    images = render_pages(pdf_bytes, IMAGE_DPI, 1)
    if not images:
        raise RuntimeError("PDF sem páginas legíveis.")
    buf = io.BytesIO()
    images[0].save(buf, format="PNG")
    return buf.getvalue()


def main() -> int:
    try:
        raw = sys.stdin.read()
        cfg = json.loads(raw) if raw.strip() else {}
    except Exception as exc:  # noqa: BLE001
        print(json.dumps({"ok": False, "error": f"STDIN inválido: {type(exc).__name__}"}))
        return 2

    mode = cfg.get("mode")
    try:
        if mode == "analyze":
            print(json.dumps(analyze(cfg)))
            return 0
        if mode == "pdf_to_image":
            pdf_b64 = cfg.get("pdf_base64") or ""
            if not pdf_b64:
                raise RuntimeError("pdf_base64 em falta.")
            png = pdf_to_image(base64.b64decode(pdf_b64))
            print(json.dumps({"ok": True, "image_base64": base64.b64encode(png).decode()}))
            return 0
        print(json.dumps({"ok": False, "error": f"modo desconhecido: {mode}"}))
        return 2
    except Exception as exc:  # noqa: BLE001
        print(json.dumps({"ok": False, "error": f"{type(exc).__name__}: {exc}"}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
