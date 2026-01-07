import UBreadcrumbs from '@/components/u-breadcrumbs';
import { Metadata } from 'next';
import React from 'react';
import { getTranslation } from '@/i18n';
import ComponentsBlogs from '@/components/blogs/components-blogs';

export const metadata: Metadata = {
    title: 'Newsletters',
};

export default async function BlogsPage({ params }: { params: { id: number } }) {

    const { t } = getTranslation();

    const breadcrumbs = [
        {
            href: '/',
            title: t('home.title'),
        },
        {
            href: '/condominiums',
            title: t('newsletters.title'),
        },
    ];

    const title = t('newsletters.title');

    return (
        <div>
            <UBreadcrumbs navigation={breadcrumbs} title={title} />
            <ComponentsBlogs />
        </div>
    );
};
