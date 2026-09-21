"""
XPLENDOR — OCR de faturas: utilitário de CONVERSÃO PDF → imagem (Fase A).

Invocado pelo Laravel (worker, que tem o docker socket) por docker exec:

    docker exec -i xplendor-scraper python /scraper/sources/ocr/run.py

STDIN (JSON): {"mode": "pdf_to_image", "pdf_base64": "<base64 do PDF>"}
STDOUT (JSON): {"ok": true, "image_base64": "<PNG base64 da 1.ª página>"}
             ou {"ok": false, "error": "..."}

Só a 1.ª página é convertida (a fatura costuma caber; multipágina é fase futura).
A chamada à IA (OpenAI visão) é feita em PHP (motor do XFIN reaproveitado). Este
módulo só faz o que o PHP não consegue sem poppler: rasterizar um PDF-scan.
"""
import base64
import json
import sys


def pdf_to_image(pdf_bytes: bytes) -> bytes:
    # Import tardio: só é preciso quando há um PDF (mantém o resto leve).
    from pdf2image import convert_from_bytes  # requer poppler-utils no sistema

    images = convert_from_bytes(pdf_bytes, dpi=200, first_page=1, last_page=1, fmt="png")
    if not images:
        raise RuntimeError("PDF sem páginas legíveis.")

    import io
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
    if mode != "pdf_to_image":
        print(json.dumps({"ok": False, "error": f"modo desconhecido: {mode}"}))
        return 2

    try:
        pdf_b64 = cfg.get("pdf_base64") or ""
        if not pdf_b64:
            raise RuntimeError("pdf_base64 em falta.")
        png = pdf_to_image(base64.b64decode(pdf_b64))
        print(json.dumps({"ok": True, "image_base64": base64.b64encode(png).decode()}))
        return 0
    except Exception as exc:  # noqa: BLE001
        print(json.dumps({"ok": False, "error": f"{type(exc).__name__}: {exc}"}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
