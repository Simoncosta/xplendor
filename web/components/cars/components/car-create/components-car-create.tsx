'use client';

import { useMemo } from "react";
import { useRouter } from "next/navigation";
import api from "@/services/axiosInstance";
import { toastError, toastSuccess } from "@/helpers/u-swal-alert";
import { getTranslation } from '@/i18n';
import { IRootState } from "@/store";
import ComponentsCarEditor from "../car-editor/components-car-editor";
import { ICar } from "../../models/cars.model";
import { CarService } from "../../services/cars.service";
import { useSelector } from "react-redux";

export default function ComponentsCarCreate() {
    const { t } = getTranslation();
    const router = useRouter();

    // Context
    const user = useSelector((state: IRootState) => state.auth.user);

    const carService = useMemo(() => new CarService(api, user.company_id), []);

    const defaultRecord = useMemo(() => {
        return carService.createCarDefault();
    }, []);

    return (
        <ComponentsCarEditor
            record={defaultRecord}
            onCancel={(cancel: boolean) => {
                if (cancel) {
                    router.push('/cars');
                }
            }}
            onSave={async (data: ICar, onCancel) => {
                try {
                    const novo = await carService.saveCar(data);
                    onCancel(false);

                    toastSuccess(t('config.message.success'));

                    router.push(`/cars/${novo.id}`);
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