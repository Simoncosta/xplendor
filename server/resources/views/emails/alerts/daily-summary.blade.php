<div style="font-family: Arial, sans-serif; color: #0f172a; line-height: 1.5;">
    <p><strong>Resumo diário de alertas</strong></p>
    <p>Empresa: {{ $companyName }}</p>
    <p>Total de alertas: {{ $alerts->count() }}</p>

    <ul style="padding-left: 18px;">
        @foreach ($alerts as $alert)
            <li style="margin-bottom: 8px;">
                [{{ strtoupper($alert['type']) }}] {{ $alert['car_name'] ?: 'Viatura sem nome' }} → {{ $alert['title'] }}
            </li>
        @endforeach
    </ul>
</div>
