import ComponentsCompanies from '@/components/companies/components-companies';
import UBreadcrumbs from '@/components/u-breadcrumbs';
import { Metadata } from 'next';
import React from 'react';
import { getTranslation } from '@/i18n';

export const metadata: Metadata = {
    title: 'Companies',
};

export default async function CompaniesPage() {
    const { t } = getTranslation();

    const breadcrumbs = [
        {
            href: '/',
            title: t('home.title'),
        },
        {
            href: '#',
            title: t('companies.title'),
        },
    ];

    const title = t('companies.title');

    return (
        <div>
            <UBreadcrumbs navigation={breadcrumbs} title={title} />
            <ComponentsCompanies />
        </div>
    );
};
