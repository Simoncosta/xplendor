import { Metadata } from 'next';
import React from 'react';
import ComponentsCompanyCreate from '@/components/companies/components/company-create/components-company-create';
import UBreadcrumbs from '@/components/u-breadcrumbs';
import { getTranslation } from '@/i18n';

export const metadata: Metadata = {
    title: 'Companies - Edit',
};

export default async function CompaniesPage({ params }: { params: { id: string } }) {
    const { t } = getTranslation();
    const breadcrumbs = [
        {
            href: '/',
            title: t('home.title'),
        },
        {
            href: '/companies',
            title: t('companies.title'),
        },
        {
            href: '#',
            title: t('config.new'),
        },
    ];

    const title = `${t('companies.title')} - ${t('config.new')}`;

    return (
        <div>
            <UBreadcrumbs navigation={breadcrumbs} title={title} />
            <ComponentsCompanyCreate />
        </div>
    );
};
