'use client';
import { useEffect, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import api from '@/services/axiosInstance';
import { PAGE_SIZES, TPageSize, UDatatable } from '../u-datatable';
import UButton from '../u-button';
import IconPlus from '../icon/icon-plus';
import { getTranslation } from '@/i18n';
import { useSelector } from 'react-redux';
import { IRootState } from '@/store';
import UBadges from '../u-badges';
import { ISubscribers } from './models/subscribers.model';
import { SubscribersService } from './services/subscribers.service';

const PER_PAGE = 15;

export default function ComponentsSubscribers() {
    const router = useRouter();
    const { t } = getTranslation();
    const user = useSelector((state: IRootState) => state.auth.user);

    // Context
    const subscribersService = useMemo(() => new SubscribersService(api, user.company_id), []);

    // States
    const [loading, setLoading] = useState<boolean>(true);
    const [subscribers, setSubscribers] = useState<ISubscribers[]>([]);

    //Paginação
    const [page, setPage] = useState<number>(1);
    const [pageSize, setPageSize] = useState<TPageSize>(PAGE_SIZES[0]);
    const [totalRecords, setTotalRecords] = useState<number>(0);

    useEffect(() => {
        async function fetchSubscribers() {
            setLoading(true);
            try {
                const res = await subscribersService.getSubscribers(PER_PAGE);
                const { data, total } = res;

                setSubscribers(data);
                setTotalRecords(total);
                setLoading(false);
            } catch (error) {
                console.error(error);
            } finally {
                setLoading(false);
            }
        }

        fetchSubscribers();
    }, [page, pageSize]);

    const columns = [
        { accessor: 'email', title: 'Email' },
        { accessor: 'name', title: t('grid.name') },
        {
            accessor: 'is_subscribed', title: t('subscriber.title'), render: (row: ISubscribers) => (
                <UBadges title={row.is_subscribed ? t('grid.yes') : t('grid.no')} />
            )
        },
    ]

    return (
        <div className='panel'>
            <UDatatable
                columns={columns}
                records={subscribers}
                onPageChange={(p: number) => setPage(p)}
                page={page}
                recordsPerPage={pageSize}
                fetching={loading}
                totalRecords={totalRecords}
                onSearchChange={(q) => {
                    setPage(1);
                }}
                onRecordsPerPageChange={(p: TPageSize) => setPageSize(p)}
            // toolbarButtons={toolbarButtons}
            // controls={{
            //     onEdit: handleEditRow,
            // }}
            />
        </div>
    );
}