import UBreadcrumbs from '@/components/u-breadcrumbs';
import { Metadata } from 'next';
import React from 'react';
import { getTranslation } from '@/i18n';
import ComponentsUserUpdate from '@/components/users/components/user-update/components-user-update';

export const metadata: Metadata = {
    title: 'Users - Edit',
};

export default async function UsersPage({ params }: { params: { id: number } }) {
    const { t } = getTranslation();
    const breadcrumbs = [
        {
            href: '/',
            title: t('home.title'),
        },
        {
            href: `/users`,
            title: t('users.title'),
        },
        {
            href: '#',
            title: t('config.edit'),
        },
    ];

    const title = `${t('users.title')} - ${t('config.edit')}`;

    return (
        <div>
            <UBreadcrumbs navigation={breadcrumbs} title={title} />
            <ComponentsUserUpdate
                id={params.id}
            />
        </div>
    );
};
