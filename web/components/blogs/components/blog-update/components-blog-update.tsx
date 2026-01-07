'use client';

import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { toastError, toastSuccess } from "@/helpers/u-swal-alert";
import { getTranslation } from '@/i18n';
import api from "@/services/axiosInstance";
import { useSelector } from "react-redux";
import { IRootState } from "@/store";
import { IBlogs } from "../../models/blog.model";
import { BlogsService } from "../../services/blogs.service";
import ComponentsBlogEditor from "../blog-editor/components-blog-editor";

export default function ComponentsBlogUpdate({ id }: { id: number }) {

    const router = useRouter();
    const { t } = getTranslation();

    const user = useSelector((state: IRootState) => state.auth.user);

    // Memo
    const blogService = useMemo(() => new BlogsService(api, user.company_id), []);

    const [blogData, setBlogData] = useState<IBlogs>();

    useEffect(() => {
        const getContact = async () => {
            const blogData = await blogService.getBlog(Number(id));
            setBlogData(blogData);
        }

        getContact();
    }, [id])

    return (
        <ComponentsBlogEditor
            record={blogData}
            onCancel={(cancel: boolean) => {
                if (cancel) {
                    router.push(`/users`);
                }
            }}
            onSave={async (data, onCancel) => {
                try {
                    await blogService.update(Number(id), data);
                    onCancel(false);

                    toastSuccess(t('config.message.success'))
                } catch (err) {
                    onCancel(true);
                    if (err instanceof Error) {
                        toastError(t('config.message.success'));
                    }
                }
            }}
        />
    );
}
