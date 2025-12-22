'use client';

import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { toastError, toastSuccess } from "@/helpers/u-swal-alert";
import { getTranslation } from '@/i18n';
import api from "@/services/axiosInstance";
import { useSelector } from "react-redux";
import { IRootState } from "@/store";
import { UsersService } from "../../services/users.service";
import { IUsers } from "../../models/user.model";
import ComponentsUserEditor from "../user-editor/components-user-editor";

export default function ComponentsUserUpdate({ id }: { id: number }) {

    const router = useRouter();
    const { t } = getTranslation();

    const user = useSelector((state: IRootState) => state.auth.user);

    const userService = useMemo(() => new UsersService(api, user.company_id), []);

    const [userData, setUserData] = useState<IUsers>();

    useEffect(() => {
        const getContact = async () => {
            const userData = await userService.getUser(Number(id));
            setUserData(userData);
        }

        getContact();
    }, [id])

    return (
        <ComponentsUserEditor
            record={userData}
            onCancel={(cancel: boolean) => {
                if (cancel) {
                    router.push(`/users`);
                }
            }}
            onSave={async (data, onCancel) => {
                try {
                    await userService.updateUser(Number(id), data);
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
