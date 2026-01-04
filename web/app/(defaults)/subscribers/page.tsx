import UBreadcrumbs from '@/components/u-breadcrumbs';
import { Metadata } from 'next';
import React from 'react';
import { getTranslation } from '@/i18n';
import ComponentsSubscribers from '@/components/subscribers/components-subscribers';

export const metadata: Metadata = {
    title: 'Subscribers',
};

export default async function SubscribersPage({ params }: { params: { id: number } }) {

    const { t } = getTranslation();

    const breadcrumbs = [
        {
            href: '/',
            title: t('home.title'),
        },
        {
            href: '/subscribers',
            title: t('newsletters.subscribers'),
        },
    ];

    const title = t('newsletters.subscribers');

    return (
        <div>
            <UBreadcrumbs navigation={breadcrumbs} title={title} />
            <ComponentsSubscribers />
        </div>
    );
};
