import UBreadcrumbs from '@/components/u-breadcrumbs';
import { Metadata } from 'next';
import React from 'react';
import { getTranslation } from '@/i18n';
import ComponentsBlogUpdate from '@/components/blogs/components/blog-update/components-blog-update';

export const metadata: Metadata = {
    title: 'Newsletters - Edit',
};

export default async function BlogsPage({ params }: { params: { id: number } }) {
    const { t } = getTranslation();
    const breadcrumbs = [
        {
            href: '/',
            title: t('home.title'),
        },
        {
            href: `/newsletters`,
            title: t('newsletters.title'),
        },
        {
            href: '#',
            title: t('config.edit'),
        },
    ];

    const title = `${t('newsletters.title')} - ${t('config.edit')}`;

    return (
        <div>
            <UBreadcrumbs navigation={breadcrumbs} title={title} />
            <ComponentsBlogUpdate id={params.id} />
        </div>
    );
};
