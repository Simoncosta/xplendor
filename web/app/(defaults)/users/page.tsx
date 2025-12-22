import ComponentsCompanies from '@/components/companies/components-companies';
import UBreadcrumbs from '@/components/u-breadcrumbs';
import { Metadata } from 'next';
import React from 'react';
import { getTranslation } from '@/i18n';
import ComponentsFractions from '@/components/fractions/components-fractions';
import ComponentsUsers from '@/components/users/components-users';

export const metadata: Metadata = {
    title: 'Users',
};

export default async function FractionsPage({ params }: { params: { id: number } }) {

    const { t } = getTranslation();

    const breadcrumbs = [
        {
            href: '/',
            title: t('home.title'),
        },
        {
            href: '/condominiums',
            title: t('users.title'),
        },
    ];

    const title = t('users.title');

    return (
        <div>
            <UBreadcrumbs navigation={breadcrumbs} title={title} />
            <ComponentsUsers />
        </div>
    );
};
