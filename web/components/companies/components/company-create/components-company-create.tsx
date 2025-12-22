'use client';

import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import ComponentsCompanyEditor from "../company-editor/components-company-editor";
import { CompanyService } from "../../services/companies.service";
import api from "@/services/axiosInstance";
import { ICompany } from "../../models/companies.model";
import { toastError, toastSuccess } from "@/helpers/u-swal-alert";
import { getTranslation } from '@/i18n';
import { fetchMe } from "@/store/authSlice";
import { useDispatch } from "react-redux";
import { AppDispatch } from "@/store";

export default function ComponentsCompanyCreate() {
    const { t } = getTranslation();
    const dispatch = useDispatch<AppDispatch>();
    const router = useRouter();

    const companyService = useMemo(() => new CompanyService(api), []);

    const defaultRecord = useMemo(() => {
        return companyService.createCompanyDefault();
    }, []);

    return (
        <ComponentsCompanyEditor
            record={defaultRecord}
            onCancel={(cancel: boolean) => {
                if (cancel) {
                    router.push('/companies');
                }
            }}
            onSave={async (data: ICompany, onCancel) => {
                try {
                    const novo = await companyService.saveCompany(data);
                    onCancel(false);

                    await dispatch(fetchMe());

                    toastSuccess(t('config.message.success'));

                    router.push(`/companies/${novo.id}`);
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