'use client';
import { CompanyService } from '@/components/companies/services/companies.service';
import { useEffect, useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import api from '@/services/axiosInstance';
import { PAGE_SIZES, TPageSize, UDatatable } from '../u-datatable';
import { ICompany } from './models/companies.model';
import UButton from '../u-button';
import IconPlus from '../icon/icon-plus';
import { getTranslation } from '@/i18n';
import { useSelector } from 'react-redux';
import { IRootState } from '@/store';
import { toastSuccess } from '@/helpers/u-swal-alert';
import Swal from 'sweetalert2';

const PER_PAGE = 15;

export default function ComponentsCompanies() {
    const router = useRouter();
    const { t } = getTranslation();

    // Context
    const user = useSelector((state: IRootState) => state.auth.user);

    const [companies, setCompanies] = useState<ICompany[]>([]);
    const [loading, setLoading] = useState<boolean>(false);

    const companyService = useMemo(() => new CompanyService(api), []);

    //Paginação
    const [page, setPage] = useState<number>(1);
    const [pageSize, setPageSize] = useState<TPageSize>(PAGE_SIZES[0]);
    const [totalRecords, setTotalRecords] = useState<number>(0);

    useEffect(() => {
        loadCompanies();
    }, [page, pageSize]);

    async function loadCompanies() {
        try {
            const res = await companyService.getCompanies(PER_PAGE);
            const { data, total } = res;

            setCompanies(data);
            setTotalRecords(total);
            setLoading(false);
        } catch (error) {
            console.error(error);
        } finally {
            setLoading(false);
        }
    }

    const toolbarButtons = useMemo(() => {
        if (user.role !== 'root') return [];

        return [
            <UButton className='w-max' isOutline isRounded type='button'
                icon={<IconPlus className='w-5 h-5' />}
                onClick={() => {
                    router.push('/companies/create');
                }}>
                {t('config.new')}
            </UButton>,
        ]
    }, [companies]);

    const handleEditRow = (row: ICompany) => {
        router.push(`/companies/${row.id}`);
    }

    const handleRemoveRow = (row: ICompany) => {
        try {
            Swal.fire({
                title: 'Excluir empresa',
                text: 'Você tem certeza que deseja excluir esta empresa?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Sim',
            }).then(async (result) => {
                if (result.isConfirmed) {
                    await companyService.removeCompany(row.id);
                    toastSuccess('Empresa excluída com sucesso.');
                    setPage(1);
                    loadCompanies();
                }
            });
        } catch (error) {

        }
    }

    const columns = [
        { accessor: 'fiscal_name', title: t('grid.name') },
        { accessor: 'nipc', title: 'NIF' },
        { accessor: 'email', title: 'Email' },
    ]

    return (
        <div className='panel'>
            <UDatatable
                columns={columns}
                records={companies}
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
                    // Não tem recurso na API para remover um cliente/fornecedor no momento.
                    onDelete: handleRemoveRow,
                }}
            />
        </div>
    );
}