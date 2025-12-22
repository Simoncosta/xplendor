'use client';
import { IRootState } from '@/store';
import Link from 'next/link';
import React, { useEffect, useState } from 'react';
import { useSelector } from 'react-redux';
import UAlert from '../u-alert';

const ComponentsDashboardSales = () => {
    const { user } = useSelector((state: IRootState) => state.auth);

    return (
        <div>
            {/* {!!user && user.role !== 'user' && user.company_user.length === 0 && (
                <UAlert type='info'>
                    <strong>Nenhuma empresa cadastrada.</strong><br />
                    <Link href="/companies/create" className='underline'>
                        Clique aqui
                    </Link>
                    {" "}e cadastre uma empresa para começar a utilizar a plataforma.
                </UAlert>
            )} */}
        </div>
    );
};

export default ComponentsDashboardSales;
