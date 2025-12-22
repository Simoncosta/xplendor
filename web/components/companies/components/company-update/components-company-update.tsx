'use client';

import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import ComponentsCompanyEditor from "../company-editor/components-company-editor";
import { CompanyService } from "../../services/companies.service";
import api from "@/services/axiosInstance";
import { ICompany } from "../../models/companies.model";
import { toastError, toastSuccess } from "@/helpers/u-swal-alert";
import { getTranslation } from '@/i18n';

export default function ComponentsCompanyUpdate({ id }: { id: string }) {
    const router = useRouter();
    const { t } = getTranslation();

    const companyService = useMemo(() => new CompanyService(api), []);

    const [companyData, setCompanyData] = useState<ICompany>();

    useEffect(() => {
        const getContact = async () => {
            const customersupplierData = await companyService.getCompany(Number(id));
            setCompanyData(customersupplierData);
        }

        getContact();
    }, [id])

    return (
        <ComponentsCompanyEditor
            record={companyData}
            onCancel={(cancel: boolean) => {
                if (cancel) {
                    router.push('/companies');
                }
            }}
            onSave={async (data, onCancel) => {
                try {
                    await companyService.updateCompany(Number(id), data);
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