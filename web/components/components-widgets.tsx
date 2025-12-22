'use client';
import Dropdown from '@/components/dropdown';
import IconArrowLeft from '@/components/icon/icon-arrow-left';
import IconBolt from '@/components/icon/icon-bolt';
import IconCaretDown from '@/components/icon/icon-caret-down';
import IconCaretsDown from '@/components/icon/icon-carets-down';
import IconCashBanknotes from '@/components/icon/icon-cash-banknotes';
import IconChatDots from '@/components/icon/icon-chat-dots';
import IconChecks from '@/components/icon/icon-checks';
import IconChrome from '@/components/icon/icon-chrome';
import IconClock from '@/components/icon/icon-clock';
import IconCreditCard from '@/components/icon/icon-credit-card';
import IconDollarSign from '@/components/icon/icon-dollar-sign';
import IconFile from '@/components/icon/icon-file';
import IconGlobe from '@/components/icon/icon-globe';
import IconHorizontalDots from '@/components/icon/icon-horizontal-dots';
import IconInbox from '@/components/icon/icon-inbox';
import IconLink from '@/components/icon/icon-link';
import IconMail from '@/components/icon/icon-mail';
import IconMultipleForwardRight from '@/components/icon/icon-multiple-forward-right';
import IconNetflix from '@/components/icon/icon-netflix';
import IconPlus from '@/components/icon/icon-plus';
import IconSafari from '@/components/icon/icon-safari';
import IconServer from '@/components/icon/icon-server';
import IconShoppingCart from '@/components/icon/icon-shopping-cart';
import IconSquareCheck from '@/components/icon/icon-square-check';
import IconTag from '@/components/icon/icon-tag';
import IconThumbUp from '@/components/icon/icon-thumb-up';
import IconTrendingUp from '@/components/icon/icon-trending-up';
import IconUser from '@/components/icon/icon-user';
import IconUsersGroup from '@/components/icon/icon-users-group';
import { IRootState } from '@/store';
import Link from 'next/link';
import React, { useEffect, useState } from 'react';
import ReactApexChart from 'react-apexcharts';
import { useSelector } from 'react-redux';
import PerfectScrollbar from 'react-perfect-scrollbar';

const ComponentsWidgets = () => {
    const isDark = useSelector((state: IRootState) => state.themeConfig.theme === 'dark' || state.themeConfig.isDarkMode);
    const isRtl = useSelector((state: IRootState) => state.themeConfig.rtlClass) === 'rtl' ? true : false;

    const [isMounted, setIsMounted] = useState(false);
    useEffect(() => {
        setIsMounted(true);
    });

    //Revenue Chart
    const revenueChart: any = {
        series: [
            {
                name: 'Income',
                data: [16800, 16800, 15500, 17800, 15500, 17000, 19000, 16000, 15000, 17000, 14000, 17000],
            },
            {
                name: 'Expenses',
                data: [16500, 17500, 16200, 17300, 16000, 19500, 16000, 17000, 16000, 19000, 18000, 19000],
            },
        ],
        options: {
            chart: {
                height: 325,
                type: 'area',
                fontFamily: 'Nunito, sans-serif',
                zoom: {
                    enabled: false,
                },
                toolbar: {
                    show: false,
                },
            },

            dataLabels: {
                enabled: false,
            },
            stroke: {
                show: true,
                curve: 'smooth',
                width: 2,
                lineCap: 'square',
            },
            dropShadow: {
                enabled: true,
                opacity: 0.2,
                blur: 10,
                left: -7,
                top: 22,
            },
            colors: isDark ? ['#2196F3', '#E7515A'] : ['#1B55E2', '#E7515A'],
            markers: {
                discrete: [
                    {
                        seriesIndex: 0,
                        dataPointIndex: 6,
                        fillColor: '#1B55E2',
                        strokeColor: 'transparent',
                        size: 7,
                    },
                    {
                        seriesIndex: 1,
                        dataPointIndex: 5,
                        fillColor: '#E7515A',
                        strokeColor: 'transparent',
                        size: 7,
                    },
                ],
            },
            labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            xaxis: {
                axisBorder: {
                    show: false,
                },
                axisTicks: {
                    show: false,
                },
                crosshairs: {
                    show: true,
                },
                labels: {
                    offsetX: isRtl ? 2 : 0,
                    offsetY: 5,
                    style: {
                        fontSize: '12px',
                        cssClass: 'apexcharts-xaxis-title',
                    },
                },
            },
            yaxis: {
                tickAmount: 7,
                labels: {
                    formatter: (value: number) => {
                        return value / 1000 + 'K';
                    },
                    offsetX: isRtl ? -30 : -10,
                    offsetY: 0,
                    style: {
                        fontSize: '12px',
                        cssClass: 'apexcharts-yaxis-title',
                    },
                },
                opposite: isRtl ? true : false,
            },
            grid: {
                borderColor: isDark ? '#191E3A' : '#E0E6ED',
                strokeDashArray: 5,
                xaxis: {
                    lines: {
                        show: false,
                    },
                },
                yaxis: {
                    lines: {
                        show: true,
                    },
                },
                padding: {
                    top: 0,
                    right: 0,
                    bottom: 0,
                    left: 0,
                },
            },
            legend: {
                position: 'top',
                horizontalAlign: 'right',
                fontSize: '16px',
                markers: {
                    width: 10,
                    height: 10,
                    offsetX: -2,
                },
                itemMargin: {
                    horizontal: 10,
                    vertical: 5,
                },
            },
            tooltip: {
                marker: {
                    show: true,
                },
                x: {
                    show: false,
                },
            },
            fill: {
                type: 'gradient',
                gradient: {
                    shadeIntensity: 1,
                    inverseColors: !1,
                    opacityFrom: isDark ? 0.19 : 0.28,
                    opacityTo: 0.05,
                    stops: isDark ? [100, 100] : [45, 100],
                },
            },
        },
    };

    //Sales By Category
    const salesByCategory: any = {
        series: [985, 737, 270],
        options: {
            chart: {
                type: 'donut',
                height: 460,
                fontFamily: 'Nunito, sans-serif',
            },
            dataLabels: {
                enabled: false,
            },
            stroke: {
                show: true,
                width: 25,
                colors: isDark ? '#0e1726' : '#fff',
            },
            colors: isDark ? ['#5c1ac3', '#e2a03f', '#e7515a', '#e2a03f'] : ['#e2a03f', '#5c1ac3', '#e7515a'],
            legend: {
                position: 'bottom',
                horizontalAlign: 'center',
                fontSize: '14px',
                markers: {
                    width: 10,
                    height: 10,
                    offsetX: -2,
                },
                height: 50,
                offsetY: 20,
            },
            plotOptions: {
                pie: {
                    donut: {
                        size: '65%',
                        background: 'transparent',
                        labels: {
                            show: true,
                            name: {
                                show: true,
                                fontSize: '29px',
                                offsetY: -10,
                            },
                            value: {
                                show: true,
                                fontSize: '26px',
                                color: isDark ? '#bfc9d4' : undefined,
                                offsetY: 16,
                                formatter: (val: any) => {
                                    return val;
                                },
                            },
                            total: {
                                show: true,
                                label: 'Total',
                                color: '#888ea8',
                                fontSize: '29px',
                                formatter: (w: any) => {
                                    return w.globals.seriesTotals.reduce(function (a: any, b: any) {
                                        return a + b;
                                    }, 0);
                                },
                            },
                        },
                    },
                },
            },
            labels: ['Apparel', 'Sports', 'Others'],
            states: {
                hover: {
                    filter: {
                        type: 'none',
                        value: 0.15,
                    },
                },
                active: {
                    filter: {
                        type: 'none',
                        value: 0.15,
                    },
                },
            },
        },
    };

    //Daily Sales
    const dailySales: any = {
        series: [
            {
                name: 'Sales',
                data: [44, 55, 41, 67, 22, 43, 21],
            },
            {
                name: 'Last Week',
                data: [13, 23, 20, 8, 13, 27, 33],
            },
        ],
        options: {
            chart: {
                height: 160,
                type: 'bar',
                fontFamily: 'Nunito, sans-serif',
                toolbar: {
                    show: false,
                },
                stacked: true,
                stackType: '100%',
            },
            dataLabels: {
                enabled: false,
            },
            stroke: {
                show: true,
                width: 1,
            },
            colors: ['#e2a03f', '#e0e6ed'],
            responsive: [
                {
                    breakpoint: 480,
                    options: {
                        legend: {
                            position: 'bottom',
                            offsetX: -10,
                            offsetY: 0,
                        },
                    },
                },
            ],
            xaxis: {
                labels: {
                    show: false,
                },
                categories: ['Sun', 'Mon', 'Tue', 'Wed', 'Thur', 'Fri', 'Sat'],
            },
            yaxis: {
                show: false,
            },
            fill: {
                opacity: 1,
            },
            plotOptions: {
                bar: {
                    horizontal: false,
                    columnWidth: '25%',
                },
            },
            legend: {
                show: false,
            },
            grid: {
                show: false,
                xaxis: {
                    lines: {
                        show: false,
                    },
                },
                padding: {
                    top: 10,
                    right: -20,
                    bottom: -20,
                    left: -20,
                },
            },
        },
    };

    //Total Orders
    const totalOrders: any = {
        series: [
            {
                name: 'Sales',
                data: [28, 40, 36, 52, 38, 60, 38, 52, 36, 40],
            },
        ],
        options: {
            chart: {
                height: 290,
                type: 'area',
                fontFamily: 'Nunito, sans-serif',
                sparkline: {
                    enabled: true,
                },
            },
            stroke: {
                curve: 'smooth',
                width: 2,
            },
            colors: isDark ? ['#00ab55'] : ['#00ab55'],
            labels: ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10'],
            yaxis: {
                min: 0,
                show: false,
            },
            grid: {
                padding: {
                    top: 125,
                    right: 0,
                    bottom: 0,
                    left: 0,
                },
            },
            fill: {
                opacity: 1,
                type: 'gradient',
                gradient: {
                    type: 'vertical',
                    shadeIntensity: 1,
                    inverseColors: !1,
                    opacityFrom: 0.3,
                    opacityTo: 0.05,
                    stops: [100, 100],
                },
            },
            tooltip: {
                x: {
                    show: false,
                },
            },
        },
    };

    // totalVisitOptions
    const totalVisit: any = {
        series: [{ data: [21, 9, 36, 12, 44, 25, 59, 41, 66, 25] }],
        options: {
            chart: {
                height: 58,
                type: 'line',
                fontFamily: 'Nunito, sans-serif',
                sparkline: {
                    enabled: true,
                },
                dropShadow: {
                    enabled: true,
                    blur: 3,
                    color: '#009688',
                    opacity: 0.4,
                },
            },
            stroke: {
                curve: 'smooth',
                width: 2,
            },
            colors: ['#009688'],
            grid: {
                padding: {
                    top: 5,
                    bottom: 5,
                    left: 5,
                    right: 5,
                },
            },
            tooltip: {
                x: {
                    show: false,
                },
                y: {
                    title: {
                        formatter: () => {
                            return '';
                        },
                    },
                },
            },
        },
    };
    // paidVisitOptions
    const paidVisit: any = {
        series: [{ data: [22, 19, 30, 47, 32, 44, 34, 55, 41, 69] }],
        options: {
            chart: {
                height: 58,
                type: 'line',
                fontFamily: 'Nunito, sans-serif',
                sparkline: {
                    enabled: true,
                },
                dropShadow: {
                    enabled: true,
                    blur: 3,
                    color: '#e2a03f',
                    opacity: 0.4,
                },
            },
            stroke: {
                curve: 'smooth',
                width: 2,
            },
            colors: ['#e2a03f'],
            grid: {
                padding: {
                    top: 5,
                    bottom: 5,
                    left: 5,
                    right: 5,
                },
            },
            tooltip: {
                x: {
                    show: false,
                },
                y: {
                    title: {
                        formatter: () => {
                            return '';
                        },
                    },
                },
            },
        },
    };
    // uniqueVisitorSeriesOptions
    const uniqueVisitorSeries: any = {
        series: [
            {
                name: 'Direct',
                data: [58, 44, 55, 57, 56, 61, 58, 63, 60, 66, 56, 63],
            },
            {
                name: 'Organic',
                data: [91, 76, 85, 101, 98, 87, 105, 91, 114, 94, 66, 70],
            },
        ],
        options: {
            chart: {
                height: 360,
                type: 'bar',
                fontFamily: 'Nunito, sans-serif',
                toolbar: {
                    show: false,
                },
            },
            dataLabels: {
                enabled: false,
            },
            stroke: {
                width: 2,
                colors: ['transparent'],
            },
            colors: ['#5c1ac3', '#ffbb44'],
            dropShadow: {
                enabled: true,
                blur: 3,
                color: '#515365',
                opacity: 0.4,
            },
            plotOptions: {
                bar: {
                    horizontal: false,
                    columnWidth: '55%',
                    borderRadius: 8,
                    borderRadiusApplication: 'end',
                },
            },
            legend: {
                position: 'bottom',
                horizontalAlign: 'center',
                fontSize: '14px',
                itemMargin: {
                    horizontal: 8,
                    vertical: 8,
                },
            },
            grid: {
                borderColor: isDark ? '#191e3a' : '#e0e6ed',
                padding: {
                    left: 20,
                    right: 20,
                },
            },
            xaxis: {
                categories: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                axisBorder: {
                    show: true,
                    color: isDark ? '#3b3f5c' : '#e0e6ed',
                },
            },
            yaxis: {
                tickAmount: 6,
                opposite: isRtl ? true : false,
                labels: {
                    offsetX: isRtl ? -10 : 0,
                },
            },
            fill: {
                type: 'gradient',
                gradient: {
                    shade: isDark ? 'dark' : 'light',
                    type: 'vertical',
                    shadeIntensity: 0.3,
                    inverseColors: false,
                    opacityFrom: 1,
                    opacityTo: 0.8,
                    stops: [0, 100],
                },
            },
            tooltip: {
                marker: {
                    show: true,
                },
            },
        },
    };
    // followersOptions
    const followers: any = {
        series: [
            {
                data: [38, 60, 38, 52, 36, 40, 28],
            },
        ],
        options: {
            chart: {
                height: 160,
                type: 'area',
                fontFamily: 'Nunito, sans-serif',
                sparkline: {
                    enabled: true,
                },
            },
            stroke: {
                curve: 'smooth',
                width: 2,
            },
            colors: ['#4361ee'],
            grid: {
                padding: {
                    top: 5,
                },
            },
            yaxis: {
                show: false,
            },
            tooltip: {
                x: {
                    show: false,
                },
                y: {
                    title: {
                        formatter: () => {
                            return '';
                        },
                    },
                },
            },
        },
    };
    // referralOptions
    const referral: any = {
        series: [
            {
                data: [60, 28, 52, 38, 40, 36, 38],
            },
        ],
        options: {
            chart: {
                height: 160,
                type: 'area',
                fontFamily: 'Nunito, sans-serif',
                sparkline: {
                    enabled: true,
                },
            },
            stroke: {
                curve: 'smooth',
                width: 2,
            },
            colors: ['#e7515a'],
            grid: {
                padding: {
                    top: 5,
                },
            },
            yaxis: {
                show: false,
            },
            tooltip: {
                x: {
                    show: false,
                },
                y: {
                    title: {
                        formatter: () => {
                            return '';
                        },
                    },
                },
            },
        },
    };
    // engagementOptions
    const engagement: any = {
        series: [
            {
                name: 'Sales',
                data: [28, 50, 36, 60, 38, 52, 38],
            },
        ],
        options: {
            chart: {
                height: 160,
                type: 'area',
                fontFamily: 'Nunito, sans-serif',
                sparkline: {
                    enabled: true,
                },
            },
            stroke: {
                curve: 'smooth',
                width: 2,
            },
            colors: ['#1abc9c'],
            grid: {
                padding: {
                    top: 5,
                },
            },
            yaxis: {
                show: false,
            },
            tooltip: {
                x: {
                    show: false,
                },
                y: {
                    title: {
                        formatter: () => {
                            return '';
                        },
                    },
                },
            },
        },
    };
    return (
        <div>
            <div>
                <div className="mb-6 grid gap-6 xl:grid-cols-3">
                    <div className="panel h-full xl:col-span-2">
                        <div className="mb-5 flex items-center justify-between dark:text-white-light">
                            <h5 className="text-lg font-semibold">Revenue</h5>
                            <div className="dropdown">
                                <Dropdown
                                    offset={[0, 1]}
                                    placement={`${isRtl ? 'bottom-start' : 'bottom-end'}`}
                                    button={<IconHorizontalDots className="text-black/70 hover:!text-primary dark:text-white/70" />}
                                >
                                    <ul>
                                        <li>
                                            <button type="button">Weekly</button>
                                        </li>
                                        <li>
                                            <button type="button">Monthly</button>
                                        </li>
                                        <li>
                                            <button type="button">Yearly</button>
                                        </li>
                                    </ul>
                                </Dropdown>
                            </div>
                        </div>
                        <p className="text-lg dark:text-white-light/90">
                            Total Profit <span className="ml-2 text-primary">$10,840</span>
                        </p>
                        <div className="relative">
                            <div className="rounded-lg bg-white dark:bg-black">
                                {isMounted ? (
                                    <ReactApexChart series={revenueChart.series} options={revenueChart.options} type="area" height={325} width={'100%'} />
                                ) : (
                                    <div className="grid min-h-[325px] place-content-center bg-white-light/30 dark:bg-dark dark:bg-opacity-[0.08] ">
                                        <span className="inline-flex h-5 w-5 animate-spin rounded-full  border-2 border-black !border-l-transparent dark:border-white"></span>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="panel h-full">
                        <div className="mb-5 flex items-center">
                            <h5 className="text-lg font-semibold dark:text-white-light">Sales By Category</h5>
                        </div>
                        <div>
                            <div className="rounded-lg bg-white dark:bg-black">
                                {isMounted ? (
                                    <ReactApexChart series={salesByCategory.series} options={salesByCategory.options} type="donut" height={460} width={'100%'} />
                                ) : (
                                    <div className="grid min-h-[325px] place-content-center bg-white-light/30 dark:bg-dark dark:bg-opacity-[0.08] ">
                                        <span className="inline-flex h-5 w-5 animate-spin rounded-full  border-2 border-black !border-l-transparent dark:border-white"></span>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </div>

                <div className="mb-6 grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
                    <div className="panel h-full sm:col-span-2 xl:col-span-1">
                        <div className="mb-5 flex items-center">
                            <h5 className="text-lg font-semibold dark:text-white-light">
                                Daily Sales
                                <span className="block text-sm font-normal text-white-dark">Go to columns for details.</span>
                            </h5>
                            <div className="relative ltr:ml-auto rtl:mr-auto">
                                <div className="grid h-11 w-11 place-content-center rounded-full bg-[#ffeccb] text-warning dark:bg-warning dark:text-[#ffeccb]">
                                    <IconDollarSign />
                                </div>
                            </div>
                        </div>
                        <div>
                            <div className="rounded-lg bg-white dark:bg-black">
                                {isMounted ? (
                                    <ReactApexChart series={dailySales.series} options={dailySales.options} type="bar" height={160} width={'100%'} />
                                ) : (
                                    <div className="grid min-h-[325px] place-content-center bg-white-light/30 dark:bg-dark dark:bg-opacity-[0.08] ">
                                        <span className="inline-flex h-5 w-5 animate-spin rounded-full  border-2 border-black !border-l-transparent dark:border-white"></span>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                    <div className="panel h-full">
                        <div className="mb-5 flex items-center justify-between dark:text-white-light">
                            <h5 className="text-lg font-semibold">Summary</h5>
                            <div className="dropdown">
                                <Dropdown
                                    placement={`${isRtl ? 'bottom-start' : 'bottom-end'}`}
                                    button={<IconHorizontalDots className="h-5 w-5 text-black/70 hover:!text-primary dark:text-white/70" />}
                                >
                                    <ul>
                                        <li>
                                            <button type="button">View Report</button>
                                        </li>
                                        <li>
                                            <button type="button">Edit Report</button>
                                        </li>
                                        <li>
                                            <button type="button">Mark as Done</button>
                                        </li>
                                    </ul>
                                </Dropdown>
                            </div>
                        </div>
                        <div className="space-y-9">
                            <div className="flex items-center">
                                <div className="h-9 w-9 ltr:mr-3 rtl:ml-3">
                                    <div className="grid h-9 w-9 place-content-center  rounded-full bg-secondary-light text-secondary dark:bg-secondary dark:text-secondary-light">
                                        <IconInbox />
                                    </div>
                                </div>
                                <div className="flex-1">
                                    <div className="mb-2 flex font-semibold text-white-dark">
                                        <h6>Income</h6>
                                        <p className="ltr:ml-auto rtl:mr-auto">$92,600</p>
                                    </div>
                                    <div className="h-2 rounded-full bg-dark-light shadow dark:bg-[#1b2e4b]">
                                        <div className="h-full w-11/12 rounded-full bg-gradient-to-r from-[#7579ff] to-[#b224ef]"></div>
                                    </div>
                                </div>
                            </div>
                            <div className="flex items-center">
                                <div className="h-9 w-9 ltr:mr-3 rtl:ml-3">
                                    <div className="grid h-9 w-9 place-content-center rounded-full bg-success-light text-success dark:bg-success dark:text-success-light">
                                        <IconTag />
                                    </div>
                                </div>
                                <div className="flex-1">
                                    <div className="mb-2 flex font-semibold text-white-dark">
                                        <h6>Profit</h6>
                                        <p className="ltr:ml-auto rtl:mr-auto">$37,515</p>
                                    </div>
                                    <div className="h-2 w-full rounded-full bg-dark-light shadow dark:bg-[#1b2e4b]">
                                        <div className="h-full w-full rounded-full bg-gradient-to-r from-[#3cba92] to-[#0ba360]" style={{ width: '65%' }}></div>
                                    </div>
                                </div>
                            </div>
                            <div className="flex items-center">
                                <div className="h-9 w-9 ltr:mr-3 rtl:ml-3">
                                    <div className="grid h-9 w-9 place-content-center rounded-full bg-warning-light text-warning dark:bg-warning dark:text-warning-light">
                                        <IconCreditCard />
                                    </div>
                                </div>
                                <div className="flex-1">
                                    <div className="mb-2 flex font-semibold text-white-dark">
                                        <h6>Expenses</h6>
                                        <p className="ltr:ml-auto rtl:mr-auto">$55,085</p>
                                    </div>
                                    <div className="h-2 w-full rounded-full bg-dark-light shadow dark:bg-[#1b2e4b]">
                                        <div className="h-full w-full rounded-full bg-gradient-to-r from-[#f09819] to-[#ff5858]" style={{ width: '80%' }}></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="panel h-full p-0">
                        <div className="absolute flex w-full items-center justify-between p-5">
                            <div className="relative">
                                <div className="flex h-11 w-11 items-center justify-center rounded-lg bg-success-light text-success dark:bg-success dark:text-success-light">
                                    <IconShoppingCart />
                                </div>
                            </div>
                            <h5 className="text-2xl font-semibold dark:text-white-light ltr:text-right rtl:text-left">
                                3,192
                                <span className="block text-sm font-normal">Total Orders</span>
                            </h5>
                        </div>
                        <div className="rounded-lg bg-transparent">
                            {/* loader */}
                            {isMounted ? (
                                <ReactApexChart series={totalOrders.series} options={totalOrders.options} type="area" height={290} width={'100%'} />
                            ) : (
                                <div className="grid min-h-[325px] place-content-center bg-white-light/30 dark:bg-dark dark:bg-opacity-[0.08] ">
                                    <span className="inline-flex h-5 w-5 animate-spin rounded-full  border-2 border-black !border-l-transparent dark:border-white"></span>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
                <div className="mb-6 grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
                    <div className="panel h-full pb-0 sm:col-span-2 xl:col-span-1">
                        <h5 className="mb-5 text-lg font-semibold dark:text-white-light">Recent Activities</h5>
                        <PerfectScrollbar className="relative mb-4 h-[290px] ltr:-mr-3 ltr:pr-3 rtl:-ml-3 rtl:pl-3" options={{ suppressScrollX: true }}>
                            <div className="cursor-pointer text-sm">
                                <div className="group relative flex items-center py-1.5">
                                    <div className="h-1.5 w-1.5 rounded-full bg-primary ltr:mr-1 rtl:ml-1.5"></div>
                                    <div className="flex-1">Updated Server Logs</div>
                                    <div className="text-xs text-white-dark dark:text-gray-500 ltr:ml-auto rtl:mr-auto">Just Now</div>

                                    <span className="badge badge-outline-primary absolute bg-primary-light text-xs opacity-0 group-hover:opacity-100 dark:bg-black ltr:right-0 rtl:left-0">
                                        Pending
                                    </span>
                                </div>
                                <div className="group relative flex items-center py-1.5">
                                    <div className="h-1.5 w-1.5 rounded-full bg-success ltr:mr-1 rtl:ml-1.5"></div>
                                    <div className="flex-1">Send Mail to HR and Admin</div>
                                    <div className="text-xs text-white-dark dark:text-gray-500 ltr:ml-auto rtl:mr-auto">2 min ago</div>

                                    <span className="badge badge-outline-success absolute bg-success-light text-xs opacity-0 group-hover:opacity-100 dark:bg-black ltr:right-0 rtl:left-0">
                                        Completed
                                    </span>
                                </div>
                                <div className="group relative flex items-center py-1.5">
                                    <div className="h-1.5 w-1.5 rounded-full bg-danger ltr:mr-1 rtl:ml-1.5"></div>
                                    <div className="flex-1">Backup Files EOD</div>
                                    <div className="text-xs text-white-dark dark:text-gray-500 ltr:ml-auto rtl:mr-auto">14:00</div>

                                    <span className="badge badge-outline-danger absolute bg-danger-light text-xs opacity-0 group-hover:opacity-100 dark:bg-black ltr:right-0 rtl:left-0">Pending</span>
                                </div>
                                <div className="group relative flex items-center py-1.5">
                                    <div className="h-1.5 w-1.5 rounded-full bg-black ltr:mr-1 rtl:ml-1.5"></div>
                                    <div className="flex-1">Collect documents from Sara</div>
                                    <div className="text-xs text-white-dark dark:text-gray-500 ltr:ml-auto rtl:mr-auto">16:00</div>

                                    <span className="badge badge-outline-dark absolute bg-dark-light text-xs opacity-0 group-hover:opacity-100 dark:bg-black ltr:right-0 rtl:left-0">Completed</span>
                                </div>
                                <div className="group relative flex items-center py-1.5">
                                    <div className="h-1.5 w-1.5 rounded-full bg-warning ltr:mr-1 rtl:ml-1.5"></div>
                                    <div className="flex-1">Conference call with Marketing Manager.</div>
                                    <div className="text-xs text-white-dark dark:text-gray-500 ltr:ml-auto rtl:mr-auto">17:00</div>

                                    <span className="badge badge-outline-warning absolute bg-warning-light text-xs opacity-0 group-hover:opacity-100 dark:bg-black ltr:right-0 rtl:left-0">
                                        In progress
                                    </span>
                                </div>
                                <div className="group relative flex items-center py-1.5">
                                    <div className="h-1.5 w-1.5 rounded-full bg-info ltr:mr-1 rtl:ml-1.5"></div>
                                    <div className="flex-1">Rebooted Server</div>
                                    <div className="text-xs text-white-dark dark:text-gray-500 ltr:ml-auto rtl:mr-auto">17:00</div>

                                    <span className="badge badge-outline-info absolute bg-info-light text-xs opacity-0 group-hover:opacity-100 dark:bg-black ltr:right-0 rtl:left-0">Completed</span>
                                </div>
                                <div className="group relative flex items-center py-1.5">
                                    <div className="h-1.5 w-1.5 rounded-full bg-secondary ltr:mr-1 rtl:ml-1.5"></div>
                                    <div className="flex-1">Send contract details to Freelancer</div>
                                    <div className="text-xs text-white-dark dark:text-gray-500 ltr:ml-auto rtl:mr-auto">18:00</div>

                                    <span className="badge badge-outline-secondary absolute bg-secondary-light text-xs opacity-0 group-hover:opacity-100 dark:bg-black ltr:right-0 rtl:left-0">
                                        Pending
                                    </span>
                                </div>
                                <div className="group relative flex items-center py-1.5">
                                    <div className="h-1.5 w-1.5 rounded-full bg-primary ltr:mr-1 rtl:ml-1.5"></div>
                                    <div className="flex-1">Updated Server Logs</div>
                                    <div className="text-xs text-white-dark dark:text-gray-500 ltr:ml-auto rtl:mr-auto">Just Now</div>

                                    <span className="badge badge-outline-primary absolute bg-primary-light text-xs opacity-0 group-hover:opacity-100 dark:bg-black ltr:right-0 rtl:left-0">
                                        Pending
                                    </span>
                                </div>
                                <div className="group relative flex items-center py-1.5">
                                    <div className="h-1.5 w-1.5 rounded-full bg-success ltr:mr-1 rtl:ml-1.5"></div>
                                    <div className="flex-1">Send Mail to HR and Admin</div>
                                    <div className="text-xs text-white-dark dark:text-gray-500 ltr:ml-auto rtl:mr-auto">2 min ago</div>

                                    <span className="badge badge-outline-success absolute bg-success-light text-xs opacity-0 group-hover:opacity-100 dark:bg-black ltr:right-0 rtl:left-0">
                                        Completed
                                    </span>
                                </div>
                                <div className="group relative flex items-center py-1.5">
                                    <div className="h-1.5 w-1.5 rounded-full bg-danger ltr:mr-1 rtl:ml-1.5"></div>
                                    <div className="flex-1">Backup Files EOD</div>
                                    <div className="text-xs text-white-dark dark:text-gray-500 ltr:ml-auto rtl:mr-auto">14:00</div>

                                    <span className="badge badge-outline-danger absolute bg-danger-light text-xs opacity-0 group-hover:opacity-100 dark:bg-black ltr:right-0 rtl:left-0">Pending</span>
                                </div>
                                <div className="group relative flex items-center py-1.5">
                                    <div className="h-1.5 w-1.5 rounded-full bg-black ltr:mr-1 rtl:ml-1.5"></div>
                                    <div className="flex-1">Collect documents from Sara</div>
                                    <div className="text-xs text-white-dark dark:text-gray-500 ltr:ml-auto rtl:mr-auto">16:00</div>

                                    <span className="badge badge-outline-dark absolute bg-dark-light text-xs opacity-0 group-hover:opacity-100 dark:bg-black ltr:right-0 rtl:left-0">Completed</span>
                                </div>
                                <div className="group relative flex items-center py-1.5">
                                    <div className="h-1.5 w-1.5 rounded-full bg-warning ltr:mr-1 rtl:ml-1.5"></div>
                                    <div className="flex-1">Conference call with Marketing Manager.</div>
                                    <div className="text-xs text-white-dark dark:text-gray-500 ltr:ml-auto rtl:mr-auto">17:00</div>

                                    <span className="badge badge-outline-warning absolute bg-warning-light text-xs opacity-0 group-hover:opacity-100 dark:bg-black ltr:right-0 rtl:left-0">
                                        In progress
                                    </span>
                                </div>
                                <div className="group relative flex items-center py-1.5">
                                    <div className="h-1.5 w-1.5 rounded-full bg-info ltr:mr-1 rtl:ml-1.5"></div>
                                    <div className="flex-1">Rebooted Server</div>
                                    <div className="text-xs text-white-dark dark:text-gray-500 ltr:ml-auto rtl:mr-auto">17:00</div>

                                    <span className="badge badge-outline-info absolute bg-info-light text-xs opacity-0 group-hover:opacity-100 dark:bg-black ltr:right-0 rtl:left-0">Completed</span>
                                </div>
                                <div className="group relative flex items-center py-1.5">
                                    <div className="h-1.5 w-1.5 rounded-full bg-secondary ltr:mr-1 rtl:ml-1.5"></div>
                                    <div className="flex-1">Send contract details to Freelancer</div>
                                    <div className="text-xs text-white-dark dark:text-gray-500 ltr:ml-auto rtl:mr-auto">18:00</div>

                                    <span className="badge badge-outline-secondary absolute bg-secondary-light text-xs opacity-0 group-hover:opacity-100 dark:bg-black ltr:right-0 rtl:left-0">
                                        Pending
                                    </span>
                                </div>
                            </div>
                        </PerfectScrollbar>
                        <div className="border-t border-white-light dark:border-white/10">
                            <button type="button" className="group group flex w-full items-center justify-center p-4 font-semibold hover:text-primary">
                                View All
                                <IconArrowLeft className="transition duration-300 group-hover:translate-x-1 ltr:ml-1 rtl:mr-1 rtl:rotate-180 rtl:group-hover:-translate-x-1" />
                            </button>
                        </div>
                    </div>
                    <div className="panel h-full">
                        <div className="mb-5 flex items-center justify-between dark:text-white-light">
                            <h5 className="text-lg font-semibold">Transactions</h5>
                            <div className="dropdown">
                                <Dropdown placement={`${isRtl ? 'bottom-start' : 'bottom-end'}`} button={<IconHorizontalDots className="text-black/70 hover:!text-primary dark:text-white/70" />}>
                                    <ul>
                                        <li>
                                            <button type="button">View Report</button>
                                        </li>
                                        <li>
                                            <button type="button">Edit Report</button>
                                        </li>
                                        <li>
                                            <button type="button">Mark as Done</button>
                                        </li>
                                    </ul>
                                </Dropdown>
                            </div>
                        </div>
                        <div>
                            <div className="space-y-6">
                                <div className="flex">
                                    <span className="grid h-9 w-9 shrink-0 place-content-center rounded-md bg-success-light text-base text-success dark:bg-success dark:text-success-light">SP</span>
                                    <div className="flex-1 px-3">
                                        <div>Shaun Park</div>
                                        <div className="text-xs text-white-dark dark:text-gray-500">10 Jan 1:00PM</div>
                                    </div>
                                    <span className="whitespace-pre px-1 text-base text-success ltr:ml-auto rtl:mr-auto">+$36.11</span>
                                </div>
                                <div className="flex">
                                    <span className="grid h-9 w-9 shrink-0 place-content-center rounded-md bg-warning-light text-warning dark:bg-warning dark:text-warning-light">
                                        <IconCashBanknotes />
                                    </span>
                                    <div className="flex-1 px-3">
                                        <div>Cash withdrawal</div>
                                        <div className="text-xs text-white-dark dark:text-gray-500">04 Jan 1:00PM</div>
                                    </div>
                                    <span className="whitespace-pre px-1 text-base text-danger ltr:ml-auto rtl:mr-auto">-$16.44</span>
                                </div>
                                <div className="flex">
                                    <span className="grid h-9 w-9 shrink-0 place-content-center rounded-md bg-danger-light text-danger dark:bg-danger dark:text-danger-light">
                                        <IconUser className="h-6 w-6" />
                                    </span>
                                    <div className="flex-1 px-3">
                                        <div>Amy Diaz</div>
                                        <div className="text-xs text-white-dark dark:text-gray-500">10 Jan 1:00PM</div>
                                    </div>
                                    <span className="whitespace-pre px-1 text-base text-success ltr:ml-auto rtl:mr-auto">+$66.44</span>
                                </div>
                                <div className="flex">
                                    <span className="grid h-9 w-9 shrink-0 place-content-center rounded-md bg-secondary-light text-secondary dark:bg-secondary dark:text-secondary-light">
                                        <IconNetflix />
                                    </span>
                                    <div className="flex-1 px-3">
                                        <div>Netflix</div>
                                        <div className="text-xs text-white-dark dark:text-gray-500">04 Jan 1:00PM</div>
                                    </div>
                                    <span className="whitespace-pre px-1 text-base text-danger ltr:ml-auto rtl:mr-auto">-$32.00</span>
                                </div>
                                <div className="flex">
                                    <span className="grid h-9 w-9 shrink-0 place-content-center rounded-md bg-info-light text-base text-info dark:bg-info dark:text-info-light">DA</span>
                                    <div className="flex-1 px-3">
                                        <div>Daisy Anderson</div>
                                        <div className="text-xs text-white-dark dark:text-gray-500">10 Jan 1:00PM</div>
                                    </div>
                                    <span className="whitespace-pre px-1 text-base text-success ltr:ml-auto rtl:mr-auto">+$10.08</span>
                                </div>
                                <div className="flex">
                                    <span className="grid h-9 w-9 shrink-0 place-content-center rounded-md bg-primary-light text-primary dark:bg-primary dark:text-primary-light">
                                        <IconBolt />
                                    </span>
                                    <div className="flex-1 px-3">
                                        <div>Electricity Bill</div>
                                        <div className="text-xs text-white-dark dark:text-gray-500">04 Jan 1:00PM</div>
                                    </div>
                                    <span className="whitespace-pre px-1 text-base text-danger ltr:ml-auto rtl:mr-auto">-$22.00</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="panel h-full overflow-hidden border-0 p-0">
                        <div className="min-h-[190px] bg-gradient-to-r from-[#4361ee] to-[#160f6b] p-6">
                            <div className="mb-6 flex items-center justify-between">
                                <div className="flex items-center rounded-full bg-black/50 p-1 font-semibold text-white ltr:pr-3 rtl:pl-3">
                                    <img className="block h-8 w-8 rounded-full border-2 border-white/50 object-cover ltr:mr-1 rtl:ml-1" src="/assets/images/profile-34.jpeg" alt="avatar" />
                                    Alan Green
                                </div>
                                <button type="button" className="flex h-9 w-9 items-center justify-between rounded-md bg-black text-white hover:opacity-80 ltr:ml-auto rtl:mr-auto">
                                    <IconPlus className="m-auto h-6 w-6" />
                                </button>
                            </div>
                            <div className="flex items-center justify-between text-white">
                                <p className="text-xl">Wallet Balance</p>
                                <h5 className="text-2xl ltr:ml-auto rtl:mr-auto">
                                    <span className="text-white-light">$</span>2953
                                </h5>
                            </div>
                        </div>
                        <div className="-mt-12 grid grid-cols-2 gap-2 px-8">
                            <div className="rounded-md bg-white px-4 py-2.5 shadow dark:bg-[#060818]">
                                <span className="mb-4 flex items-center justify-between dark:text-white">
                                    Received
                                    <IconCaretDown className="h-4 w-4 rotate-180 text-success" />
                                </span>
                                <div className="btn w-full  border-0 bg-[#ebedf2] py-1 text-base text-[#515365] shadow-none dark:bg-black dark:text-[#bfc9d4]">$97.99</div>
                            </div>
                            <div className="rounded-md bg-white px-4 py-2.5 shadow dark:bg-[#060818]">
                                <span className="mb-4 flex items-center justify-between dark:text-white">
                                    Spent
                                    <IconCaretDown className="h-4 w-4 text-danger" />
                                </span>
                                <div className="btn w-full  border-0 bg-[#ebedf2] py-1 text-base text-[#515365] shadow-none dark:bg-black dark:text-[#bfc9d4]">$53.00</div>
                            </div>
                        </div>
                        <div className="p-5">
                            <div className="mb-5">
                                <span className="rounded-full bg-[#1b2e4b] px-4 py-1.5 text-xs text-white before:inline-block before:h-1.5 before:w-1.5 before:rounded-full before:bg-white ltr:before:mr-2 rtl:before:ml-2">
                                    Pending
                                </span>
                            </div>
                            <div className="mb-5 space-y-1">
                                <div className="flex items-center justify-between">
                                    <p className="font-semibold text-[#515365]">Netflix</p>
                                    <p className="text-base">
                                        <span>$</span> <span className="font-semibold">13.85</span>
                                    </p>
                                </div>
                                <div className="flex items-center justify-between">
                                    <p className="font-semibold text-[#515365]">BlueHost VPN</p>
                                    <p className="text-base">
                                        <span>$</span> <span className="font-semibold ">15.66</span>
                                    </p>
                                </div>
                            </div>
                            <div className="flex justify-around px-2 text-center">
                                <button type="button" className="btn btn-secondary ltr:mr-2 rtl:ml-2">
                                    View Details
                                </button>
                                <button type="button" className="btn btn-success">
                                    Pay Now $29.51
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <div className="panel h-full w-full">
                        <div className="mb-5 flex items-center justify-between">
                            <h5 className="text-lg font-semibold dark:text-white-light">Recent Orders</h5>
                        </div>
                        <div className="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th className="ltr:rounded-l-md rtl:rounded-r-md">Customer</th>
                                        <th>Product</th>
                                        <th>Invoice</th>
                                        <th>Price</th>
                                        <th className="ltr:rounded-r-md rtl:rounded-l-md">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr className="group text-white-dark hover:text-black dark:hover:text-white-light/90">
                                        <td className="min-w-[150px] text-black dark:text-white">
                                            <div className="flex items-center">
                                                <img className="h-8 w-8 rounded-md object-cover ltr:mr-3 rtl:ml-3" src="/assets/images/profile-6.jpeg" alt="avatar" />
                                                <span className="whitespace-nowrap">Luke Ivory</span>
                                            </div>
                                        </td>
                                        <td className="text-primary">Headphone</td>
                                        <td>
                                            <Link href="/apps/invoice/preview">#46894</Link>
                                        </td>
                                        <td>$56.07</td>
                                        <td>
                                            <span className="badge bg-success shadow-md dark:group-hover:bg-transparent">Paid</span>
                                        </td>
                                    </tr>
                                    <tr className="group text-white-dark hover:text-black dark:hover:text-white-light/90">
                                        <td className="text-black dark:text-white">
                                            <div className="flex items-center">
                                                <img className="h-8 w-8 rounded-md object-cover ltr:mr-3 rtl:ml-3" src="/assets/images/profile-7.jpeg" alt="avatar" />
                                                <span className="whitespace-nowrap">Andy King</span>
                                            </div>
                                        </td>
                                        <td className="text-info">Nike Sport</td>
                                        <td>
                                            <Link href="/apps/invoice/preview">#76894</Link>
                                        </td>
                                        <td>$126.04</td>
                                        <td>
                                            <span className="badge bg-secondary shadow-md dark:group-hover:bg-transparent">Shipped</span>
                                        </td>
                                    </tr>
                                    <tr className="group text-white-dark hover:text-black dark:hover:text-white-light/90">
                                        <td className="text-black dark:text-white">
                                            <div className="flex items-center">
                                                <img className="h-8 w-8 rounded-md object-cover ltr:mr-3 rtl:ml-3" src="/assets/images/profile-8.jpeg" alt="avatar" />
                                                <span className="whitespace-nowrap">Laurie Fox</span>
                                            </div>
                                        </td>
                                        <td className="text-warning">Sunglasses</td>
                                        <td>
                                            <Link href="/apps/invoice/preview">#66894</Link>
                                        </td>
                                        <td>$56.07</td>
                                        <td>
                                            <span className="badge bg-success shadow-md dark:group-hover:bg-transparent">Paid</span>
                                        </td>
                                    </tr>
                                    <tr className="group text-white-dark hover:text-black dark:hover:text-white-light/90">
                                        <td className="text-black dark:text-white">
                                            <div className="flex items-center">
                                                <img className="h-8 w-8 rounded-md object-cover ltr:mr-3 rtl:ml-3" src="/assets/images/profile-9.jpeg" alt="avatar" />
                                                <span className="whitespace-nowrap">Ryan Collins</span>
                                            </div>
                                        </td>
                                        <td className="text-danger">Sport</td>
                                        <td>
                                            <button type="button">#75844</button>
                                        </td>
                                        <td>$110.00</td>
                                        <td>
                                            <span className="badge bg-secondary shadow-md dark:group-hover:bg-transparent">Shipped</span>
                                        </td>
                                    </tr>
                                    <tr className="group text-white-dark hover:text-black dark:hover:text-white-light/90">
                                        <td className="text-black dark:text-white">
                                            <div className="flex items-center">
                                                <img className="h-8 w-8 rounded-md object-cover ltr:mr-3 rtl:ml-3" src="/assets/images/profile-10.jpeg" alt="avatar" />
                                                <span className="whitespace-nowrap">Irene Collins</span>
                                            </div>
                                        </td>
                                        <td className="text-secondary">Speakers</td>
                                        <td>
                                            <Link href="/apps/invoice/preview">#46894</Link>
                                        </td>
                                        <td>$56.07</td>
                                        <td>
                                            <span className="badge bg-success shadow-md dark:group-hover:bg-transparent">Paid</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div className="panel h-full w-full">
                        <div className="mb-5 flex items-center justify-between">
                            <h5 className="text-lg font-semibold dark:text-white-light">Top Selling Product</h5>
                        </div>
                        <div className="table-responsive">
                            <table>
                                <thead>
                                    <tr className="border-b-0">
                                        <th className="ltr:rounded-l-md rtl:rounded-r-md">Product</th>
                                        <th>Price</th>
                                        <th>Discount</th>
                                        <th>Sold</th>
                                        <th className="ltr:rounded-r-md rtl:rounded-l-md">Source</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr className="group text-white-dark hover:text-black dark:hover:text-white-light/90">
                                        <td className="min-w-[150px] text-black dark:text-white">
                                            <div className="flex">
                                                <img className="h-8 w-8 rounded-md object-cover ltr:mr-3 rtl:ml-3" src="/assets/images/product-headphones.jpg" alt="avatar" />
                                                <p className="whitespace-nowrap">
                                                    Headphone
                                                    <span className="block text-xs text-primary">Digital</span>
                                                </p>
                                            </div>
                                        </td>
                                        <td>$168.09</td>
                                        <td>$60.09</td>
                                        <td>170</td>
                                        <td>
                                            <button type="button" className="flex items-center text-danger">
                                                <IconMultipleForwardRight className="ltr:mr-1 rtl:ml-1 rtl:rotate-180" />
                                                Direct
                                            </button>
                                        </td>
                                    </tr>
                                    <tr className="group text-white-dark hover:text-black dark:hover:text-white-light/90">
                                        <td className="text-black dark:text-white">
                                            <div className="flex">
                                                <img className="h-8 w-8 rounded-md object-cover ltr:mr-3 rtl:ml-3" src="/assets/images/product-shoes.jpg" alt="avatar" />
                                                <p className="whitespace-nowrap">
                                                    Shoes <span className="block text-xs text-warning">Faishon</span>
                                                </p>
                                            </div>
                                        </td>
                                        <td>$126.04</td>
                                        <td>$47.09</td>
                                        <td>130</td>
                                        <td>
                                            <button type="button" className="flex items-center text-success">
                                                <IconMultipleForwardRight className="ltr:mr-1 rtl:ml-1 rtl:rotate-180" />
                                                Google
                                            </button>
                                        </td>
                                    </tr>
                                    <tr className="group text-white-dark hover:text-black dark:hover:text-white-light/90">
                                        <td className="text-black dark:text-white">
                                            <div className="flex">
                                                <img className="h-8 w-8 rounded-md object-cover ltr:mr-3 rtl:ml-3" src="/assets/images/product-watch.jpg" alt="avatar" />
                                                <p className="whitespace-nowrap">
                                                    Watch <span className="block text-xs text-danger">Accessories</span>
                                                </p>
                                            </div>
                                        </td>
                                        <td>$56.07</td>
                                        <td>$20.00</td>
                                        <td>66</td>
                                        <td>
                                            <button type="button" className="flex items-center text-warning">
                                                <IconMultipleForwardRight className="ltr:mr-1 rtl:ml-1 rtl:rotate-180" />
                                                Ads
                                            </button>
                                        </td>
                                    </tr>
                                    <tr className="group text-white-dark hover:text-black dark:hover:text-white-light/90">
                                        <td className="text-black dark:text-white">
                                            <div className="flex">
                                                <img className="h-8 w-8 rounded-md object-cover ltr:mr-3 rtl:ml-3" src="/assets/images/product-laptop.jpg" alt="avatar" />
                                                <p className="whitespace-nowrap">
                                                    Laptop <span className="block text-xs text-primary">Digital</span>
                                                </p>
                                            </div>
                                        </td>
                                        <td>$110.00</td>
                                        <td>$33.00</td>
                                        <td>35</td>
                                        <td>
                                            <button type="button" className="flex items-center text-secondary">
                                                <IconMultipleForwardRight className="ltr:mr-1 rtl:ml-1 rtl:rotate-180" />
                                                Email
                                            </button>
                                        </td>
                                    </tr>
                                    <tr className="group text-white-dark hover:text-black dark:hover:text-white-light/90">
                                        <td className="text-black dark:text-white">
                                            <div className="flex">
                                                <img className="h-8 w-8 rounded-md object-cover ltr:mr-3 rtl:ml-3" src="/assets/images/product-camera.jpg" alt="avatar" />
                                                <p className="whitespace-nowrap">
                                                    Camera <span className="block text-xs text-primary">Digital</span>
                                                </p>
                                            </div>
                                        </td>
                                        <td>$56.07</td>
                                        <td>$26.04</td>
                                        <td>30</td>
                                        <td>
                                            <button type="button" className="flex items-center text-primary">
                                                <IconMultipleForwardRight className="ltr:mr-1 rtl:ml-1 rtl:rotate-180" />
                                                Referral
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div className="mt-6">
                <div className="mb-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <div className="panel h-full sm:col-span-2 lg:col-span-1">
                        {/* statistics */}
                        <div className="mb-5 flex justify-between dark:text-white-light">
                            <h5 className="text-lg font-semibold ">Statistics</h5>
                            <div className="dropdown">
                                <Dropdown
                                    offset={[0, 5]}
                                    placement={`${isRtl ? 'bottom-start' : 'bottom-end'}`}
                                    btnClassName="hover:text-primary"
                                    button={<IconHorizontalDots className="text-black/70 hover:!text-primary dark:text-white/70" />}
                                >
                                    <ul>
                                        <li>
                                            <button type="button">This Week</button>
                                        </li>
                                        <li>
                                            <button type="button">Last Week</button>
                                        </li>
                                        <li>
                                            <button type="button">This Month</button>
                                        </li>
                                        <li>
                                            <button type="button">Last Month</button>
                                        </li>
                                    </ul>
                                </Dropdown>
                            </div>
                        </div>
                        <div className="grid gap-8 text-sm font-bold text-[#515365] sm:grid-cols-2">
                            <div>
                                <div>
                                    <div>Total Visits</div>
                                    <div className="text-lg text-[#f8538d]">423,964</div>
                                </div>
                                {isMounted && <ReactApexChart series={totalVisit.series} options={totalVisit.options} type="line" height={58} width={'100%'} />}
                            </div>

                            <div>
                                <div>
                                    <div>Paid Visits</div>
                                    <div className="text-lg text-[#f8538d]">7,929</div>
                                </div>

                                {isMounted && <ReactApexChart series={paidVisit.series} options={paidVisit.options} type="line" height={58} width={'100%'} />}
                            </div>
                        </div>
                    </div>

                    <div className="panel h-full">
                        <div className="mb-5 flex justify-between dark:text-white-light">
                            <h5 className="text-lg font-semibold ">Expenses</h5>

                            <div className="dropdown">
                                <Dropdown
                                    offset={[0, 5]}
                                    placement={`${isRtl ? 'bottom-start' : 'bottom-end'}`}
                                    btnClassName="hover:text-primary"
                                    button={<IconHorizontalDots className="text-black/70 hover:!text-primary dark:text-white/70" />}
                                >
                                    <ul>
                                        <li>
                                            <button type="button">This Week</button>
                                        </li>
                                        <li>
                                            <button type="button">Last Week</button>
                                        </li>
                                        <li>
                                            <button type="button">This Month</button>
                                        </li>
                                        <li>
                                            <button type="button">Last Month</button>
                                        </li>
                                    </ul>
                                </Dropdown>
                            </div>
                        </div>
                        <div className=" my-10 text-3xl font-bold text-[#e95f2b]">
                            <span>$ 45,141 </span>
                            <span className="text-sm text-black dark:text-white-light ltr:mr-2 rtl:ml-2">this week</span>
                            <IconTrendingUp className="inline text-success" />
                        </div>
                        <div className="flex items-center justify-between">
                            <div className="h-5 w-full overflow-hidden rounded-full bg-dark-light p-1 shadow-3xl dark:bg-dark-light/10 dark:shadow-none">
                                <div
                                    className="relative h-full w-full rounded-full bg-gradient-to-r from-[#4361ee] to-[#805dca] before:absolute before:inset-y-0 before:m-auto before:h-2 before:w-2 before:rounded-full before:bg-white ltr:before:right-0.5 rtl:before:left-0.5"
                                    style={{ width: '65%' }}
                                ></div>
                            </div>
                            <span className="dark:text-white-light ltr:ml-5 rtl:mr-5">57%</span>
                        </div>
                    </div>

                    <div
                        className="panel grid h-full grid-cols-1 content-between overflow-hidden before:absolute before:-right-44 before:bottom-0 before:top-0 before:m-auto before:h-96 before:w-96 before:rounded-full before:bg-[#1937cc]"
                        style={{
                            background: 'linear-gradient(0deg,#00c6fb -227%,#005bea)',
                        }}
                    >
                        <div className="z-[7] mb-16 flex items-start justify-between text-white-light">
                            <h5 className="text-lg font-semibold">Total Balance</h5>

                            <div className="relative whitespace-nowrap text-xl">
                                $ 41,741.42
                                <span className="mt-1 table rounded bg-[#4361ee] p-1 text-xs text-[#d3d3d3] ltr:ml-auto rtl:mr-auto">+ 2453</span>
                            </div>
                        </div>
                        <div className="z-10 flex items-center justify-between">
                            <div className="flex items-center justify-between">
                                <button type="button" className="place-content-center rounded p-1 text-white-light shadow-[0_0_2px_0_#bfc9d4] hover:bg-[#1937cc] ltr:mr-2 rtl:ml-2">
                                    <IconPlus />
                                </button>
                                <button type="button" className="grid place-content-center rounded p-1 text-white-light shadow-[0_0_2px_0_#bfc9d4] hover:bg-[#1937cc]">
                                    <IconCreditCard />
                                </button>
                            </div>
                            <button type="button" className="z-10 rounded p-1 text-white-light shadow-[0_0_2px_0_#bfc9d4] hover:bg-[#4361ee]">
                                Upgrade
                            </button>
                        </div>
                    </div>
                </div>

                <div className="mb-6 grid gap-6 lg:grid-cols-3">
                    <div className="panel h-full p-0 lg:col-span-2">
                        <div className="mb-5 flex items-start justify-between border-b border-white-light p-5  dark:border-[#1b2e4b] dark:text-white-light">
                            <h5 className="text-lg font-semibold ">Unique Visitors</h5>
                            <div className="dropdown">
                                <Dropdown
                                    offset={[0, 5]}
                                    placement={`${isRtl ? 'bottom-start' : 'bottom-end'}`}
                                    btnClassName="hover:text-primary"
                                    button={<IconHorizontalDots className="text-black/70 hover:!text-primary dark:text-white/70" />}
                                >
                                    <ul>
                                        <li>
                                            <button type="button">View</button>
                                        </li>
                                        <li>
                                            <button type="button">Update</button>
                                        </li>
                                        <li>
                                            <button type="button">Delete</button>
                                        </li>
                                    </ul>
                                </Dropdown>
                            </div>
                        </div>

                        {isMounted && <ReactApexChart options={uniqueVisitorSeries.options} series={uniqueVisitorSeries.series} type="bar" height={360} width={'100%'} />}
                    </div>

                    <div className="panel h-full">
                        <div className="-mx-5 mb-5 flex items-start justify-between border-b border-white-light p-5 pt-0  dark:border-[#1b2e4b] dark:text-white-light">
                            <h5 className="text-lg font-semibold ">Activity Log</h5>
                            <div className="dropdown">
                                <Dropdown
                                    offset={[0, 5]}
                                    placement={`${isRtl ? 'bottom-start' : 'bottom-end'}`}
                                    btnClassName="hover:text-primary"
                                    button={<IconHorizontalDots className="text-black/70 hover:!text-primary dark:text-white/70" />}
                                >
                                    <ul>
                                        <li>
                                            <button type="button">View All</button>
                                        </li>
                                        <li>
                                            <button type="button">Mark as Read</button>
                                        </li>
                                    </ul>
                                </Dropdown>
                            </div>
                        </div>
                        <PerfectScrollbar className="perfect-scrollbar relative h-[360px] ltr:-mr-3 ltr:pr-3 rtl:-ml-3 rtl:pl-3">
                            <div className="space-y-7">
                                <div className="flex">
                                    <div className="relative z-10 shrink-0 before:absolute before:left-4 before:top-10 before:h-[calc(100%-24px)] before:w-[2px] before:bg-white-dark/30 ltr:mr-2 rtl:ml-2">
                                        <div className="flex h-8 w-8 items-center justify-center rounded-full bg-secondary text-white shadow shadow-secondary">
                                            <IconPlus className="h-4 w-4" />
                                        </div>
                                    </div>
                                    <div>
                                        <h5 className="font-semibold dark:text-white-light">
                                            New project created :{' '}
                                            <button type="button" className="text-success">
                                                [XPLENDOR Admin Template]
                                            </button>
                                        </h5>
                                        <p className="text-xs text-white-dark">27 Feb, 2020</p>
                                    </div>
                                </div>
                                <div className="flex">
                                    <div className="relative z-10 shrink-0 before:absolute before:left-4 before:top-10 before:h-[calc(100%-24px)] before:w-[2px] before:bg-white-dark/30 ltr:mr-2 rtl:ml-2">
                                        <div className="flex h-8 w-8 items-center justify-center rounded-full bg-success text-white shadow-success">
                                            <IconMail className="h-4 w-4" />
                                        </div>
                                    </div>
                                    <div>
                                        <h5 className="font-semibold dark:text-white-light">
                                            Mail sent to{' '}
                                            <button type="button" className="text-white-dark">
                                                HR
                                            </button>{' '}
                                            and{' '}
                                            <button type="button" className="text-white-dark">
                                                Admin
                                            </button>
                                        </h5>
                                        <p className="text-xs text-white-dark">28 Feb, 2020</p>
                                    </div>
                                </div>
                                <div className="flex">
                                    <div className="relative z-10 shrink-0 before:absolute before:left-4 before:top-10 before:h-[calc(100%-24px)] before:w-[2px] before:bg-white-dark/30 ltr:mr-2 rtl:ml-2">
                                        <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-white">
                                            <IconChecks className="h-4 w-4" />
                                        </div>
                                    </div>
                                    <div>
                                        <h5 className="font-semibold dark:text-white-light">Server Logs Updated</h5>
                                        <p className="text-xs text-white-dark">27 Feb, 2020</p>
                                    </div>
                                </div>
                                <div className="flex">
                                    <div className="relative z-10 shrink-0 before:absolute before:left-4 before:top-10 before:h-[calc(100%-24px)] before:w-[2px] before:bg-white-dark/30 ltr:mr-2 rtl:ml-2">
                                        <div className="flex h-8 w-8 items-center justify-center rounded-full bg-danger text-white">
                                            <IconChecks className="h-4 w-4" />
                                        </div>
                                    </div>
                                    <div>
                                        <h5 className="font-semibold dark:text-white-light">
                                            Task Completed :
                                            <button type="button" className="ml-1 text-success">
                                                [Backup Files EOD]
                                            </button>
                                        </h5>
                                        <p className="text-xs text-white-dark">01 Mar, 2020</p>
                                    </div>
                                </div>
                                <div className="flex">
                                    <div className="relative z-10 shrink-0 before:absolute before:left-4 before:top-10 before:h-[calc(100%-24px)] before:w-[2px] before:bg-white-dark/30 ltr:mr-2 rtl:ml-2">
                                        <div className="flex h-8 w-8 items-center justify-center rounded-full bg-warning text-white">
                                            <IconFile className="h-4 w-4" />
                                        </div>
                                    </div>
                                    <div>
                                        <h5 className="font-semibold dark:text-white-light">
                                            Documents Submitted from <button type="button">Sara</button>
                                        </h5>
                                        <p className="text-xs text-white-dark">10 Mar, 2020</p>
                                    </div>
                                </div>
                                <div className="flex">
                                    <div className="shrink-0 ltr:mr-2 rtl:ml-2">
                                        <div className="flex h-8 w-8 items-center justify-center rounded-full bg-dark text-white">
                                            <IconServer className="h-4 w-4" />
                                        </div>
                                    </div>
                                    <div>
                                        <h5 className="font-semibold dark:text-white-light">Server rebooted successfully</h5>
                                        <p className="text-xs text-white-dark">06 Apr, 2020</p>
                                    </div>
                                </div>
                            </div>
                        </PerfectScrollbar>
                    </div>
                </div>

                <div className="mb-6 grid gap-6 sm:grid-cols-3 xl:grid-cols-5">
                    <div className="panel h-full sm:col-span-3 xl:col-span-2">
                        <div className="mb-5 flex items-start justify-between">
                            <h5 className="text-lg font-semibold dark:text-white-light">Visitors by Browser</h5>
                        </div>
                        <div className="flex flex-col space-y-5">
                            <div className="flex items-center">
                                <div className="h-9 w-9">
                                    <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-primary/10 text-primary dark:bg-primary dark:text-white-light">
                                        <IconChrome className="h-5 w-5" />
                                    </div>
                                </div>
                                <div className="w-full flex-initial px-3">
                                    <div className="w-summary-info mb-1 flex justify-between font-semibold text-white-dark">
                                        <h6>Chrome</h6>
                                        <p className="text-xs ltr:ml-auto rtl:mr-auto">65%</p>
                                    </div>
                                    <div>
                                        <div className="h-5 w-full overflow-hidden rounded-full bg-dark-light p-1 shadow-3xl dark:bg-dark-light/10 dark:shadow-none">
                                            <div
                                                className="relative h-full w-full rounded-full bg-gradient-to-r from-[#009ffd] to-[#2a2a72] before:absolute before:inset-y-0 before:m-auto before:h-2 before:w-2 before:rounded-full before:bg-white ltr:before:right-0.5 rtl:before:left-0.5"
                                                style={{ width: '65%' }}
                                            ></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div className="flex items-center">
                                <div className="h-9 w-9">
                                    <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-danger/10 text-danger dark:bg-danger dark:text-white-light">
                                        <IconSafari className="h-5 w-5" />
                                    </div>
                                </div>
                                <div className="w-full flex-initial px-3">
                                    <div className="w-summary-info mb-1 flex justify-between font-semibold text-white-dark">
                                        <h6>Safari</h6>
                                        <p className="text-xs ltr:ml-auto rtl:mr-auto">40%</p>
                                    </div>
                                    <div>
                                        <div className="h-5 w-full overflow-hidden rounded-full bg-dark-light p-1 shadow-3xl dark:bg-dark-light/10 dark:shadow-none">
                                            <div
                                                className="relative h-full w-full rounded-full bg-gradient-to-r from-[#a71d31] to-[#3f0d12] before:absolute before:inset-y-0 before:m-auto before:h-2 before:w-2 before:rounded-full before:bg-white ltr:before:right-0.5 rtl:before:left-0.5"
                                                style={{ width: '40%' }}
                                            ></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div className="flex items-center">
                                <div className="h-9 w-9">
                                    <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-warning/10 text-warning dark:bg-warning dark:text-white-light">
                                        <IconGlobe className="h-5 w-5" />
                                    </div>
                                </div>
                                <div className="w-full flex-initial px-3">
                                    <div className="w-summary-info mb-1 flex justify-between font-semibold text-white-dark">
                                        <h6>Others</h6>
                                        <p className="text-xs ltr:ml-auto rtl:mr-auto">25%</p>
                                    </div>
                                    <div>
                                        <div className="h-5 w-full overflow-hidden rounded-full bg-dark-light p-1 shadow-3xl dark:bg-dark-light/10 dark:shadow-none">
                                            <div
                                                className="relative h-full w-full rounded-full bg-gradient-to-r from-[#fe5f75] to-[#fc9842] before:absolute before:inset-y-0 before:m-auto before:h-2 before:w-2 before:rounded-full before:bg-white ltr:before:right-0.5 rtl:before:left-0.5"
                                                style={{ width: '25%' }}
                                            ></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="panel h-full p-0">
                        <div className="flex p-5">
                            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary dark:bg-primary dark:text-white-light">
                                <IconUsersGroup className="h-5 w-5" />
                            </div>
                            <div className="font-semibold ltr:ml-3 rtl:mr-3">
                                <p className="text-xl dark:text-white-light">31.6K</p>
                                <h5 className="text-xs text-[#506690]">Followers</h5>
                            </div>
                        </div>
                        <div className="h-40">
                            {isMounted && <ReactApexChart series={followers.series} options={followers.options} type="area" height={160} width={'100%'} className="absolute bottom-0 w-full" />}
                        </div>
                    </div>

                    <div className="panel h-full p-0">
                        <div className="flex p-5">
                            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-danger/10 text-danger dark:bg-danger dark:text-white-light">
                                <IconLink className="h-5 w-5" />
                            </div>
                            <div className="font-semibold ltr:ml-3 rtl:mr-3">
                                <p className="text-xl dark:text-white-light">1,900</p>
                                <h5 className="text-xs text-[#506690]">Referral</h5>
                            </div>
                        </div>
                        <div className="h-40">
                            {isMounted && <ReactApexChart series={referral.series} options={referral.options} type="area" height={160} width={'100%'} className="absolute bottom-0 w-full" />}
                        </div>
                    </div>

                    <div className="panel h-full p-0">
                        <div className="flex p-5">
                            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-success/10 text-success dark:bg-success dark:text-white-light">
                                <IconChatDots className="h-5 w-5" />
                            </div>
                            <div className="font-semibold ltr:ml-3 rtl:mr-3">
                                <p className="text-xl dark:text-white-light">18.2%</p>
                                <h5 className="text-xs text-[#506690]">Engagement</h5>
                            </div>
                        </div>
                        <div className="h-40">
                            {isMounted && <ReactApexChart series={engagement.series} options={engagement.options} type="area" height={160} width={'100%'} className="absolute bottom-0 w-full" />}
                        </div>
                    </div>
                </div>

                <div className="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                    <div className="panel h-full">
                        <div className="-m-5 mb-5 flex  items-start border-b border-white-light p-5 dark:border-[#1b2e4b]">
                            <div className="shrink-0 rounded-full ring-2 ring-white-light dark:ring-dark ltr:mr-4 rtl:ml-4">
                                <img src="/assets/images/profile-1.jpeg" alt="profile1" className="h-10 w-10 rounded-full object-cover" />
                            </div>
                            <div className="font-semibold">
                                <h6>Jimmy Turner</h6>
                                <p className="mt-1 text-xs text-white-dark">Monday, Nov 18</p>
                            </div>
                        </div>
                        <div>
                            <div className="pb-8 text-white-dark">
                                {`"Duis aute irure dolor" in reprehenderit in voluptate velit esse
                cillum "dolore eu fugiat" nulla pariatur. Excepteur sint
                occaecat cupidatat non proident.`}
                            </div>
                            <div className="absolute bottom-0 -mx-5 flex w-full items-center justify-between p-5">
                                <div className="flex items-center">
                                    <IconThumbUp className="relative -top-0.5 inline h-5 w-5 text-info ltr:mr-1.5 rtl:ml-1.5" />
                                    <span className="dark:text-info">551 Likes</span>
                                </div>
                                <button type="button" className="flex items-center rounded-md bg-success/30 px-1.5 py-1 text-xs text-success hover:shadow-[0_10px_20px_-10px] hover:shadow-success">
                                    Read More
                                    <IconCaretsDown className="h-4 w-4 -rotate-90 ltr:ml-1.5 rtl:mr-1.5 rtl:rotate-90" />
                                </button>
                            </div>
                        </div>
                    </div>

                    <div className="panel h-full">
                        <div className="-m-5 mb-5 flex items-center  justify-between border-b border-white-light p-5 dark:border-[#1b2e4b]">
                            <div className="flex">
                                <div className="media-aside align-self-start">
                                    <div className="shrink-0 rounded-full ring-2 ring-white-light dark:ring-dark ltr:mr-4 rtl:ml-4">
                                        <img src="/assets/images/g-8.png" alt="profile2" className="h-10 w-10 rounded-full object-cover" />
                                    </div>
                                </div>
                                <div className="font-semibold">
                                    <h6>Dev Summit - New York</h6>
                                    <p className="mt-1 text-xs text-white-dark">Bronx, NY</p>
                                </div>
                            </div>
                        </div>
                        <div className="pb-8 text-center font-semibold">
                            <div className="mb-4 text-primary">4 Members Going</div>
                            <div className="flex items-center justify-center gap-3 pb-8">
                                <img className="h-10 w-10 overflow-hidden rounded-lg object-cover ring-2 ring-white-light dark:ring-dark" src="/assets/images/profile-1.jpeg" alt="profile1" />
                                <img className="h-10 w-10 overflow-hidden rounded-lg object-cover ring-2 ring-white-light dark:ring-dark" src="/assets/images/profile-2.jpeg" alt="profile2" />
                                <img className="h-10 w-10 overflow-hidden rounded-lg object-cover ring-2 ring-white-light dark:ring-dark" src="/assets/images/profile-3.jpeg" alt="profile3" />
                                <img className="h-10 w-10 overflow-hidden rounded-lg object-cover ring-2 ring-white-light dark:ring-dark" src="/assets/images/profile-4.jpeg" alt="profile4" />
                            </div>

                            <div className="absolute bottom-0 -mx-5 flex w-full items-center justify-between p-5">
                                <button type="button" className="btn btn-secondary btn-lg w-full border-0 bg-gradient-to-r from-[#3d38e1] to-[#1e9afe]">
                                    View Details
                                </button>
                            </div>
                        </div>
                    </div>

                    <div className="panel h-full">
                        <div className="-m-5 mb-5 flex items-center justify-between border-b border-white-light p-5 dark:border-[#1b2e4b]">
                            <button type="button" className="flex font-semibold">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-secondary text-white ltr:mr-4 rtl:ml-4">
                                    <span>FD</span>
                                </div>
                                <div style={{ textAlign: 'left' }}>
                                    <h6>Figma Design</h6>
                                    <p className="mt-1 text-xs text-white-dark">Design Reset</p>
                                </div>
                            </button>

                            <div className="dropdown">
                                <Dropdown
                                    offset={[0, 5]}
                                    placement={`${isRtl ? 'bottom-start' : 'bottom-end'}`}
                                    btnClassName="hover:text-primary"
                                    button={<IconHorizontalDots className="h-5 w-5 text-black/70 hover:!text-primary dark:text-white/70" />}
                                >
                                    <ul>
                                        <li>
                                            <button type="button">View Project</button>
                                        </li>
                                        <li>
                                            <button type="button">Edit Project</button>
                                        </li>
                                        <li>
                                            <button type="button">Mark as Done</button>
                                        </li>
                                    </ul>
                                </Dropdown>
                            </div>
                        </div>
                        <div className="group">
                            <div className="mb-5 text-white-dark">Doloribus nisi vel suscipit modi, optio ex repudiandae voluptatibus officiis commodi. Nesciunt quas aut neque incidunt!</div>
                            <div className="mb-2 flex items-center justify-between font-semibold">
                                <div className="flex items-center">
                                    <IconSquareCheck className="h-4 w-4 text-success" />
                                    <div className="text-xs ltr:ml-2 rtl:mr-2">5 Tasks</div>
                                </div>
                                <p className="text-primary">65%</p>
                            </div>
                            <div className="mb-5 h-2.5 rounded-full bg-dark-light p-0.5 dark:bg-dark-light/10">
                                <div className="h-full rounded-full bg-gradient-to-r from-[#1e9afe] to-[#60dfcd]" style={{ width: '65%' }}></div>
                            </div>
                            <div className="flex items-end justify-between">
                                <div className="flex items-center rounded-full bg-danger/20 px-2 py-1 text-xs font-semibold text-danger">
                                    <IconClock className="h-3 w-3 ltr:mr-1 rtl:ml-1" />3 Days Left
                                </div>
                                <div className="flex items-center justify-center group-hover:-space-x-2 rtl:space-x-reverse rtl:group-hover:space-x-reverse">
                                    <span className="flex h-9 w-9 items-center justify-center rounded-full bg-[#bfc9d4] font-semibold text-white opacity-0 transition-all duration-300 group-hover:opacity-100 dark:bg-dark">
                                        +6
                                    </span>
                                    <img
                                        className="h-9 w-9 rounded-full border-2 border-white object-cover transition-all duration-300 dark:border-dark"
                                        src="/assets/images/profile-6.jpeg"
                                        alt="profile6"
                                    />
                                    <img
                                        className="h-9 w-9 rounded-full border-2 border-white object-cover transition-all duration-300 dark:border-dark"
                                        src="/assets/images/profile-7.jpeg"
                                        alt="profile7"
                                    />
                                    <img
                                        className="h-9 w-9 rounded-full border-2 border-white object-cover transition-all duration-300 dark:border-dark"
                                        src="/assets/images/profile-8.jpeg"
                                        alt="profile8"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default ComponentsWidgets;
