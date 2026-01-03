'use client';

import { useEffect, useState, useMemo } from "react";
import Link from 'next/link';
import { getTranslation } from "@/i18n";
import { useRouter } from "next/navigation";
import { useSelector } from "react-redux";
import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";
import Lightbox from 'react-18-image-lightbox';
import 'react-18-image-lightbox/style.css';
import { IRootState } from "@/store";
import { CarService } from "../../services/cars.service";
import { ICar } from "../../models/cars.model";
import api from "@/services/axiosInstance";
import UTabPanel from "@/components/u-tab-panel";
import IconEye from "@/components/icon/icon-eye";
import IconThumbUp from "@/components/icon/icon-thumb-up";
import UButton from "@/components/u-button";
import IconRefresh from "@/components/icon/icon-refresh";
import { CarDetailsService } from "./services/car-details.service";

export default function ComponentsCarDetails({ id }: { id: string }) {

    const router = useRouter();
    const { t } = getTranslation();

    // Context
    const user = useSelector((state: IRootState) => state.auth.user);

    const carService = useMemo(() => new CarService(api, user.company_id), []);
    const carDetailsService = useMemo(() => new CarDetailsService(api, user.company_id, Number(id)), []);

    const [carData, setCarData] = useState<ICar>();
    const [isOpen, setIsOpen] = useState(false);
    const [photoIndex, setPhotoIndex] = useState(0);
    const [loadingAiAnalyses, setLoadingAiAnalyses] = useState(false);

    const images = carData?.images || [];

    const openLightbox = (index: number) => {
        setPhotoIndex(index);
        setTimeout(() => setIsOpen(true), 0);
    };

    useEffect(() => {
        const getCar = async () => {
            const responseCar = await carService.getCar(Number(id));
            setCarData(responseCar);
        }

        getCar();
    }, [id]);

    const handleGenerateAiAnalyses = async () => {
        try {
            setLoadingAiAnalyses(true);
            const response = await carDetailsService.generateAiAnalyses();
            setCarData((prev: any) => {
                return {
                    ...prev,
                    analyses: response,
                }
            });
            setLoadingAiAnalyses(false);
        } catch (error) {
            console.error(error);
            setLoadingAiAnalyses(false);
        }
    }

    const handleFeedbackAiAnalyses = async (id: number, feedback: string) => {
        try {
            const response = await carDetailsService.feedbackAiAnalyses(Number(id), feedback);
            setCarData((prev: any) => {
                return {
                    ...prev,
                    analyses: response,
                }
            });
        } catch (error) {
            console.error(error);
        }
    }

    return (
        <>
            <UTabPanel
                tabs={[
                    { label: 'Estratégia' },
                    { label: 'Imagens' },
                    { label: 'Financeiro' },
                ]}
                panels={[
                    <div className="grid grid-cols-12 gap-3">
                        <div className="col-span-12 rounded-t-none panel flex items-center justify-between">
                            <h1 className="text-2xl font-semibold mr-1">
                                {carData?.brand?.name} - {carData?.model?.name}
                            </h1>
                            <span className="text-base">{" "}{carData?.version}</span>
                        </div>
                        <section className={`panel col-span-3`}>
                            <div className="flex justify-between">
                                <h5 className="text-xl font-semibold dark:text-white-light mr-1">Status</h5>
                            </div>
                            <div className="mt-2 flex items-center">
                                <div className="text-3xl font-bold ltr:mr-3 rtl:ml-3">{t(`cars.status.${carData?.status}`)}</div>
                            </div>
                        </section>

                        <section className="panel col-span-3">
                            <div className="flex justify-between">
                                <h5 className="text-xl font-semibold dark:text-white-light mr-1">Contactos</h5>
                            </div>
                            <div className="mt-2 flex items-center">
                                <div className="text-3xl font-bold ltr:mr-3 rtl:ml-3">{carData?.leads?.length}</div>
                            </div>
                        </section>

                        <section className="panel col-span-3">
                            <div className="flex justify-between">
                                <h5 className="text-xl font-semibold dark:text-white-light mr-1">Visualizações</h5>
                            </div>
                            <div className="mt-2 flex items-center">
                                <div className="text-3xl font-bold ltr:mr-3 rtl:ml-3">{carData?.views?.length}</div>
                            </div>
                        </section>

                        <section className="panel bg-primary col-span-3 text-white">
                            <div className="flex justify-between">
                                <h5 className="text-xl font-semibold dark:text-white-light mr-1">Preço</h5>
                                <span className="text-base">€</span>
                            </div>
                            <div className="mt-2 flex items-center">
                                <div className="text-3xl font-bold ltr:mr-3 rtl:ml-3">{
                                    new Intl.NumberFormat('pt-PT', {
                                        style: 'currency',
                                        currency: 'EUR',
                                        minimumFractionDigits: 2,
                                    }).format(Number(carData?.price_gross) ?? 0)}</div>
                            </div>
                        </section>

                        <div className="panel col-span-6 h-full">
                            <div className="flex items-center justify-between">
                                <div className={`flex items-start ${carData?.analyses?.analysis ? 'w-full -m-5 mb-5 border-b border-white-light dark:border-[#1b2e4b] p-5' : 'p-2'}`}>
                                    <div className="shrink-0 rounded-full ring-2 ring-white-light ltr:mr-4 rtl:ml-4 dark:ring-dark">
                                        <img src="/assets/images/user-profile.jpeg" alt="profile1" className="h-10 w-10 rounded-full object-cover" />
                                    </div>
                                    <div className="font-semibold">
                                        <h6>Quem Compra?</h6>
                                        <p className="mt-1 text-xs text-white-dark">Público alvo</p>
                                    </div>
                                </div>
                                {
                                    !carData?.analyses?.analysis && (
                                        <button
                                            type="button"
                                            onClick={handleGenerateAiAnalyses}
                                            disabled={loadingAiAnalyses}
                                            className={`${loadingAiAnalyses ? 'cursor-not-allowed' : ''}`}
                                        >
                                            <IconRefresh className={`w-5 h-5 ${loadingAiAnalyses ? 'animate-spin' : ''}`} />
                                        </button>
                                    )
                                }
                            </div>
                            {
                                carData?.analyses?.analysis && (
                                    <div>
                                        <div className="pb-8 prose max-w-none text-[#111] dark:text-[#FFF]">
                                            <ReactMarkdown remarkPlugins={[remarkGfm]}>
                                                {carData?.analyses?.analysis || ""}
                                            </ReactMarkdown>
                                        </div>
                                        <div className="absolute bottom-0 -mx-5 flex w-full items-center justify-between p-5">
                                            {
                                                carData?.analyses?.feedback ? (
                                                    <>
                                                        Obrigado pelo feedback
                                                    </>
                                                ) : (
                                                    <>
                                                        <button
                                                            type="button"
                                                            onClick={() => handleFeedbackAiAnalyses(Number(carData?.analyses?.id), 'positive')}
                                                            className="flex items-center"
                                                        >
                                                            <IconThumbUp className="w-5 h-5 text-primary inline ltr:mr-1.5 rtl:ml-1.5 relative -top-0.5" />
                                                            <span className="dark:text-info">Aprovado</span>
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => handleFeedbackAiAnalyses(Number(carData?.analyses?.id), 'negative')}
                                                            className="flex items-center"
                                                        >
                                                            <IconThumbUp className="w-5 h-5 text-danger inline ltr:mr-1.5 rtl:ml-1.5 relative -top-0.5 rotate-180" />
                                                            <span className="dark:text-info">Precisa Melhorar</span>
                                                        </button>
                                                    </>
                                                )
                                            }
                                        </div>
                                    </div>
                                )
                            }
                        </div >
                    </div >,
                    <div className="panel rounded-t-none p-2 grid grid-cols-1 gap-2 sm:grid-cols-3 md:grid-cols-4">
                        {images.map((img, index) => {
                            // @ts-ignore
                            const imageUrl = `${process.env.NEXT_PUBLIC_API_URL}${String(img?.image ?? '')}`;

                            return (
                                <button
                                    type="button"
                                    // @ts-ignore
                                    key={Number(img?.id) ?? index}
                                    onClick={() => openLightbox(index)}
                                    className="block"
                                >
                                    <img
                                        src={imageUrl}
                                        alt={`gallery-${index}`}
                                        className="h-full w-full rounded-md object-cover"
                                    />
                                </button>
                            );
                        })}

                        {carData?.images && carData?.images?.length > 0 && isOpen && photoIndex >= 0 && (
                            <Lightbox
                                // @ts-ignore
                                mainSrc={`${process.env.NEXT_PUBLIC_API_URL}${carData?.images?.at(photoIndex)?.image ?? ''}`}
                                // @ts-ignore
                                nextSrc={`${process.env.NEXT_PUBLIC_API_URL}${carData?.images?.at((photoIndex + 1) % carData.images.length)?.image ?? ''}`}
                                // @ts-ignore
                                prevSrc={`${process.env.NEXT_PUBLIC_API_URL}${carData?.images?.at((photoIndex - 1 + carData.images.length) % carData.images.length)?.image ?? ''}`}
                                onCloseRequest={() => setIsOpen(false)}
                                onMoveNextRequest={() =>
                                    setPhotoIndex((photoIndex + 1) % (carData?.images?.length ?? 0))
                                }
                                onMovePrevRequest={() =>
                                    setPhotoIndex((photoIndex - 1 + (carData?.images?.length ?? 0)) % (carData?.images?.length ?? 0))
                                }
                                imageTitle={`Imagem ${photoIndex + 1}`}
                                imageCaption={`Fonte: ${process.env.NEXT_PUBLIC_API_URL}`}
                                animationDuration={300}
                                keyRepeatLimit={180}
                            />
                        )}
                    </div>,
                ]
                }
            />
        </>
    )
}