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
import { BlogsService } from './services/blogs.service';
import { IBlogs } from './models/blog.model';
import { showDialog } from '@/helpers/u-swal-alert';

const PER_PAGE = 15;

export default function ComponentsBlogs() {
    const router = useRouter();
    const { t } = getTranslation();
    const user = useSelector((state: IRootState) => state.auth.user);

    // States
    const [blogs, setBlogs] = useState<IBlogs[]>([]);
    const [loading, setLoading] = useState<boolean>(true);

    // Services
    const blogService = useMemo(() => new BlogsService(api, user.company_id), []);

    //Paginação
    const [page, setPage] = useState<number>(1);
    const [pageSize, setPageSize] = useState<TPageSize>(PAGE_SIZES[0]);
    const [totalRecords, setTotalRecords] = useState<number>(0);

    const handleDeleteRow = (row: IBlogs) => {
        showDialog({
            title: `${t('config.delete')}?`,
            text: 'Você tem certeza que deseja excluir esse blog?',
            icon: 'warning',
            confirmButtonText: t('config.delete'),
            cancelButtonText: t('config.cancel'),
            showCancelButton: true,
        }).then(({ value }) => {
            if (value) {
                blogService.delete(Number(row.id));
                loadBlog();
            }
        });
    }

    async function loadBlog() {
        setLoading(true);
        try {
            const res = await blogService.getBlogs(PER_PAGE);
            const { data, total } = res;

            setBlogs(data);
            setTotalRecords(total);
            setLoading(false);
        } catch (error) {
            console.error(error);
        } finally {
            setLoading(false);
        }
    }
    useEffect(() => {
        loadBlog();
    }, [page, pageSize]);

    const toolbarButtons = useMemo(() => {
        return [
            <UButton className='w-max' isOutline isRounded type='button'
                icon={<IconPlus className='w-5 h-5' />}
                onClick={() => {
                    router.push(`/blogs/create`);
                }}>
                {t('config.new')}
            </UButton>,
        ]
    }, []);

    const handleEditRow = (row: IBlogs) => {
        router.push(`/blogs/${row.id}`);
    }

    const resolveImage = (img?: string) => {
        if (!img) return undefined;

        // se já for URL completa
        if (img.startsWith("http")) return img;

        // se for asset local do Next
        if (img.startsWith("/assets") || img.startsWith("/img")) return img;

        return `${process.env.NEXT_PUBLIC_API_URL}${img}`;
    }

    const columns = [
        {
            accessor: 'banner', title: t('grid.title'), render: (row: IBlogs) => (
                <div className='flex items-center gap-2'>
                    {
                        row.banner
                            ? <img className="w-12 h-8 rounded-md overflow-hidden object-cover" src={resolveImage(String(row.banner))} alt="img" />
                            : <span className="flex justify-center items-center w-8 h-8 text-center rounded-full object-cover bg-dark text-base text-white">
                                {row.title.slice(0, 2).toUpperCase()}
                            </span>
                    }
                    <span>{row.title}</span>
                </div>
            )
        },
        { accessor: 'category', title: t('grid.category') },
        { accessor: 'read_time', title: t('grid.read_time'), render: (row: IBlogs) => row.read_time ? `${row.read_time} ${t('grid.minutes')}` : null },
        {
            accessor: 'tags', title: 'Tags', render: (row: IBlogs) => (
                row.tags?.map((tag: string) => <UBadges className="mr-1" key={tag} title={tag} />)
            )
        },
        {
            accessor: 'status', title: 'Status', render: (row: IBlogs) => (
                <UBadges badge={row.status === 'draft' ? 'warning' : 'success'} title={t(`grid.blog.status.${row.status}`) ?? '-'} />
            )
        }
    ]

    return (
        <div className='panel'>
            <UDatatable
                key={blogs.length}
                columns={columns}
                records={blogs}
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
                    onDelete: handleDeleteRow,
                }}
            />
        </div>
    );
}