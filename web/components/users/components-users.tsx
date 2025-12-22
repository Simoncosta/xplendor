'use client';
import { useEffect, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import api from '@/services/axiosInstance';
import { PAGE_SIZES, TPageSize, UDatatable } from '../u-datatable';
import { IUsers } from './models/user.model';
import UButton from '../u-button';
import IconPlus from '../icon/icon-plus';
import { getTranslation } from '@/i18n';
import { UsersService } from './services/users.service';
import { useSelector } from 'react-redux';
import { IRootState } from '@/store';
import UBadges from '../u-badges';

const PER_PAGE = 15;

export default function ComponentsUsers() {
    const router = useRouter();
    const { t } = getTranslation();
    const user = useSelector((state: IRootState) => state.auth.user);

    // States
    const [users, setUsers] = useState<IUsers[]>([]);
    const [loading, setLoading] = useState<boolean>(true);

    // Services
    const usersService = useMemo(() => new UsersService(api, user.company_id), []);

    //Paginação
    const [page, setPage] = useState<number>(1);
    const [pageSize, setPageSize] = useState<TPageSize>(PAGE_SIZES[0]);
    const [totalRecords, setTotalRecords] = useState<number>(0);

    useEffect(() => {
        async function loadFractions() {
            setLoading(true);
            try {
                const res = await usersService.getUsers(PER_PAGE);
                const { data, total } = res;

                setUsers(data);
                setTotalRecords(total);
                setLoading(false);
            } catch (error) {
                console.error(error);
            } finally {
                setLoading(false);
            }
        }

        loadFractions();
    }, [page, pageSize]);

    const toolbarButtons = useMemo(() => {
        return [
            <UButton className='w-max' isOutline isRounded type='button'
                icon={<IconPlus className='w-5 h-5' />}
                onClick={() => {
                    router.push(`/users/create`);
                }}>
                {t('config.new')}
            </UButton>,
        ]
    }, []);

    const handleEditRow = (row: IUsers) => {
        router.push(`/users/${row.id}`);
    }

    const resolveImage = (img?: string) => {
        if (!img) return undefined;

        // se já for URL completa
        if (img.startsWith("http")) return img;

        // se for asset local do Next
        if (img.startsWith("/assets") || img.startsWith("/img")) return img;

        return `${process.env.NEXT_PUBLIC_API_URL}/storage/${img}`;
    }

    const columns = [
        {
            accessor: 'name', title: t('grid.name'), render: (row: IUsers) => (
                <div className='flex items-center gap-2'>
                    {
                        row.avatar
                            ? <img className="w-8 h-8 rounded-full overflow-hidden object-cover" src={resolveImage(String(row.avatar))} alt="img" />
                            : <span className="flex justify-center items-center w-8 h-8 text-center rounded-full object-cover bg-dark text-base text-white">
                                {row.name.slice(0, 2).toUpperCase()}
                            </span>
                    }
                    <span>{row.name}</span>
                </div>
            )
        },
        { accessor: 'email', title: 'Email' },
        { accessor: 'gender', title: t('grid.gender'), render: (row: IUsers) => !!row.gender ? t(`grid.gender.${row.gender}`) : null },
        {
            accessor: 'role', title: t('grid.role'), render: (row: IUsers) => (
                <UBadges title={t(`grid.role.${row.role}`) ?? '-'} />
            )
        }
    ]

    return (
        <div className='panel'>
            <UDatatable
                columns={columns}
                records={users}
                onPageChange={(p: number) => setPage(p)}
                page={page}
                recordsPerPage={pageSize}
                fetching={loading}
                totalRecords={totalRecords}
                onSearchChange={(q) => {
                    setPage(1);
                }}
                onRecordsPerPageChange={(p: TPageSize) => setPageSize(p)}
                toolbarButtons={toolbarButtons}
                controls={{
                    onEdit: handleEditRow,
                }}
            />
        </div>
    );
}