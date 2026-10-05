{{--
    PDF do orçamento (dompdf). Recebe $doc já preparado pelo QuotePdfPresenter:
    textos e valores formatados em pt-PT; aqui só há apresentação.
    dompdf: layout com tabelas (sem flex/grid); fontes Inter locais (acentos PT).
--}}
<!DOCTYPE html>
<html lang="pt-PT">
<head>
<meta charset="utf-8">
<title>{{ $doc['title'] }}</title>
<style>
    @font-face { font-family: 'Inter'; font-weight: 400; font-style: normal; src: url('{{ $doc['assets']['font_regular'] }}') format('truetype'); }
    @font-face { font-family: 'Inter'; font-weight: 600; font-style: normal; src: url('{{ $doc['assets']['font_semibold'] }}') format('truetype'); }
    @font-face { font-family: 'Inter'; font-weight: 700; font-style: normal; src: url('{{ $doc['assets']['font_bold'] }}') format('truetype'); }

    @page { margin: 34mm 16mm 26mm 16mm; }
    * { box-sizing: border-box; }
    body { font-family: 'Inter', 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1d2128; line-height: 1.45; margin: 0; }

    /* Cabeçalho e rodapé repetidos em todas as páginas */
    .page-header { position: fixed; top: -24mm; left: 0; right: 0; height: 20mm; }
    .page-footer { position: fixed; bottom: -19mm; left: 0; right: 0; height: 15mm; border-top: 0.6pt solid #d9dce1; padding-top: 2.5mm; font-size: 7.5pt; color: #6b717c; }
    .page-footer table { width: 100%; border-collapse: collapse; }
    .page-footer .brandline { color: #1d2128; }
    .page-footer a { color: #6b717c; text-decoration: none; }
    .section { page-break-inside: avoid; }
    .lines tr { page-break-inside: avoid; }
    .nowrap { white-space: nowrap; }

    .brand td { vertical-align: middle; }
    .brand-logo { width: 9mm; height: 9mm; }
    .brand-name { font-size: 15pt; font-weight: 700; letter-spacing: 0.06em; padding-left: 2.5mm; }
    .doc-meta { text-align: right; font-size: 8.5pt; color: #6b717c; }
    .doc-meta .doc-kind { font-size: 8pt; font-weight: 600; letter-spacing: 0.14em; color: #1d2128; }
    .doc-meta .doc-number { font-size: 13pt; font-weight: 700; color: #1d2128; }

    table { border-collapse: collapse; }
    .w-100 { width: 100%; }
    .muted { color: #6b717c; }
    .label { font-size: 7.5pt; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; color: #6b717c; margin-bottom: 1.5mm; }

    .parties td { vertical-align: top; width: 50%; }
    .party { background: #f5f6f8; border-radius: 2mm; padding: 4mm 5mm; }
    .party-name { font-size: 11pt; font-weight: 600; margin-bottom: 1mm; }

    .intro { margin: 7mm 0 2mm; }
    .intro-title { font-size: 13pt; font-weight: 700; margin: 0 0 1.5mm; }

    .section-title { font-size: 10.5pt; font-weight: 700; margin: 7mm 0 2mm; }
    .section-title .muted { font-weight: 400; font-size: 8.5pt; }
    .lines th { font-size: 7.5pt; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: #6b717c; text-align: left; padding: 2mm 2mm; border-bottom: 0.8pt solid #1d2128; }
    .lines td { padding: 2.6mm 2mm; border-bottom: 0.6pt solid #e4e6ea; vertical-align: top; }
    .lines .num { text-align: right; white-space: nowrap; }
    .lines .item-name { font-weight: 600; }
    .lines .item-optional { font-weight: normal; color: #6b717c; font-size: 0.85em; }
    .lines .item-desc { color: #6b717c; font-size: 8.5pt; margin-top: 0.5mm; }
    .lines .discount { color: #1a7f4b; }
    .lines tr.subtotal td { border-bottom: none; padding-top: 2.4mm; padding-bottom: 1mm; color: #6b717c; }
    .lines tr.package td { border-bottom: none; padding-top: 1mm; padding-bottom: 1mm; color: #1a7f4b; font-weight: 600; }
    .lines tr.total td { border-top: 0.8pt solid #1d2128; border-bottom: none; padding-top: 2.4mm; font-weight: 700; font-size: 10.5pt; }

    .totals { margin-top: 8mm; }
    .totals td { vertical-align: top; }
    .total-box { border: 0.8pt solid #1d2128; border-radius: 2mm; padding: 4mm 5mm; }
    .total-box .value { font-size: 17pt; font-weight: 700; margin-top: 1mm; }
    .total-box .unit { font-size: 9pt; font-weight: 600; color: #6b717c; }
    .vat-note { margin-top: 2.5mm; font-size: 8.5pt; color: #1d2128; font-weight: 600; }

    .conditions { margin-top: 8mm; page-break-inside: avoid; }
    .conditions table td { padding: 1.6mm 0; vertical-align: top; border-bottom: 0.6pt solid #eef0f2; }
    .conditions .cond-key { width: 42mm; color: #6b717c; padding-right: 4mm; }
    .acceptance { margin-top: 6mm; font-size: 8.5pt; color: #6b717c; }
</style>
</head>
<body>

<div class="page-header">
    <table class="w-100 brand">
        <tr>
            <td style="width: 60%;">
                <table><tr>
                    <td><img class="brand-logo" src="{{ $doc['assets']['logo'] }}" alt=""></td>
                    <td class="brand-name">{{ $doc['legal']['brand'] }}</td>
                </tr></table>
            </td>
            <td class="doc-meta">
                <div class="doc-kind">ORÇAMENTO</div>
                <div class="doc-number">{{ $doc['number'] }}</div>
                <div>{{ $doc['version_label'] }}</div>
            </td>
        </tr>
    </table>
</div>

<div class="page-footer">
    <table>
        <tr>
            <td>
                <div class="brandline">{{ $doc['legal']['brand_line'] }}</div>
                <div>{{ $doc['legal']['contact_line'] }}</div>
                <div>
                    @foreach ($doc['legal']['socials'] as $social)
                        <a href="{{ $social['url'] }}">{{ $social['title'] }}: {{ $social['label'] }}</a>@if (! $loop->last)&nbsp;&nbsp;·&nbsp;&nbsp;@endif
                    @endforeach
                </div>
            </td>
            {{-- "Página X de Y" é escrito pelo QuotePdfRenderer (dompdf não conta o total de páginas em CSS). --}}
            <td style="width: 28mm;"></td>
        </tr>
    </table>
</div>

<table class="w-100 parties">
    <tr>
        <td style="padding-right: 3mm;">
            <div class="party">
                <div class="label">Cliente</div>
                <div class="party-name">{{ $doc['customer']['name'] }}</div>
                @foreach ($doc['customer']['lines'] as $line)
                    <div class="muted">{{ $line }}</div>
                @endforeach
            </div>
        </td>
        <td style="padding-left: 3mm;">
            <div class="party">
                <div class="label">Datas</div>
                <table class="w-100">
                    <tr><td class="muted">Data do orçamento</td><td class="nowrap" style="text-align: right; font-weight: 600;">{{ $doc['issued_at'] }}</td></tr>
                    <tr><td class="muted">Válido até</td><td class="nowrap" style="text-align: right; font-weight: 600;">{{ $doc['valid_until'] }}</td></tr>
                    @if ($doc['minimum_contract'])
                        <tr><td class="muted">Contrato mínimo</td><td class="nowrap" style="text-align: right; font-weight: 600;">{{ $doc['minimum_contract'] }}</td></tr>
                    @endif
                </table>
            </div>
        </td>
    </tr>
</table>

@if ($doc['title_text'] || $doc['intro'])
    <div class="intro">
        @if ($doc['title_text'])<div class="intro-title">{{ $doc['title_text'] }}</div>@endif
        @if ($doc['intro'])<div class="muted">{!! nl2br(e($doc['intro'])) !!}</div>@endif
    </div>
@endif

@foreach ($doc['sections'] as $section)
    <div class="section">
    <div class="section-title">{{ $section['title'] }} <span class="muted">{{ $section['subtitle'] }}</span></div>
    <table class="w-100 lines">
        <thead>
            <tr>
                <th style="width: 46%;">Serviço</th>
                <th class="num" style="width: 10%;">Qtd.</th>
                <th class="num" style="width: 15%;">Preço</th>
                <th class="num" style="width: 13%;">Desconto</th>
                <th class="num" style="width: 16%;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($section['lines'] as $line)
                <tr>
                    <td>
                        <div class="item-name">{{ $line['name'] }}@if(!empty($line['optional'])) <span class="item-optional">(opcional)</span>@endif</div>
                        @if ($line['description'])<div class="item-desc">{{ $line['description'] }}</div>@endif
                    </td>
                    <td class="num">{{ $line['quantity'] }}</td>
                    <td class="num">{{ $line['unit_price'] }}</td>
                    <td class="num discount">{{ $line['discount'] }}</td>
                    <td class="num">{{ $line['total'] }}</td>
                </tr>
            @endforeach
            @if ($section['package_discount'])
                <tr class="subtotal"><td colspan="4">Subtotal</td><td class="num">{{ $section['subtotal'] }}</td></tr>
                <tr class="package"><td colspan="4">{{ $section['package_discount']['label'] }}</td><td class="num">{{ $section['package_discount']['value'] }}</td></tr>
            @endif
            <tr class="total"><td colspan="4">{{ $section['total_label'] }}</td><td class="num">{{ $section['total'] }}</td></tr>
        </tbody>
    </table>
    </div>
@endforeach

<div class="section">
<table class="w-100 totals">
    <tr>
        @foreach ($doc['totals'] as $i => $total)
            <td style="width: 50%; {{ $i === 0 ? 'padding-right: 3mm;' : 'padding-left: 3mm;' }}">
                <div class="total-box">
                    <div class="label">{{ $total['label'] }}</div>
                    <div class="value">{{ $total['value'] }} <span class="unit">{{ $total['unit'] }}</span></div>
                </div>
            </td>
        @endforeach
        @if (count($doc['totals']) === 1)<td style="width: 50%;"></td>@endif
    </tr>
</table>
<div class="vat-note">{{ $doc['vat_note'] }}</div>
</div>

<div class="conditions">
    <div class="label" style="margin-bottom: 2mm;">Condições</div>
    <table class="w-100">
        @foreach ($doc['conditions'] as $condition)
            <tr><td class="cond-key">{{ $condition['key'] }}</td><td>{{ $condition['value'] }}</td></tr>
        @endforeach
    </table>
    <div class="acceptance">{{ $doc['acceptance_note'] }}</div>
</div>

</body>
</html>
