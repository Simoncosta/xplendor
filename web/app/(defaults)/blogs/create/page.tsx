import { Metadata } from 'next';
import React from 'react';
import UBreadcrumbs from '@/components/u-breadcrumbs';
import { getTranslation } from '@/i18n';
import ComponentsBlogCreate from '@/components/blogs/components/blog-create/components-blog-create';

export const metadata: Metadata = {
    title: 'Newsletters - Edit',
};

export default async function BlogsPage() {
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
            title: t('config.new'),
        },
    ];

    const title = `${t('newsletters.title')} - ${t('config.new')}`;

    return (
        <div>
            <UBreadcrumbs navigation={breadcrumbs} title={title} />
            <ComponentsBlogCreate />
        </div>
    );
};
