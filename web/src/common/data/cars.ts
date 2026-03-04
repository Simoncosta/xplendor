const statusOptions = [
    { value: "draft", label: "Rascunho" },
    { value: "active", label: "Ativo" },
    { value: "inactive", label: "Inativo" },
    { value: "sold", label: "Vendido" },
];

const originOptions = [
    { value: "imported", label: "Importada" },
    { value: "national", label: "Nacional" },
];

const transmissionOptions = [
    { value: "manual", label: "Manual" },
    { value: "automatic", label: "Automática" },
    { value: "semi-automatic", label: "Semi-automática" },
];

const segmentOptions = [
    { value: "city_car", label: "Citadino" },
    { value: "suv_tt", label: "SUV" },
    { value: "hatchback", label: "Hatchback" },
    { value: "sedan", label: "Sedan" },
    { value: "coupe", label: "Coupé" },
    { value: "station_wagon", label: "Carrinha" },
];

const fuelTypeOptions = [
    { value: "gasoline", label: "Gasolina" },
    { value: "diesel", label: "Diesel" },
    { value: "flex", label: "Flex" },
    { value: "electric", label: "Elétrico" },
    { value: "hybrid", label: "Híbrido" },
];

const monthsOptions = [
    { value: 1, label: "Janeiro" },
    { value: 2, label: "Fevereiro" },
    { value: 3, label: "Março" },
    { value: 4, label: "Abril" },
    { value: 5, label: "Maio" },
    { value: 6, label: "Junho" },
    { value: 7, label: "Julho" },
    { value: 8, label: "Agosto" },
    { value: 9, label: "Setembro" },
    { value: 10, label: "Outubro" },
    { value: 11, label: "Novembro" },
    { value: 12, label: "Dezembro" },
];

const seatsOptions = [
    { value: 1, label: "1 Lugar" },
    { value: 2, label: "2 Lugares" },
    { value: 3, label: "3 Lugares" },
    { value: 4, label: "4 Lugares" },
    { value: 5, label: "5 Lugares" },
    { value: 6, label: "6 Lugares" },
    { value: 7, label: "7 Lugares" },
    { value: 8, label: "8 Lugares" },
    { value: 9, label: "9 Lugares" },
    { value: 10, label: "10 Lugares" },
    { value: 16, label: "16 Lugares" },
    { value: 99, label: "Indefinido" },
];

const colorsOptions = [
    { value: "blue", label: "Azul" },
    { value: "light-blue", label: "Azul Claro" },
    { value: "yellow", label: "Amarelo" },
    { value: "dark-blue", label: "Azul Escuro" },
    { value: "beige", label: "Bege" },
    { value: "bordeaux", label: "Bordeaux" },
    { value: "white", label: "Branco" },
    { value: "brown", label: "Castanho" },
    { value: "champagne", label: "Champagne" },
    { value: "gray", label: "Cinza" },
    { value: "dark-gray", label: "Cinza Antracite" },
    { value: "silver-gray", label: "Cinza Prata" },
    { value: "orange", label: "Laranja" },
    { value: "other", label: "Outras" },
    { value: "black", label: "Preto" },
    { value: "pink", label: "Rosa" },
    { value: "purple", label: "Roxo" },
    { value: "light-green", label: "Verde Claro" },
    { value: "dark-green", label: "Verde Escuro" },
    { value: "red", label: "Vermelho" },
];

const conditionsOptions = [
    { value: "new", label: "Novo" },
    { value: "used", label: "Usado" },
    { value: "like_new", label: "Como Novo" },
    { value: "good", label: "Bom" },
    { value: "service", label: "Serviço" },
    { value: "trade_in", label: "Troca" },
    { value: "classic", label: "Clássico" }
];

const classTaxOptions = [
    { value: "Classe 1", label: "Classe 1" },
    { value: "Classe 2", label: "Classe 2" },
    { value: "Classe 3", label: "Classe 3" },
    { value: "Classe 4", label: "Classe 4" },
    { value: "Classe 5", label: "Classe 5" },
];

export {
    statusOptions,
    originOptions,
    monthsOptions,
    fuelTypeOptions,
    transmissionOptions,
    segmentOptions,
    seatsOptions,
    colorsOptions,
    conditionsOptions,
    classTaxOptions
};