import ComponentsCompanyEditor from '@/components/companies/components/company-editor/components-company-editor';
import ComponentsCompanyUpdate from '@/components/companies/components/company-update/components-company-update';
import UBreadcrumbs from '@/components/u-breadcrumbs';
import { Metadata } from 'next';
import React from 'react';
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
            title: t('config.edit'),
        },
    ];

    const title = `${t('companies.title')} - ${t('config.edit')}`;

    return (
        <div>
            <UBreadcrumbs navigation={breadcrumbs} title={title} />
            <ComponentsCompanyUpdate
                id={params.id}
            />
        </div>
    );
};
