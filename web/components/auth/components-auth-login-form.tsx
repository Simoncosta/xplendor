'use client';
import React from 'react';
import { useForm } from 'react-hook-form';
import { useDispatch, useSelector } from 'react-redux';
import { useRouter } from 'next/navigation';
import IconLockDots from '@/components/icon/icon-lock-dots';
import IconMail from '@/components/icon/icon-mail';
import { AppDispatch, IRootState } from '@/store';
import { fetchMe, login } from '@/store/authSlice';

const ComponentsAuthLoginForm = () => {
    const { register, handleSubmit } = useForm();
    const dispatch = useDispatch<AppDispatch>();
    const router = useRouter();

    const { loading, error } = useSelector((state: IRootState) => state.auth);

    const submit = async (data: any) => {
        const { email, password } = data;

        const result = await dispatch(login({ email, password }));
        await dispatch(fetchMe(result.payload.data));

        if (login.fulfilled.match(result)) {
            router.push('/');
        }
    };

    return (
        <form className="space-y-5 dark:text-white" onSubmit={handleSubmit(submit)}>
            <div>
                <label htmlFor="Email">Email</label>
                <div className="relative text-white-dark">
                    <input
                        id="Email"
                        type="email"
                        placeholder="Enter Email"
                        className="form-input ps-10 placeholder:text-white-dark"
                        {...register('email')}
                    />
                    <span className="absolute start-4 top-1/2 -translate-y-1/2">
                        <IconMail fill={true} />
                    </span>
                </div>
            </div>
            <div>
                <label htmlFor="Password">Password</label>
                <div className="relative text-white-dark">
                    <input
                        id="Password"
                        type="password"
                        placeholder="Enter Password"
                        className="form-input ps-10 placeholder:text-white-dark"
                        {...register('password')}
                    />
                    <span className="absolute start-4 top-1/2 -translate-y-1/2">
                        <IconLockDots fill={true} />
                    </span>
                </div>
            </div>
            {/* <div>
                <label className="flex cursor-pointer items-center">
                    <input type="checkbox" className="form-checkbox bg-white dark:bg-black" />
                    <span className="text-white-dark">Subscribe to weekly newsletter</span>
                </label>
            </div> */}
            <button type="submit" className="btn btn-dark !mt-6 w-full border-0 uppercase">
                {loading ? 'Entrando...' : 'Entrar'}
            </button>
            {error && <p style={{ color: 'red' }}>{error}</p>}
        </form>
    );
};

export default ComponentsAuthLoginForm;
