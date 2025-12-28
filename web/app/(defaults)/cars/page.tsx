import ComponentsCars from '@/components/cars/components-cars';
import UBreadcrumbs from '@/components/u-breadcrumbs';
import { Metadata } from 'next';
import React from 'react';
import { getTranslation } from '@/i18n';

export const metadata: Metadata = {
    title: 'Cars',
};

export default async function CarsPage() {
    const { t } = getTranslation();

    const breadcrumbs = [
        {
            href: '/',
            title: t('home.title'),
        },
        {
            href: '#',
            title: t('cars.title'),
        },
    ];

    const title = t('cars.title');

    return (
        <div>
            <UBreadcrumbs navigation={breadcrumbs} title={title} />
            <ComponentsCars />
        </div>
    );
};
