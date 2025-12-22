'use client';
import IconLockDots from '@/components/icon/icon-lock-dots';
import { useRouter } from 'next/navigation';
import React, { useEffect, useState } from 'react';
import { useSelector, useDispatch } from 'react-redux';
import axiosInstance from '@/services/axiosInstance';
import { logout, unlockScreen } from '@/store/authSlice';
import Cookies from 'js-cookie';

const ComponentsAuthUnlockForm = () => {
    const router = useRouter();
    const dispatch = useDispatch();
    const user = useSelector((state: any) => state.auth.user);

    const [password, setPassword] = useState('');

    const submitForm = async (e: any) => {
        e.preventDefault();
        try {
            await axiosInstance.post('/v1/check-password', { password });
            dispatch(unlockScreen());
            router.push('/');
            router.refresh();
        } catch {
            alert("Senha incorreta");
        }
    };

    const handleLogout = () => {
        router.push('/auth/login');
    };

    return (
        <div className="mx-auto w-full max-w-[440px]">
            <div className="mb-10 flex items-center">
                <div className="flex h-16 w-16 items-end justify-center overflow-hidden rounded-full bg-[#00AB55] ltr:mr-4 rtl:ml-4">
                    <img src={
                        user.avatar
                            ? `${process.env.NEXT_PUBLIC_API_URL}/storage/${user.avatar}`
                            : "/assets/images/user-profile.jpeg"
                    } className="w-full object-cover" alt="images" />
                </div>
                <div className="flex-1">
                    <h4 className="text-2xl dark:text-white">{user.name}</h4>
                    <p className="text-white-dark">Enter your password to unlock your ID</p>
                </div>
            </div>
            <form className="space-y-5" onSubmit={submitForm}>
                <div>
                    <label htmlFor="Password" className="dark:text-white">
                        Password
                    </label>
                    <div className="relative text-white-dark">
                        <input
                            id="Password"
                            type="password"
                            placeholder="Enter Password"
                            className="form-input ps-10 placeholder:text-white-dark"
                            onChange={(e) => setPassword(e.target.value)}
                        />
                        <span className="absolute start-4 top-1/2 -translate-y-1/2">
                            <IconLockDots fill={true} />
                        </span>
                    </div>
                </div>
                <button type="submit" className="btn btn-dark !mt-6 w-full border-0 uppercase">
                    UNLOCK
                </button>
            </form>
            <button type="button" onClick={handleLogout} className="btn btn-danger !mt-6 w-full border-0 uppercase">
                LOGOUT
            </button>
        </div>
    );
};

export default ComponentsAuthUnlockForm;
