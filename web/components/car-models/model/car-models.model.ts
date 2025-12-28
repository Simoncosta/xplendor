export interface ICarModel {
    id: number | undefined;
    name: string;
    type: 'recommended' | 'other';
    car_brand_id: number;
}