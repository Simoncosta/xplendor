'use client';

import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import api from "@/services/axiosInstance";
import { toastError, toastSuccess } from "@/helpers/u-swal-alert";
import { getTranslation } from '@/i18n';
import ComponentsCarEditor from "../car-editor/components-car-editor";
import { CarService } from "../../services/cars.service";
import { useSelector } from "react-redux";
import { IRootState } from "@/store";
import { ICar } from "../../models/cars.model";

export default function ComponentsCarUpdate({ id }: { id: string }) {
    const router = useRouter();
    const { t } = getTranslation();

    // Context
    const user = useSelector((state: IRootState) => state.auth.user);

    const carService = useMemo(() => new CarService(api, user.company_id), []);

    const [carData, setCarData] = useState<ICar>();

    useEffect(() => {
        const getContact = async () => {
            const responseCar = await carService.getCar(Number(id));
            setCarData(responseCar);
        }

        getContact();
    }, [id])

    return (
        <ComponentsCarEditor
            record={carData}
            onCancel={(cancel: boolean) => {
                if (cancel) {
                    router.push('/cars');
                }
            }}
            onSave={async (data, onCancel) => {
                try {
                    await carService.updateCar(Number(id), data);
                    onCancel(false);

                    toastSuccess(t('config.message.success'))
                } catch (err) {
                    onCancel(true);
                    if (err instanceof Error) {
                        toastError(t('config.message.success'));
                    }
                }
            }}
        />
    );
}