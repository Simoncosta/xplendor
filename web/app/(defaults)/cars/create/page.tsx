import { Metadata } from 'next';
import React from 'react';
import ComponentsCompanyCreate from '@/components/companies/components/company-create/components-company-create';
import UBreadcrumbs from '@/components/u-breadcrumbs';
import { getTranslation } from '@/i18n';
import ComponentsCarCreate from '@/components/cars/components/car-create/components-car-create';

export const metadata: Metadata = {
    title: 'Cars - New',
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
            title: t('config.new'),
        },
    ];

    const title = `${t('cars.title')} - ${t('config.new')}`;

    return (
        <div>
            <UBreadcrumbs navigation={breadcrumbs} title={title} />
            <ComponentsCarCreate />
        </div>
    );
};
