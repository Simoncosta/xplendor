'use client';

import { useMemo } from "react";
import { useRouter } from "next/navigation";
import { getTranslation } from "@/i18n";
import api from "@/services/axiosInstance";
import { useSelector } from "react-redux";
import { IRootState } from "@/store";
import { toastSuccess } from "@/helpers/u-swal-alert";
import { BlogsService } from "../../services/blogs.service";
import ComponentsBlogEditor from "../blog-editor/components-blog-editor";

export default function ComponentsBlogCreate() {

    const user = useSelector((state: IRootState) => state.auth.user);

    const { t } = getTranslation();
    const router = useRouter();

    const blogService = useMemo(() => new BlogsService(api, Number(user.company_id)), []);

    const defaultRecord = useMemo(() => {
        return blogService.createBlogDefault();
    }, []);

    return (
        <ComponentsBlogEditor
            record={defaultRecord}
            onCancel={(cancel: boolean) => {
                if (cancel) {
                    router.push(`/blogs`);
                }
            }}
            onSave={async (data: any, onCancel: any) => {
                try {
                    const novo = await blogService.store(data);
                    onCancel(false);

                    toastSuccess(t('config.message.success'));

                    router.push(`/blogs`);
                } catch (err) {
                    onCancel(true);
                    if (err instanceof Error) {
                        // toastError(t('config.message.success'));
                    }
                }
            }}
        />
    );
}