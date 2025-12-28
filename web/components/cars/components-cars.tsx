'use client';

import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { useSelector } from "react-redux";
import { getTranslation } from "@/i18n";
import { IRootState } from "@/store";
import { PAGE_SIZES, TPageSize, UDatatable } from "../u-datatable";
import UButton from "../u-button";
import IconPlus from "../icon/icon-plus";
import { CarService } from "./services/cars.service";
import api from "@/services/axiosInstance";
import { ICar } from "./models/cars.model";
import UBadges from "../u-badges";
import ComponentsCarExpansion from "./components/car-expansion/components-car-expansion";

const PER_PAGE = 15;

export default function CarsCompanies() {
    const router = useRouter();
    const { t } = getTranslation();

    // Context
    const user = useSelector((state: IRootState) => state.auth.user);

    // Service
    const carService = useMemo(() => new CarService(api, user.company_id), []);

    //Paginação
    const [page, setPage] = useState<number>(1);
    const [pageSize, setPageSize] = useState<TPageSize>(PAGE_SIZES[0]);
    const [totalRecords, setTotalRecords] = useState<number>(0);

    // State
    const [loading, setLoading] = useState<boolean>(false);
    const [cars, setCars] = useState<ICar[]>([]);

    useEffect(() => {
        loadCars();
    }, [page, pageSize]);

    async function loadCars() {
        try {
            const res = await carService.getCars(PER_PAGE);
            const { data, total } = res;

            setCars(data);
            setTotalRecords(total);
            setLoading(false);
        } catch (error) {
            console.error(error);
        } finally {
            setLoading(false);
        }
    }

    const handleEditRow = (row: ICar) => {
        router.push(`/cars/${row.id}`);
    }

    const toolbarButtons = useMemo(() => {
        if (user.role !== 'root') return [];

        return [
            <UButton className='w-max' isOutline isRounded type='button'
                icon={<IconPlus className='w-5 h-5' />}
                onClick={() => {
                    router.push('/cars/create');
                }}>
                {t('config.new')}
            </UButton>,
        ]
    }, []);

    const columns = [
        {
            accessor: 'model', title: t('grid.model'), render: (result: ICar) => (
                <div className="flex items-center">
                    <span className="font-semibold text-base">{result.brand.name} {result.model.name}</span>
                    <span className="ml-1">{result.version}</span>
                </div>
            )
        },
        { accessor: 'mileage_km', title: 'Kms', render: (result: ICar) => new Intl.NumberFormat('pt-PT').format(result.mileage_km ?? 0) },
        {
            accessor: 'price_gross', title: t('grid.price'), render: (result: ICar) => (
                new Intl.NumberFormat('pt-PT', {
                    style: 'currency',
                    currency: 'EUR',
                    minimumFractionDigits: 2,
                }).format(Number(result.price_gross) ?? 0)
            )
        },
        { accessor: 'status', title: 'Status', render: (result: ICar) => <UBadges title={t(`cars.status.${result.status}`)} /> },
    ];

    return (
        <div className='panel'>
            <UDatatable
                columns={columns}
                records={cars}
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
                    // onDelete: handleRemoveRow,
                }}
                rowExpansion={{
                    content: ({ record }) => <ComponentsCarExpansion data={record} />
                }}
            />
        </div>
    );
}