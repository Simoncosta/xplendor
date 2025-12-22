'use client';

import { useMemo } from "react";
import { useRouter } from "next/navigation";
import { getTranslation } from "@/i18n";
import api from "@/services/axiosInstance";
import { useSelector } from "react-redux";
import { IRootState } from "@/store";
import { toastSuccess } from "@/helpers/u-swal-alert";
import { UsersService } from "../../services/users.service";
import ComponentsUserEditor from "../user-editor/components-user-editor";

export default function ComponentsUserCreate() {

    const user = useSelector((state: IRootState) => state.auth.user);

    const { t } = getTranslation();
    const router = useRouter();

    const userService = useMemo(() => new UsersService(api, Number(user.company_id)), []);

    const defaultRecord = useMemo(() => {
        return userService.createUserDefault();
    }, []);

    return (
        <ComponentsUserEditor
            record={defaultRecord}
            onCancel={(cancel: boolean) => {
                if (cancel) {
                    router.push(`/users`);
                }
            }}
            onSave={async (data: any, onCancel: any) => {
                try {
                    const novo = await userService.saveUser(data);
                    onCancel(false);

                    toastSuccess(t('config.message.success'));

                    router.push(`/users`);
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