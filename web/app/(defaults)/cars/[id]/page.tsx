import ComponentsCompanyEditor from '@/components/companies/components/company-editor/components-company-editor';
import ComponentsCompanyUpdate from '@/components/companies/components/company-update/components-company-update';
import UBreadcrumbs from '@/components/u-breadcrumbs';
import { Metadata } from 'next';
import React from 'react';
import { getTranslation } from '@/i18n';
import ComponentsCarUpdate from '@/components/cars/components/car-update/components-car-update';

export const metadata: Metadata = {
    title: 'Cars - Edit',
};

export default async function CarsPage({ params }: { params: { id: string } }) {
    const { t } = getTranslation();

    const breadcrumbs = [
        {
            href: '/',
            title: t('home.title'),
        },
        {
            href: '/cars',
            title: t('cars.title'),
        },
        {
            href: '#',
            title: t('config.edit'),
        },
    ];

    const title = `${t('cars.title')} - ${t('config.edit')}`;

    return (
        <div>
            <UBreadcrumbs navigation={breadcrumbs} title={title} />
            <ComponentsCarUpdate
                id={params.id}
            />
        </div>
    );
};
