"""
XPLENDOR — OCR de faturas (F2a): leitura LOCAL do ficheiro, sem IA. Prova com
ficheiros sintéticos (QR gerado pelo próprio zxing-cpp, PDF montado à mão):
  · PDF COM texto: QR lido a 200 DPI, texto útil acima do limiar → sem imagens;
  · vias repetidas: a página DUPLICADO depois da ORIGINAL é descartada;
  · PDF digitalizado (sem camada de texto): QR lido, imagens de TODAS as páginas;
  · imagem (PNG): QR lido diretamente;
  · fallback 300 DPI: sem QR a 200 → lido a 300;
  · máximo de páginas para a IA (truncated) e modo desconhecido.
Requer poppler-utils + zxing-cpp (contentor do scraper).
"""
import base64
import io
import json
import os
import sys
import zlib

import pytest

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

zxingcpp = pytest.importorskip("zxingcpp")
from PIL import Image  # noqa: E402

import sources.ocr.run as ocr  # noqa: E402

QR_RAW = ("A:502811331*B:514148497*C:PT*D:FT*E:N*F:20260504*G:FD M501/1681*H:J6TBYMGN-1681"
          "*I1:PT*I3:1786.85*I4:107.21*I7:153.48*I8:35.30*N:142.51*O:2082.84*Q:YlRC*R:0001")


def qr_image(text=QR_RAW, scale=4) -> Image.Image:
    import numpy as np

    img = zxingcpp.write_barcode_to_image(zxingcpp.create_barcode(text, zxingcpp.BarcodeFormat.QRCode), scale=scale)
    return Image.fromarray(np.asarray(img)).convert("L")


def text_pdf(pages: list, qr_on_page=None) -> bytes:
    """PDF mínimo com camada de texto (Helvetica) e, opcionalmente, o QR numa página."""
    objs = {1: b"<< /Type /Catalog /Pages 2 0 R >>", 3: b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>"}
    kids, nxt = [], 4
    qr = qr_image() if qr_on_page is not None else None
    for pi, lines in enumerate(pages):
        page_id, content_id = nxt, nxt + 1
        nxt += 2
        ops = [b"BT /F1 10 Tf"]
        y = 800
        for ln in lines:
            safe = ln.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)")
            ops.append(f"1 0 0 1 40 {y} Tm ({safe}) Tj".encode("latin-1"))
            y -= 14
        ops.append(b"ET")
        res = b"/Font << /F1 3 0 R >>"
        if qr is not None and pi == qr_on_page:
            img_id = nxt
            nxt += 1
            data = zlib.compress(qr.tobytes())
            objs[img_id] = (f"<< /Type /XObject /Subtype /Image /Width {qr.width} /Height {qr.height} "
                            f"/ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length {len(data)} >>\nstream\n"
                            ).encode() + data + b"\nendstream"
            ops.append(b"q 120 0 0 120 420 60 cm /Im1 Do Q")
            res += f" /XObject << /Im1 {img_id} 0 R >>".encode()
        stream = b"\n".join(ops)
        objs[content_id] = f"<< /Length {len(stream)} >>\nstream\n".encode() + stream + b"\nendstream"
        objs[page_id] = (f"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << ".encode()
                         + res + f" >> /Contents {content_id} 0 R >>".encode())
        kids.append(page_id)
    objs[2] = f"<< /Type /Pages /Kids [{' '.join(f'{k} 0 R' for k in kids)}] /Count {len(kids)} >>".encode()

    out = io.BytesIO()
    out.write(b"%PDF-1.4\n")
    offsets = {}
    for oid in sorted(objs):
        offsets[oid] = out.tell()
        out.write(f"{oid} 0 obj\n".encode() + objs[oid] + b"\nendobj\n")
    xref = out.tell()
    n = max(objs) + 1
    out.write(f"xref\n0 {n}\n0000000000 65535 f \n".encode())
    for oid in range(1, n):
        out.write(f"{offsets.get(oid, 0):010d} 00000 n \n".encode())
    out.write(f"trailer\n<< /Size {n} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n".encode())
    return out.getvalue()


def scanned_pdf(n_pages=2, qr_on_page=0) -> bytes:
    """PDF só com imagens (como um digitalizado): sem camada de texto."""
    pages = []
    for i in range(n_pages):
        page = Image.new("L", (827, 1169), 255)  # A4 a 100 DPI
        if i == qr_on_page:
            page.paste(qr_image(scale=3), (560, 880))
        pages.append(page.convert("RGB"))
    buf = io.BytesIO()
    pages[0].save(buf, format="PDF", resolution=100, save_all=True, append_images=pages[1:])
    return buf.getvalue()


def invoice_lines(copy="ORIGINAL"):
    lines = [f"Fatura FD M501/1681 {copy}", "Artigo  Descricao  Qtd  Un  Preco  IVA  Valor"]
    lines += [f"10{i:02d}  Lombo de porco fatiado lote {i}  2,00  KG  7,15  6  14,30" for i in range(15)]
    return lines


def run_analyze(data: bytes, mime: str, **kw):
    return ocr.analyze({"file_base64": base64.b64encode(data).decode(), "mime": mime, **kw})


def test_pdf_with_text_reads_qr_and_skips_images():
    res = run_analyze(text_pdf([invoice_lines()], qr_on_page=0), "application/pdf", text_min_chars=200)
    assert res["kind"] == "pdf" and res["pages"] == 1
    assert res["qr"]["raw"] == QR_RAW and res["qr"]["page"] == 1 and res["qr"]["dpi"] == 200
    assert res["text_chars"] >= 200 and "Lombo de porco" in res["text"]
    assert res["images"] == []  # texto chega → a IA lê o texto, não imagens


def test_duplicate_copy_pages_are_dropped():
    pdf = text_pdf([invoice_lines("ORIGINAL"), invoice_lines("DUPLICADO"), invoice_lines("TRIPLICADO")], qr_on_page=0)
    res = run_analyze(pdf, "application/pdf")
    assert res["copies_dropped"] == [2, 3]
    assert res["kept_pages"] == [1]
    assert "DUPLICADO" not in res["text"]


def test_multi_page_original_without_marker_on_page_two_is_kept():
    pdf = text_pdf([invoice_lines("ORIGINAL"), ["A transportar 1 214,92", "Transportado 1 214,92"]])
    res = run_analyze(pdf, "application/pdf")
    assert res["kept_pages"] == [1, 2] and res["copies_dropped"] == []
    assert res["qr"] is None


def test_scanned_pdf_reads_qr_and_renders_all_pages():
    res = run_analyze(scanned_pdf(n_pages=2, qr_on_page=1), "application/pdf", text_min_chars=200)
    assert res["text_chars"] == 0
    assert res["qr"]["raw"] == QR_RAW and res["qr"]["page"] == 2
    assert [i["page"] for i in res["images"]] == [1, 2]
    assert all(i["mime"] == "image/jpeg" and i["base64"] for i in res["images"])


def test_max_pages_truncates_images():
    res = run_analyze(scanned_pdf(n_pages=3, qr_on_page=0), "application/pdf", max_pages=2)
    assert res["pages"] == 3 and res["truncated"] is True
    assert len(res["images"]) == 2


def test_image_reads_qr_directly():
    page = Image.new("L", (900, 1200), 255)
    page.paste(qr_image(scale=4), (500, 800))
    buf = io.BytesIO()
    page.save(buf, format="PNG")
    res = run_analyze(buf.getvalue(), "image/png")
    assert res["kind"] == "image" and res["qr"]["raw"] == QR_RAW
    assert res["images"] == []  # a imagem original vai direta para a IA (PHP)


def test_qr_fallback_to_300_dpi(monkeypatch):
    real = ocr.render_pages

    def fake(pdf_bytes, dpi, last_page):
        imgs = real(pdf_bytes, dpi, last_page)
        # A 200 DPI o QR "não se lê" (páginas em branco); a 300 DPI é o render real.
        return [Image.new("RGB", im.size, "white") for im in imgs] if dpi == 200 else imgs

    monkeypatch.setattr(ocr, "render_pages", fake)
    res = run_analyze(text_pdf([invoice_lines()], qr_on_page=0), "application/pdf")
    assert res["qr"]["raw"] == QR_RAW and res["qr"]["dpi"] == 300


def test_non_at_qr_is_ignored():
    page = Image.new("L", (900, 1200), 255)
    page.paste(qr_image("https://example.com/promo", scale=4), (500, 800))
    buf = io.BytesIO()
    page.save(buf, format="PNG")
    assert run_analyze(buf.getvalue(), "image/png")["qr"] is None


def test_main_unknown_mode(monkeypatch, capsys):
    monkeypatch.setattr(sys, "stdin", io.StringIO(json.dumps({"mode": "nope"})))
    assert ocr.main() == 2
    assert json.loads(capsys.readouterr().out)["ok"] is False
