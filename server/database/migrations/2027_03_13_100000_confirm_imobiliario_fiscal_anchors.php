<?php

use App\Models\ContentAnchor;
use App\Models\ContentSector;
use Illuminate\Database\Migrations\Migration;

/**
 * As datas do IMI e do IRS das âncoras do imobiliário foram confirmadas no Portal das Finanças a 23/10/2026:
 * a nota de cada âncora deixa de dizer "por confirmar". Só muda a nota (as regras e as datas ficam).
 */
return new class extends Migration
{
    private const TITLES = ['IMI: pagamento único ou 1.ª prestação', 'IMI: 2.ª prestação', 'IMI: última prestação', 'IRS: entrega da declaração', 'IRS: pagamento ou reembolso'];

    private const NOTE = 'Fonte: Portal das Finanças (Autoridade Tributária), confirmada a 23/10/2026. Num fim de semana ou feriado, passa para o dia útil seguinte.';

    private const OLD_NOTE = 'Fonte oficial: Portal das Finanças (Autoridade Tributária). Data das Notas do Roteiro, vista a 23/10/2026; por confirmar na fonte oficial. Num fim de semana ou feriado, passa para o dia útil seguinte.';

    public function up(): void
    {
        $sector = ContentSector::where('slug', 'imobiliario')->value('id');
        if ($sector) {
            ContentAnchor::where('sector_id', $sector)->whereIn('title', self::TITLES)->update(['notes' => self::NOTE]);
        }
    }

    public function down(): void
    {
        $sector = ContentSector::where('slug', 'imobiliario')->value('id');
        if ($sector) {
            ContentAnchor::where('sector_id', $sector)->whereIn('title', self::TITLES)->update(['notes' => self::OLD_NOTE]);
        }
    }
};
