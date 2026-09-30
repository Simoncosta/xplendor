"""FASE 0 (motor de preço de autocaravanas) — parsing de specs a partir de
texto livre dos anúncios (título + descrição).

Extrai: dormidas (beds), tipologia (layout), cilindrada (displacement, cm³)
e comprimento (length, metros). Regex derivados EXCLUSIVAMENTE de padrões
observados num corpus real de 91 anúncios únicos (Standvirtual detalhe ×18,
CustoJusto listagem/detalhe ×73), recolhido em 2026-09-30, e endurecidos por
uma passagem adversarial (inputs plausíveis construídos das MESMAS frases
reais — ver tests/test_motorhome_specs.py::TestAdversarial*).

  DORMIDAS   "Lugares dormida 4" · "Lug dormida - 5" · "4 Dormidas" ·
             "e 6 dormidas" · "4 lugares de livrete e dormida" ·
             "5 lugares de viagem e de dormida" · "lugares de viagem e
             dormir" · "acomoda 4 pessoas" · "4 Lugares Dormida 5 Lugares
             livrete" (nº ANTES ganha, mas só na MESMA linha)
  COMPRIMENTO "Comprimento - 7450 mm" · "Comprimento 6,72 mt" ·
             "Comprimento 6.8m" · "Com. 6,25 Larg. 2,10" ·
             "Com. 5,10/6,20" (par lança/total → usar o TOTAL) ·
             "Apenas 5,90mt de comprimento" · "5,90 comprimento"
  CILINDRADA "2200cc" · "2987cc" · "2.800Cm" · "2198 c.c." · "2.3 Multijet" ·
             "2.3M-Jet" · "2.8 JTD/HDI" · "2500TDI" · "1.9TD" · "2 .0 JTD" ·
             "Jumper2.8 JTD" · "Motor 2300 130cv" · "Cilindrada: 2.8" ·
             "Motor 2.8cc" (unidade errada do anunciante) · "2.3 Ducato"
  TIPOLOGIA  "perfilada(s)" · "integral" · "capucino/capucine" · "furgão" ·
             "campervan" · "T5 Camper"

Falsos positivos que os regex REJEITAM (vistos no corpus ou construídos dele
na passagem adversarial):
  "Tração integral 4matic" / "revisão integral" / "pintura integral"
  "Caixa de 6 velocidades" · "5 lugares de livrete" sozinho (lotação)
  "Lugares livrete - 4 ⏎ Lug dormida - 5"  → 5 (o 4 é livrete, noutra linha)
  "mínimo 2 dormidas" (aluguer: noites) · "garagem acomoda 2 bicicletas"
  "de comprimento   34.900  2008" (PREÇO após a palavra comprimento)
  "Toldo Fiamma com 4,50 m de comprimento" (acessório)
  "Cama 190cm x 140cm ... 2200cc" → 2200 (a medida da cama não silencia o cc)
  "Motor 2019 recondicionado" / "ano 2005 TDI" (anos não são cilindrada)
  "motor consome 6.9 litros" (consumo) · "195/70 R14" · "3500 Kgs" ·
  "Frigorifico 156 lts" · "Fogão 3 bocas"

Regras de rigor:
  - Só se extrai o que está EXPLÍCITO; campo ausente → None (nunca inferir).
  - "caravana" por TEXTO nunca vira layout (ambíguo com "autocaravana");
    só é aceite quando vem estruturada (body_type do Standvirtual).
  - Gamas de sanidade: beds 1..12 · length 4.0..9.5 m · displacement 800..7000 cm³.
  - Ambiguidade documentada: "Motor 2000" é aceite como 2000 cm³ (cilindrada
    2.0 é comum); anos 1900-1999 e 2001-2029 são rejeitados nos padrões sem
    unidade explícita.
"""
from __future__ import annotations

import re
from typing import Optional

# Tipologias canónicas (vocabulário = slugs Standvirtual, consistente com
# `category`/degrau 4 da cascata Laravel).
CANONICAL_LAYOUTS = {"perfiladas", "integral", "capucine", "furgao", "caravana"}

# [^\S\n] = espaço em branco SEM newline — impede padrões de atravessar linhas
# (a linha "Lugares livrete - 4" não pode alimentar o nº de "Lug dormida" da
# linha seguinte).
_SP = r"[^\S\n]"


def _window_before(text: str, start: int, size: int = 30) -> str:
    return text[max(0, start - size):start]


# ─────────────────────────────────────────────────────────────────────────────
# Dormidas (beds)
# ─────────────────────────────────────────────────────────────────────────────

# Guarda anti-aluguer: "mínimo 2 dormidas"/"2 dormidas por noite" são NOITES.
_BEDS_RENTAL_CONTEXT = re.compile(r"m[ií]nimo|noite|di[áa]ri|aluguer|rent", re.I)

# Ordem de prioridade (primeiro match ganha). needs_guard aplica a guarda
# anti-aluguer à janela imediatamente antes do match.
_BEDS_PATTERNS: list[tuple[re.Pattern, bool]] = [
    # "4 Lugares Dormida" (nº ANTES; MESMA linha — cf. falso positivo real
    # "Lugares livrete - 4 ⏎ Lug dormida - 5")
    (re.compile(rf"(\d{{1,2}}){_SP}+lug(?:ares)?\.?{_SP}*(?:de{_SP}+)?dormidas?\b", re.I), False),
    # "Lugares dormida 4" · "Lug dormida - 5" · "lugares de dormida: 4"
    (re.compile(r"lug(?:ares)?\.?\s*(?:de\s+)?dormidas?\s*[-–—:]*\s*(\d{1,2})\b", re.I), False),
    # "Dormidas: 4" · "dormida - 5" (sem a palavra lugares)
    (re.compile(r"\bdormidas?\s*[-–—:]\s*(\d{1,2})\b", re.I), False),
    # "4 lugares de livrete e dormida" · "5 lugares de viagem e de dormida" ·
    # "4 lugares de viagem e dormir" (variante real, corpus c2/d6)
    (re.compile(r"(\d{1,2})\s+lugares?\s+de\s+(?:livrete|viagem)\s+e\s+(?:de\s+)?(?:dormidas?|dormir)\b", re.I), False),
    # "4 Dormidas" · "e 6 dormidas" — com guarda anti-aluguer
    (re.compile(r"(\d{1,2})\s*dormidas?\b", re.I), True),
    # "acomoda 4 pessoas" — a palavra pessoas/adultos/pax é OBRIGATÓRIA
    # ("garagem acomoda 2 bicicletas" não conta).
    (re.compile(r"(?:acomodam?|dormem?)\s*(?:até\s*)?(\d{1,2})\s*(?:pessoas|adultos|pax)\b", re.I), False),
]


def parse_beds(text: str) -> Optional[int]:
    if not text:
        return None
    for pattern, needs_guard in _BEDS_PATTERNS:
        for m in pattern.finditer(text):
            if needs_guard and _BEDS_RENTAL_CONTEXT.search(_window_before(text, m.start())):
                continue
            beds = int(m.group(1))
            if 1 <= beds <= 12:
                return beds
    return None


# ─────────────────────────────────────────────────────────────────────────────
# Comprimento (length, metros)
# ─────────────────────────────────────────────────────────────────────────────

# Tolerância "compr\w*": a listagem CustoJusto trunca palavras a meio
# ("...5.9m de comprime" é um doc real do corpus).
_COMPR = r"compr[a-zà-ÿ]*"

# nº ANTES da palavra: "Apenas 5,90mt de comprimento" · "5,90 comprimento".
# (?!\d) impede ler "8.90" de um preço "8.900".
_LENGTH_TRAILING = re.compile(
    rf"(\d[.,]\d{{1,2}})(?!\d)\s*(?:mm|mts?|m|metros)?\s*(?:de\s+)?{_COMPR}",
    re.I,
)
# Acessórios com comprimento próprio ("Toldo ... 4,50 m de comprimento").
_LENGTH_ACCESSORY_CONTEXT = re.compile(
    r"toldo|rampa|porta[- ]?moto|garagem|antena|mangueira|cabo|escada", re.I
)

# "Com. 6,25 Larg 2,10" · "Com. 5,10/6,20" (par: sem/com lança → o TOTAL).
_LENGTH_ABBREV = re.compile(
    r"\bcom\.\s*(\d[.,]\d{1,2})(?!\d)(?:\s*/\s*(\d[.,]\d{1,2})(?!\d))?",
    re.I,
)

# nº DEPOIS da palavra. Dois ramos SEGUROS:
#   (a) inteiro 3-4 dígitos COM unidade obrigatória mm/cm ("7450 mm", "745 cm")
#   (b) decimal (?!\d) com unidade opcional ("6,72 mt", "6.8m", "6,72")
# O ramo "inteiro sem unidade" NÃO existe: era a porta de entrada dos PREÇOS
# ("...de comprimento   8.900  2008" → 8.9 m). Confirmado adversarialmente.
_LENGTH_FULLWORD_INT = re.compile(
    rf"{_COMPR}\s*[-–—:]*\s*(\d{{3,4}})\s*(mm|cm)\b", re.I
)
_LENGTH_FULLWORD_DEC = re.compile(
    rf"{_COMPR}\s*[-–—:]*\s*(\d{{1,2}}[.,]\d{{1,2}})(?!\d)\s*(?:mm|mts?|m\b|metros)?",
    re.I,
)


def parse_length_m(text: str) -> Optional[float]:
    if not text:
        return None
    candidates: list[float] = []

    # 1º: nº-antes ("5,90mt de comprimento") — imune ao preço-depois-da-palavra.
    for m in _LENGTH_TRAILING.finditer(text):
        if _LENGTH_ACCESSORY_CONTEXT.search(_window_before(text, m.start())):
            continue
        candidates.append(round(float(m.group(1).replace(",", ".")), 2))
        break

    m = _LENGTH_ABBREV.search(text)
    if m:
        raw = m.group(2) or m.group(1)   # par "5,10/6,20" → total
        candidates.append(round(float(raw.replace(",", ".")), 2))

    m = _LENGTH_FULLWORD_INT.search(text)
    if m:
        divisor = 1000.0 if m.group(2).lower() == "mm" else 100.0
        candidates.append(round(int(m.group(1)) / divisor, 2))

    m = _LENGTH_FULLWORD_DEC.search(text)
    if m:
        candidates.append(round(float(m.group(1).replace(",", ".")), 2))

    for value in candidates:
        if 4.0 <= value <= 9.5:
            return value
    return None


# ─────────────────────────────────────────────────────────────────────────────
# Cilindrada (displacement, cm³)
# ─────────────────────────────────────────────────────────────────────────────

def _looks_like_year(value: int) -> bool:
    """1900-1999 e 2001-2029 tratados como anos nos padrões SEM unidade
    explícita. 2000 é aceite (cilindrada 2.0 é comum; ambiguidade documentada)."""
    return (1900 <= value <= 1999) or (2001 <= value <= 2029)


# "2200cc" · "2987cc" · "2.800Cm" · "2198 c.c." · "2 200 cm3"
_DISPLACEMENT_CC = re.compile(
    r"(\d{1}[.\s]?\d{3}|\d{3,4})\s*(c\.?c\.?|cm3|cm³|cm\b)",
    re.I,
)
# Medidas em cm ("Comprimento: 850 cm", "Largura 230 cm") não são cilindrada.
_CC_DIMENSION_CONTEXT = re.compile(r"compr|larg|alt(?:ura)?\b|di[âa]metro", re.I)

# Litros + sufixo de motor conhecido ou chassis conhecido.
_DISPLACEMENT_LITERS = re.compile(
    r"(\d)\s*[.,]\s*(\d)\s*-?\s*"
    r"(?:m-?jet|multijet|jtd|hdi|tdi|cdti|dci|crdi|tdci|td\b|turbo"
    r"|ducato|jumper|transit|boxer|master|sprinter|daily)",
    re.I,
)
# "2500TDI" — cilindrada em cc colada ao sufixo do motor (anos rejeitados:
# "ano 2005 TDI" não é cilindrada).
_DISPLACEMENT_CC_SUFFIX = re.compile(
    r"(\d{4})\s*(?:tdi|td|jtd|hdi|cdti|dci|multijet|m-?jet)\b",
    re.I,
)
# "Motor 2.5" · "Motor 2.8cc 129cv" · "Cilindrada: 2.8 idtd" · "Motorização
# Fiat 2.2". A palavra opcional exclui verbos de CONSUMO ("motor consome 6.9
# litros" não é cilindrada). (?!\d) porque \b falha entre dígito e letra.
_DISPLACEMENT_MOTOR = re.compile(
    r"(?:motor(?:iza[çc][ãa]o)?|cilindrada)\s*[-–—:]*\s*"
    r"(?:(?!consome|consumo|gasta|faz|media|média)[A-Za-zÀ-ÿ]+\s+)?"
    r"(\d)\s*[.,]\s*(\d)(?!\d)",
    re.I,
)
# "Motor 2300 130cv" / "Motorização 2200" — cc inteiro sem unidade, com âncora
# e guardas contra potência/km ("Motor 140cv") e anos ("Motor 2019 recondicionado").
_DISPLACEMENT_MOTOR_CC = re.compile(
    r"motor(?:iza[çc][ãa]o)?\s*[-–—:]*\s*(\d{4})(?!\s*(?:cv|cavalos|km|kms))\b",
    re.I,
)


def parse_displacement_cc(text: str) -> Optional[int]:
    if not text:
        return None

    # finditer em todos os padrões: o 1º match EM GAMA ganha. Um match fora
    # de gama (ex.: medida de cama "190cm") NUNCA silencia os seguintes —
    # falso negativo confirmado adversarialmente com frases reais do corpus.
    for m in _DISPLACEMENT_CC.finditer(text):
        unit = m.group(2).lower().rstrip(".")
        if unit == "cm" and _CC_DIMENSION_CONTEXT.search(_window_before(text, m.start(), 25)):
            continue
        cc = int(re.sub(r"[^\d]", "", m.group(1)))
        if 800 <= cc <= 7000:
            return cc

    for m in _DISPLACEMENT_CC_SUFFIX.finditer(text):
        cc = int(m.group(1))
        if 800 <= cc <= 7000 and not _looks_like_year(cc):
            return cc

    for pattern in (_DISPLACEMENT_LITERS, _DISPLACEMENT_MOTOR):
        for m in pattern.finditer(text):
            cc = int(m.group(1)) * 1000 + int(m.group(2)) * 100
            if 800 <= cc <= 7000:
                return cc

    for m in _DISPLACEMENT_MOTOR_CC.finditer(text):
        cc = int(m.group(1))
        if 800 <= cc <= 7000 and not _looks_like_year(cc):
            return cc
    return None


# ─────────────────────────────────────────────────────────────────────────────
# Tipologia (layout)
# ─────────────────────────────────────────────────────────────────────────────

# Ordem: capucine (específico) → perfiladas → integral → furgao. "perfilada"
# explícito ganha a "furgão"/"camper" genéricos: perfiladas e capucines são
# construídas SOBRE chassis-furgão e os anúncios dizem-no.
_LAYOUT_TEXT_PATTERNS: list[tuple[re.Pattern, str]] = [
    (re.compile(r"capucin[eoa]|capuchin[oa]", re.I), "capucine"),
    (re.compile(r"perfilad[ao]s?", re.I), "perfiladas"),
    (re.compile(r"integral", re.I), "integral"),
    # "campervan" · "T5 Camper" (real, CJ) — mas não "camping".
    (re.compile(r"furg[ãa]o|camper\s?van|\bcamper\b", re.I), "furgao"),
]
# "integral" é adjetivo comum em anúncios PT: tração integral, revisão
# integral, pintura integral, restauro integral… — nenhum é tipologia.
_INTEGRAL_FALSE_CONTEXT = re.compile(
    r"tra[cç]{1,2}[ãa]o|4x4|4matic|awd"
    r"|revis[ãa]o|pintura|restauro|limpeza|manuten[çc][ãa]o|vistoria|renova[çc][ãa]o",
    re.I,
)


def parse_layout_text(text: str) -> Optional[str]:
    """Tipologia a partir de TEXTO livre. Nota de rigor: 'caravana' nunca é
    inferida de texto (colide com 'autocaravana'); só entra estruturada."""
    if not text:
        return None
    for pattern, canonical in _LAYOUT_TEXT_PATTERNS:
        for m in pattern.finditer(text):
            if canonical == "integral":
                window = text[max(0, m.start() - 25): m.end() + 25]
                if _INTEGRAL_FALSE_CONTEXT.search(window):
                    continue
            return canonical
    return None


def normalize_structured_layout(value: Optional[str]) -> Optional[str]:
    """Mapeia um body_type ESTRUTURADO (Standvirtual/CustoJusto) para o
    canónico. Valores fora do vocabulário motorhome (ex.: atrelado-tenda)
    devolvem None — não são tipologias de autocaravana."""
    if not value:
        return None
    slug = str(value).strip().lower()
    aliases = {
        "perfiladas": "perfiladas",
        "perfilada": "perfiladas",
        "integral": "integral",
        "capucine": "capucine",
        "capucino": "capucine",
        "furgao": "furgao",
        "campervan": "furgao",
        "caravana": "caravana",
    }
    return aliases.get(slug)


# ─────────────────────────────────────────────────────────────────────────────
# Entrada única
# ─────────────────────────────────────────────────────────────────────────────

def parse_motorhome_specs(title: str, description: str) -> dict:
    """Extrai as 4 specs do texto livre (título + descrição). Campos ausentes
    ficam a None — o chamador decide fallbacks estruturados."""
    text = f"{title or ''}\n{description or ''}"
    return {
        "beds": parse_beds(text),
        "length": parse_length_m(text),
        "displacement": parse_displacement_cc(text),
        "layout": parse_layout_text(text),
    }
