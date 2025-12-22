import { Metadata } from 'next';
import React from 'react';
import UBreadcrumbs from '@/components/u-breadcrumbs';
import { getTranslation } from '@/i18n';
import ComponentsUserCreate from '@/components/users/components/user-create/components-user-create';

export const metadata: Metadata = {
    title: 'Users - Edit',
};

export default async function UsersPage() {
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
            title: t('config.new'),
        },
    ];

    const title = `${t('users.title')} - ${t('config.new')}`;

    return (
        <div>
            <UBreadcrumbs navigation={breadcrumbs} title={title} />
            <ComponentsUserCreate />
        </div>
    );
};
