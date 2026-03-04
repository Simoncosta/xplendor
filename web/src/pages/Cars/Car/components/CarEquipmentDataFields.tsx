import { useEffect, useMemo } from "react";
import { useFormikContext } from "formik";
import XInputCheckboxArray from "Components/Common/XInputCheckboxArray";
import type { ICarUpdatePayload, CarExtraGroup, CarExtrasGroup } from "common/models/car.model";

const EXTRA_GROUPS: { key: CarExtraGroup; title: string; items: string[] }[] = [
    {
        key: "comfort_multimedia",
        title: "Conforto & Multimédia",
        items: [
            "A/C Bancos Traseiros",
            "Android Auto",
            "Apple CarPlay",
            "Audi Smartphone interface",
            "Bluetooth",
            "Carregamento Wireless",
            "Cockpit Virtual",
            "Computador de Bordo",
            "Controlo de funções do veículo por voz",
            "Easy Open/Easy Close",
            "Ecrã Consola Central",
            "Ecrã Encosto de Cabeça",
            "Ecrã no Tejadilho",
            "Ecrã Táctil ou Touch Screen",
            "Entrada AUX",
            "Entrada USB",
            "GPS",
            "GPS via Tlm",
            "Head-up display",
            "Internet a bordo",
            "Kit de Telefone Mãos Livres",
            "Leitor de DVD",
            "Leitor MP3",
            "Mirror Link",
            "MMI",
            "Porta Bagageira Automática",
            "Sistema de Entrada Sem Chave",
            "Sistema de Som",
            "Televisão",
            "USB bancos traseiros",
            "USB-C",
            "Wifi",
        ],
    },
    {
        key: "exterior_equipment",
        title: "Equipamento Exterior",
        items: [
            "Acabamentos em Alumínio",
            "Barras de Tejadilho",
            "Faróis de Nevoeiro",
            "Faróis Direccionais",
            "Faróis Diurnos",
            "Faróis Diurnos Em Led",
            "Faróis Reguláveis em Altura",
            "Função Luzes Coming & Leaving Home",
            "Gancho de Reboque",
            "Hard Top Amovível",
            "Jantes de Liga Leve",
            "Luzes Traseiras LED",
            "Portas Laterais Automáticas",
            "Retrovisor Anti-Encadeamento",
            "Retrovisores Aquecidos",
            "Retrovisores c/ Anti Encadeamento",
            "Retrovisores c/ Regulação Eléctrica",
            "Retrovisores Rebativeis Eletricos",
            "Tuning Estético",
            "Vidros Escurecidos",
        ],
    },
    {
        key: "interior_equipment",
        title: "Equipamento Interior",
        items: [
            "Acabamentos em Madeira",
            "Apoio de Braço",
            "Bancos Desportivos",
            "Bancos Dianteiros Aquecidos",
            "Bancos Dianteiros c/ Memória",
            "Bancos Dianteiros c/ Regulação Eléctrica",
            "Bancos Dianteiros com Apoio Lombar",
            "Bancos em Alcântara",
            "Bancos Ortopédicos",
            "Bancos Rebativeis",
            "Bancos Traseiros Aquecidos",
            "Bancos Traseiros c/ Config. Individual",
            "Bancos Ventilados",
            "Carregador de smartphone wireless",
            "Controlo por voz",
            "Cortinas nas Portas Traseiras",
            "Encostos de Cabeça Traseiros",
            "Manete de velocidades em pele",
            "Mesa Rebativel",
            "Não fumador",
            "Patilhas de Velocidade no Volante",
            "Volante com Comandos de Rádio",
            "Volante Desportivo",
            "Volante Multifunções",
        ],
    },
    {
        key: "safety_performance",
        title: "Segurança & Desempenho",
        items: [
            "ABS",
            "Airbag de Passageiros",
            "Airbag do Condutor",
            "Airbags Laterais",
            "Ajuda ao parqueamento",
            "Alarme",
            "Alerta De Colisão - Travagem De Emergência",
            "Alerta sobre Manutenção",
            "Alerta Transposição de Linha",
            "Alertas sobre Cinto de Segurança",
            "Assistência à Condução Noturna",
            "Assistente Faixa de Rodagem",
            "Aviso de ângulo morto",
            "Aviso de velocidade",
            "Aviso Sinal De Transito",
            "Botão Start",
            "Camara 360º",
            "Camara de Frente",
            "Câmara de Marcha Atrás",
            "Câmaras Laterais",
            "Cruise Control",
            "Deteção Cansaço e Fadiga",
            "Diferentes Modos de Condução",
            "Direção Adaptativa",
            "Direcção Assistida",
            "EDS Bloqueio Electrónico do Diferencial",
            "ESP Controle Electrónico de Estabilidade",
            "Fecho Autom. das Portas em Andamento",
            "Fecho Central",
            "Fecho Centralizado com Comando a Distância",
            "Fecho das Portas Automático",
            "Filtro de Partículas",
            "Imobilizador",
            "Intarder",
            "ISOFIX",
            "Kit Hidráulico",
            "Kit Pneus",
            "Livro de revisões completo",
            "MSR Regulador Momentâneo de Binário",
            "Perfil De Condutores",
            "Retarder",
            "Sensor de estacionamento dianteiro",
            "Sensor de estacionamento traseiro",
            "Sensores de Chuva",
            "Sensores de Luzes",
            "Sistema Ajuda ao Arranque em Inclinação",
            "Sistema de Chave Inteligente",
            "Sistema de Controle de Pressão dos Pneus",
            "Sistema de estacionamento autónomo",
            "Sistema SOS",
            "Start and Stop",
            "Suspensão Desportiva",
            "Suspensão Pneumatica",
            "TCS Sistema de Control de Tracção",
            "Tecnologia eléctrica",
            "Travão de Mão Eléctrico",
            "Tuning Mecânico",
        ],
    },
];

type FormValues = ICarUpdatePayload & {
    // UI state (map) usado pelos checkboxes
    extrasByGroup: Record<CarExtraGroup, string[]>;
};

const GROUP_KEYS: CarExtraGroup[] = [
    "comfort_multimedia",
    "exterior_equipment",
    "interior_equipment",
    "safety_performance",
];

// converte map -> array (payload)
const mapToArray = (map: Record<CarExtraGroup, string[]>): CarExtrasGroup[] =>
    GROUP_KEYS.map((group) => ({ group, items: map?.[group] ?? [] }));

const emptyExtrasByGroup: Record<CarExtraGroup, string[]> = {
    comfort_multimedia: [],
    exterior_equipment: [],
    interior_equipment: [],
    safety_performance: [],
};

const arrayToMap = (arr?: CarExtrasGroup[]) => {
    const map: Record<CarExtraGroup, string[]> = {
        ...emptyExtrasByGroup,
    };

    (arr ?? []).forEach((g) => {
        map[g.group] = g.items ?? [];
    });

    return map;
};

export default function CarEquipmentDataFields({ isEdit }: { isEdit: boolean }) {
    const { values, setFieldValue } = useFormikContext<FormValues>();

    // 1) Ao carregar / reinitialize (editar), garante que extrasByGroup é preenchido a partir de values.extras
    useEffect(() => {
        // se já tiver UI preenchida, não mexe
        const hasAnyUI =
            values.extrasByGroup &&
            Object.values(values.extrasByGroup).some((items) => (items?.length ?? 0) > 0);

        // se veio do backend (array) mas UI está vazia, hidrata UI
        const hasBackend = (values.extras?.length ?? 0) > 0;

        if (!hasAnyUI && hasBackend) {
            setFieldValue("extrasByGroup", arrayToMap(values.extras as any), false);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isEdit]); // dispara no mount e quando alterna edit/create

    // 2) Sempre que extrasByGroup mudar, sincroniza para values.extras (array)
    useEffect(() => {
        if (!values.extrasByGroup) return;
        setFieldValue("extras", mapToArray(values.extrasByGroup), false);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [values.extrasByGroup]);

    const counts = useMemo(() => {
        const map = new Map<CarExtraGroup, number>();
        EXTRA_GROUPS.forEach((g) => {
            map.set(g.key, (values.extrasByGroup?.[g.key] ?? []).length);
        });
        return map;
    }, [values.extrasByGroup]);

    return (
        <div className="mt-4">
            {EXTRA_GROUPS.map((group) => {
                const selectedCount = counts.get(group.key) ?? 0;
                const total = group.items.length;

                return (
                    <div key={group.key} className="mb-4">
                        <div className="d-flex align-items-center justify-content-between border-bottom pb-2 mb-3">
                            <h5 className="mb-0">{group.title}</h5>
                            <span className="text-muted">{selectedCount}/{total}</span>
                        </div>

                        <div className="row">
                            {group.items.map((item) => (
                                <div key={item} className="col-12 col-md-6 col-lg-4 col-xl-3 mb-2">
                                    <XInputCheckboxArray
                                        name={`extrasByGroup.${group.key}`}
                                        value={item}
                                        label={item}
                                    />
                                </div>
                            ))}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}