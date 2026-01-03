import UBreadcrumbs from '@/components/u-breadcrumbs';
import { Metadata } from 'next';
import React from 'react';
import { getTranslation } from '@/i18n';
import ComponentsCarDetails from '@/components/cars/components/car-details/components-car-details';

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
            title: t('config.details'),
        },
    ];

    const title = `${t('cars.title')} - ${t('config.details')}`;

    return (
        <div>
            <UBreadcrumbs navigation={breadcrumbs} title={title} />
            <ComponentsCarDetails
                id={params.id}
            />
        </div>
    );
};
