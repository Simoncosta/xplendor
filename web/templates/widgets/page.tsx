import React from 'react';
import ComponentsWidgets from '@/components/components-widgets';
import { Metadata } from 'next';

export const metadata: Metadata = {
    title: 'Widgets',
};

const Widgets = () => {
    return <ComponentsWidgets />;
};

export default Widgets; 
