'use client';

import { Swiper, SwiperSlide } from 'swiper/react';
import 'swiper/css';
import 'swiper/css/navigation';
import 'swiper/css/pagination';
import { Navigation, Pagination } from 'swiper/modules';
import IconCaretDown from '../../../icon/icon-caret-down';
import { ICar } from "../../models/cars.model";
import { useSelector } from "react-redux";
import { getTranslation } from "@/i18n";
import { IRootState } from '@/store';

export default function ComponentsCarExpansion({ data }: { data: ICar }) {
    const { t } = getTranslation();

    // Context Style
    const themeConfig = useSelector((state: IRootState) => state.themeConfig);

    return (
        <div className="flex flex-row gap-2">
            <section className="relative w-[300px] h-[225px] panel p-0">
                <Swiper
                    modules={[Navigation, Pagination]}
                    slidesPerView={1}
                    spaceBetween={30}
                    loop
                    pagination={{
                        clickable: true,
                        type: 'fraction',
                    }}
                    navigation={{
                        nextEl: '.swiper-button-next-ex4',
                        prevEl: '.swiper-button-prev-ex4',
                    }}
                    dir={themeConfig.rtlClass}
                    key={themeConfig.rtlClass === 'rtl' ? 'rtl' : 'ltr'}
                    className="rounded overflow-hidden"
                >
                    {(data.images || []).map((img: any, idx) => (
                        <SwiperSlide key={idx}>
                            <img src={`${process.env.NEXT_PUBLIC_API_URL}/${String(img.image)}`} alt={`Imagem ${idx}`} className="w-[300px] h-[225px] object-cover rounded-md" />
                        </SwiperSlide>
                    ))}
                </Swiper>

                {/* Botões externos */}
                <button className="swiper-button-prev-ex4 absolute top-1/2 left-2 z-10 -translate-y-1/2 grid place-content-center rounded-full border border-primary p-1 text-primary hover:bg-primary hover:text-white">
                    <IconCaretDown className="w-5 h-5 rotate-90" />
                </button>
                <button className="swiper-button-next-ex4 absolute top-1/2 right-2 z-10 -translate-y-1/2 grid place-content-center rounded-full border border-primary p-1 text-primary hover:bg-primary hover:text-white">
                    <IconCaretDown className="w-5 h-5 -rotate-90" />
                </button>
            </section>

            <section className="w-full grid grid-cols-3 panel">
                <h1 className="font-bold text-lg col-span-3">{data.brand.name} {data.model.name} <span className="font-normal">{data.version}</span></h1>
                <div className="col-span-1">
                    <p className="text-lg font-semibold">{t('grid.license_plate')}</p>
                    <p className="text-lg">{data.license_plate}</p>
                </div>
                <div className="col-span-1">
                    <p className="text-lg font-semibold">{t('grid.year')}</p>
                    <p className="text-lg">{data.registration_year}</p>
                </div>
                <div className="col-span-1">
                    <p className="text-lg font-semibold">Kms</p>
                    <p className="text-lg">{data.mileage_km}</p>
                </div>
                <div className="col-span-1">
                    <p className="text-lg font-semibold">VIN</p>
                    <p className="text-lg">{data.mileage_km}</p>
                </div>
                <div className="col-span-1">
                    <p className="text-lg font-semibold">{t('grid.fuel_type')}</p>
                    <p className="text-lg">{t('cars.fuel_type.' + data.fuel_type)}</p>
                </div>
                <div className="col-span-1">
                    <p className="text-lg font-semibold">{t('grid.transmission')}</p>
                    <p className="text-lg">{t('cars.transmission.' + data.transmission)}</p>
                </div>
            </section>

            <section className="w-1/5 flex flex-col items-center justify-center panel">
                <p className="text-lg font-semibold">PREÇO</p>
                <p className="text-lg">
                    {new Intl.NumberFormat('pt-PT', {
                        style: 'currency',
                        currency: 'EUR',
                        minimumFractionDigits: 2,
                    }).format(Number(data.price_gross) ?? 0)}
                </p>
            </section>
        </div>
    );
}