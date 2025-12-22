'use client';
import { useEffect, useMemo, useState } from 'react';
import { useDispatch, useSelector } from 'react-redux';
import Link from 'next/link';
import Cookies from 'js-cookie';
import { IRootState } from '@/store';
import { toggleTheme, toggleSidebar, toggleRTL } from '@/store/themeConfigSlice';
import Dropdown from '@/components/dropdown';
import IconMenu from '@/components/icon/icon-menu';
import IconSearch from '@/components/icon/icon-search';
import IconSun from '@/components/icon/icon-sun';
import IconMoon from '@/components/icon/icon-moon';
import IconLaptop from '@/components/icon/icon-laptop';
import IconUser from '@/components/icon/icon-user';
import IconMail from '@/components/icon/icon-mail';
import IconLockDots from '@/components/icon/icon-lock-dots';
import IconLogout from '@/components/icon/icon-logout';
import IconMenuDashboard from '@/components/icon/menu/icon-menu-dashboard';
import IconCaretDown from '@/components/icon/icon-caret-down';
import IconMenuApps from '@/components/icon/menu/icon-menu-apps';
import IconMenuComponents from '@/components/icon/menu/icon-menu-components';
import IconMenuElements from '@/components/icon/menu/icon-menu-elements';
import IconMenuDatatables from '@/components/icon/menu/icon-menu-datatables';
import IconMenuForms from '@/components/icon/menu/icon-menu-forms';
import IconMenuPages from '@/components/icon/menu/icon-menu-pages';
import IconMenuMore from '@/components/icon/menu/icon-menu-more';
import { usePathname, useRouter } from 'next/navigation';
import { getTranslation } from '@/i18n';
import { lockScreen, logout } from '@/store/authSlice';

const Header = () => {
    const pathname = usePathname();
    const dispatch = useDispatch();
    const router = useRouter();
    const { t, i18n } = getTranslation();

    const user = useSelector((state: IRootState) => state.auth.user);

    // States
    const [loading, setLoading] = useState<boolean>(false);

    // Effect
    useEffect(() => {
        const selector = document.querySelector('ul.horizontal-menu a[href="' + window.location.pathname + '"]');
        if (selector) {
            const all: any = document.querySelectorAll('ul.horizontal-menu .nav-link.active');
            for (let i = 0; i < all.length; i++) {
                all[0]?.classList.remove('active');
            }

            let allLinks = document.querySelectorAll('ul.horizontal-menu a.active');
            for (let i = 0; i < allLinks.length; i++) {
                const element = allLinks[i];
                element?.classList.remove('active');
            }
            selector?.classList.add('active');

            const ul: any = selector.closest('ul.sub-menu');
            if (ul) {
                let ele: any = ul.closest('li.menu').querySelectorAll('.nav-link');
                if (ele) {
                    ele = ele[0];
                    setTimeout(() => {
                        ele?.classList.add('active');
                    });
                }
            }
        }
    }, [pathname]);

    const isRtl = useSelector((state: IRootState) => state.themeConfig.rtlClass) === 'rtl';

    const themeConfig = useSelector((state: IRootState) => state.themeConfig);
    const setLocale = (flag: string) => {
        if (flag.toLowerCase() === 'ae') {
            dispatch(toggleRTL('rtl'));
        } else {
            dispatch(toggleRTL('ltr'));
        }
        router.refresh();
    };

    const handleLogout = () => {
        dispatch(logout());
        Cookies.remove('token');    // Remove cookie do middleware
        router.push('/auth/login');
    };

    const handleLockScreen = () => {
        dispatch(lockScreen());
        router.push('/auth/lockscreen');
    };

    return (
        <header className={`z-40 ${themeConfig.semidark && themeConfig.menu === 'horizontal' ? 'dark' : ''}`}>
            <div className="shadow-sm">
                <div className="relative flex w-full items-center bg-white px-5 py-2.5 dark:bg-black">
                    <div className="horizontal-logo flex items-center justify-between ltr:mr-2 rtl:ml-2 lg:hidden">
                        <Link href="/" className="main-logo flex shrink-0 items-center">
                            <img className="inline w-8 ltr:-ml-1 rtl:-mr-1" src="/assets/images/logo.svg" alt="logo" />
                            <span className="hidden align-middle text-2xl  font-semibold  transition-all duration-300 ltr:ml-1.5 rtl:mr-1.5 dark:text-white-light md:inline">UDOMIN</span>
                        </Link>
                        <button
                            type="button"
                            className="collapse-icon flex flex-none rounded-full bg-white-light/40 p-2 hover:bg-white-light/90 hover:text-primary ltr:ml-2 rtl:mr-2 dark:bg-dark/40 dark:text-[#d0d2d6] dark:hover:bg-dark/60 dark:hover:text-primary lg:hidden"
                            onClick={() => dispatch(toggleSidebar())}
                        >
                            <IconMenu className="h-5 w-5" />
                        </button>
                    </div>

                    <div className="hidden ltr:mr-2 rtl:ml-2 sm:block">

                    </div>
                    <div className="flex items-center space-x-1.5 ltr:ml-auto rtl:mr-auto rtl:space-x-reverse dark:text-[#d0d2d6] sm:flex-1 ltr:sm:ml-0 sm:rtl:mr-0 lg:space-x-2">
                        <div className="sm:ltr:mr-auto sm:rtl:ml-auto">
                            <form
                                className={`absolute inset-x-0 top-1/2 z-10 mx-4 hidden -translate-y-1/2 sm:relative sm:top-0 sm:mx-0 sm:block sm:translate-y-0`}
                            >
                            </form>
                            <button
                                type="button"
                                className="search_btn rounded-full bg-white-light/40 p-2 hover:bg-white-light/90 dark:bg-dark/40 dark:hover:bg-dark/60 sm:hidden"
                            >
                                <IconSearch className="mx-auto h-4.5 w-4.5 dark:text-[#d0d2d6]" />
                            </button>
                        </div>
                        <div>
                            {themeConfig.theme === 'light' ? (
                                <button
                                    className={`${themeConfig.theme === 'light' &&
                                        'flex items-center rounded-full bg-white-light/40 p-2 hover:bg-white-light/90 hover:text-primary dark:bg-dark/40 dark:hover:bg-dark/60'
                                        }`}
                                    onClick={() => dispatch(toggleTheme('dark'))}
                                >
                                    <IconSun />
                                </button>
                            ) : (
                                ''
                            )}
                            {themeConfig.theme === 'dark' && (
                                <button
                                    className={`${themeConfig.theme === 'dark' &&
                                        'flex items-center rounded-full bg-white-light/40 p-2 hover:bg-white-light/90 hover:text-primary dark:bg-dark/40 dark:hover:bg-dark/60'
                                        }`}
                                    onClick={() => dispatch(toggleTheme('system'))}
                                >
                                    <IconMoon />
                                </button>
                            )}
                            {themeConfig.theme === 'system' && (
                                <button
                                    className={`${themeConfig.theme === 'system' &&
                                        'flex items-center rounded-full bg-white-light/40 p-2 hover:bg-white-light/90 hover:text-primary dark:bg-dark/40 dark:hover:bg-dark/60'
                                        }`}
                                    onClick={() => dispatch(toggleTheme('light'))}
                                >
                                    <IconLaptop />
                                </button>
                            )}
                        </div>
                        <div className="dropdown shrink-0">
                            <Dropdown
                                offset={[0, 8]}
                                placement={`${isRtl ? 'bottom-start' : 'bottom-end'}`}
                                btnClassName="block p-2 rounded-full bg-white-light/40 dark:bg-dark/40 hover:text-primary hover:bg-white-light/90 dark:hover:bg-dark/60"
                                button={i18n.language && <img className="h-5 w-5 rounded-full object-cover" src={`/assets/images/flags/${i18n.language.toUpperCase()}.svg`} alt="flag" />}
                            >
                                <ul className="grid w-[280px] grid-cols-2 gap-2 !px-2 font-semibold text-dark dark:text-white-dark dark:text-white-light/90">
                                    {themeConfig.languageList.map((item: any) => {
                                        return (
                                            <li key={item.code}>
                                                <button
                                                    type="button"
                                                    className={`flex w-full hover:text-primary ${i18n.language === item.code ? 'bg-primary/10 text-primary' : ''}`}
                                                    onClick={() => {
                                                        i18n.changeLanguage(item.code);
                                                        setLocale(item.code);
                                                    }}
                                                >
                                                    <img src={`/assets/images/flags/${item.code.toUpperCase()}.svg`} alt="flag" className="h-5 w-5 rounded-full object-cover" />
                                                    <span className="ltr:ml-3 rtl:mr-3">{item.name}</span>
                                                </button>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </Dropdown>
                        </div>
                        <div className="dropdown flex shrink-0">
                            <Dropdown
                                offset={[0, 8]}
                                placement={`${isRtl ? 'bottom-start' : 'bottom-end'}`}
                                btnClassName="relative group block"
                                button={<img className="h-9 w-9 rounded-full object-cover saturate-50 group-hover:saturate-100" src={
                                    user.avatar
                                        ? `${process.env.NEXT_PUBLIC_API_URL}/storage/${user.avatar}`
                                        : "/assets/images/user-profile.jpeg"
                                } alt="userProfile" />}
                            >
                                <ul className="w-[230px] !py-0 font-semibold text-dark dark:text-white-dark dark:text-white-light/90">
                                    <li>
                                        <div className="flex items-center px-4 py-4">
                                            <img className="h-10 w-10 rounded-md object-cover" src={
                                                user.avatar
                                                    ? `${process.env.NEXT_PUBLIC_API_URL}/storage/${user.avatar}`
                                                    : "/assets/images/user-profile.jpeg"
                                            } alt="userProfile" />
                                            <div className="truncate ltr:pl-4 rtl:pr-4">
                                                <h4 className="text-base">
                                                    {user?.name}
                                                    {/* <span className="rounded bg-success-light px-1 text-xs text-success ltr:ml-2 rtl:ml-2">Pro</span> */}
                                                </h4>
                                                <button type="button" className="text-black/60 hover:text-primary dark:text-dark-light/60 dark:hover:text-white">
                                                    {user?.email}
                                                </button>
                                            </div>
                                        </div>
                                    </li>
                                    <li>
                                        <Link href={`/users/${user.id}`} className="dark:hover:text-white">
                                            <IconUser className="h-4.5 w-4.5 shrink-0 ltr:mr-2 rtl:ml-2" />
                                            Profile
                                        </Link>
                                    </li>
                                    <li>
                                        <button onClick={handleLockScreen} className="dark:hover:text-white">
                                            <IconLockDots className="h-4.5 w-4.5 shrink-0 ltr:mr-2 rtl:ml-2" />
                                            Lock Screen
                                        </button>
                                    </li>
                                    <li className="border-t border-white-light dark:border-white-light/10">
                                        <button onClick={handleLogout} className="!py-3 text-danger">
                                            <IconLogout className="h-4.5 w-4.5 shrink-0 rotate-90 ltr:mr-2 rtl:ml-2" />
                                            Sign Out
                                        </button>
                                    </li>
                                </ul>
                            </Dropdown>
                        </div>
                    </div>
                </div>

                {/* horizontal menu */}
                <ul className="horizontal-menu hidden border-t border-[#ebedf2] bg-white px-6 py-1.5 font-semibold text-black rtl:space-x-reverse dark:border-[#191e3a] dark:bg-black dark:text-white-dark lg:space-x-1.5 xl:space-x-8">
                    <li className="menu nav-item relative">
                        <button type="button" className="nav-link">
                            <div className="flex items-center">
                                <IconMenuDashboard className="shrink-0" />
                                <span className="px-1">{t('dashboard')}</span>
                            </div>
                            <div className="right_arrow">
                                <IconCaretDown />
                            </div>
                        </button>
                        <ul className="sub-menu">
                            <li>
                                <Link href="/">{t('sales')}</Link>
                            </li>
                            <li>
                                <Link href="/analytics">{t('analytics')}</Link>
                            </li>
                            <li>
                                <Link href="/finance">{t('finance')}</Link>
                            </li>
                            <li>
                                <Link href="/crypto">{t('crypto')}</Link>
                            </li>
                        </ul>
                    </li>
                    <li className="menu nav-item relative">
                        <button type="button" className="nav-link">
                            <div className="flex items-center">
                                <IconMenuApps className="shrink-0" />
                                <span className="px-1">{t('apps')}</span>
                            </div>
                            <div className="right_arrow">
                                <IconCaretDown />
                            </div>
                        </button>
                        <ul className="sub-menu">
                            <li>
                                <Link href="/apps/chat">{t('chat')}</Link>
                            </li>
                            <li>
                                <Link href="/apps/mailbox">{t('mailbox')}</Link>
                            </li>
                            <li>
                                <Link href="/apps/todolist">{t('todo_list')}</Link>
                            </li>
                            <li>
                                <Link href="/apps/notes">{t('notes')}</Link>
                            </li>
                            <li>
                                <Link href="/apps/scrumboard">{t('scrumboard')}</Link>
                            </li>
                            <li>
                                <Link href="/apps/contacts">{t('contacts')}</Link>
                            </li>
                            <li className="relative">
                                <button type="button">
                                    {t('invoice')}
                                    <div className="-rotate-90 ltr:ml-auto rtl:mr-auto rtl:rotate-90">
                                        <IconCaretDown />
                                    </div>
                                </button>
                                <ul className="absolute top-0 z-[10] hidden min-w-[180px] rounded bg-white p-0 py-2 text-dark shadow ltr:left-[95%] rtl:right-[95%] dark:bg-[#1b2e4b] dark:text-white-dark">
                                    <li>
                                        <Link href="/apps/invoice/list">{t('list')}</Link>
                                    </li>
                                    <li>
                                        <Link href="/apps/invoice/preview">{t('preview')}</Link>
                                    </li>
                                    <li>
                                        <Link href="/apps/invoice/add">{t('add')}</Link>
                                    </li>
                                    <li>
                                        <Link href="/apps/invoice/edit">{t('edit')}</Link>
                                    </li>
                                </ul>
                            </li>
                            <li>
                                <Link href="/apps/calendar">{t('calendar')}</Link>
                            </li>
                        </ul>
                    </li>
                    <li className="menu nav-item relative">
                        <button type="button" className="nav-link">
                            <div className="flex items-center">
                                <IconMenuComponents className="shrink-0" />
                                <span className="px-1">{t('components')}</span>
                            </div>
                            <div className="right_arrow">
                                <IconCaretDown />
                            </div>
                        </button>
                        <ul className="sub-menu">
                            <li>
                                <Link href="/components/tabs">{t('tabs')}</Link>
                            </li>
                            <li>
                                <Link href="/components/accordions">{t('accordions')}</Link>
                            </li>
                            <li>
                                <Link href="/components/modals">{t('modals')}</Link>
                            </li>
                            <li>
                                <Link href="/components/cards">{t('cards')}</Link>
                            </li>
                            <li>
                                <Link href="/components/carousel">{t('carousel')}</Link>
                            </li>
                            <li>
                                <Link href="/components/countdown">{t('countdown')}</Link>
                            </li>
                            <li>
                                <Link href="/components/counter">{t('counter')}</Link>
                            </li>
                            <li>
                                <Link href="/components/sweetalert">{t('sweet_alerts')}</Link>
                            </li>
                            <li>
                                <Link href="/components/timeline">{t('timeline')}</Link>
                            </li>
                            <li>
                                <Link href="/components/notifications">{t('notifications')}</Link>
                            </li>
                            <li>
                                <Link href="/components/media-object">{t('media_object')}</Link>
                            </li>
                            <li>
                                <Link href="/components/list-group">{t('list_group')}</Link>
                            </li>
                            <li>
                                <Link href="/components/pricing-table">{t('pricing_tables')}</Link>
                            </li>
                            <li>
                                <Link href="/components/lightbox">{t('lightbox')}</Link>
                            </li>
                        </ul>
                    </li>
                    <li className="menu nav-item relative">
                        <button type="button" className="nav-link">
                            <div className="flex items-center">
                                <IconMenuElements className="shrink-0" />
                                <span className="px-1">{t('elements')}</span>
                            </div>
                            <div className="right_arrow">
                                <IconCaretDown />
                            </div>
                        </button>
                        <ul className="sub-menu">
                            <li>
                                <Link href="/elements/alerts">{t('alerts')}</Link>
                            </li>
                            <li>
                                <Link href="/elements/avatar">{t('avatar')}</Link>
                            </li>
                            <li>
                                <Link href="/elements/badges">{t('badges')}</Link>
                            </li>
                            <li>
                                <Link href="/elements/breadcrumbs">{t('breadcrumbs')}</Link>
                            </li>
                            <li>
                                <Link href="/elements/buttons">{t('buttons')}</Link>
                            </li>
                            <li>
                                <Link href="/elements/buttons-group">{t('button_groups')}</Link>
                            </li>
                            <li>
                                <Link href="/elements/color-library">{t('color_library')}</Link>
                            </li>
                            <li>
                                <Link href="/elements/dropdown">{t('dropdown')}</Link>
                            </li>
                            <li>
                                <Link href="/elements/infobox">{t('infobox')}</Link>
                            </li>
                            <li>
                                <Link href="/elements/jumbotron">{t('jumbotron')}</Link>
                            </li>
                            <li>
                                <Link href="/elements/loader">{t('loader')}</Link>
                            </li>
                            <li>
                                <Link href="/elements/pagination">{t('pagination')}</Link>
                            </li>
                            <li>
                                <Link href="/elements/popovers">{t('popovers')}</Link>
                            </li>
                            <li>
                                <Link href="/elements/progress-bar">{t('progress_bar')}</Link>
                            </li>
                            <li>
                                <Link href="/elements/search">{t('search')}</Link>
                            </li>
                            <li>
                                <Link href="/elements/tooltips">{t('tooltips')}</Link>
                            </li>
                            <li>
                                <Link href="/elements/treeview">{t('treeview')}</Link>
                            </li>
                            <li>
                                <Link href="/elements/typography">{t('typography')}</Link>
                            </li>
                        </ul>
                    </li>
                    <li className="menu nav-item relative">
                        <button type="button" className="nav-link">
                            <div className="flex items-center">
                                <IconMenuDatatables className="shrink-0" />
                                <span className="px-1">{t('tables')}</span>
                            </div>
                            <div className="right_arrow">
                                <IconCaretDown />
                            </div>
                        </button>
                        <ul className="sub-menu">
                            <li>
                                <Link href="/tables">{t('tables')}</Link>
                            </li>
                            <li className="relative">
                                <button type="button">
                                    {t('datatables')}
                                    <div className="-rotate-90 ltr:ml-auto rtl:mr-auto rtl:rotate-90">
                                        <IconCaretDown />
                                    </div>
                                </button>
                                <ul className="absolute top-0 z-[10] hidden min-w-[180px] rounded bg-white p-0 py-2 text-dark shadow ltr:left-[95%] rtl:right-[95%] dark:bg-[#1b2e4b] dark:text-white-dark">
                                    <li>
                                        <Link href="/datatables/basic">{t('basic')}</Link>
                                    </li>
                                    <li>
                                        <Link href="/datatables/advanced">{t('advanced')}</Link>
                                    </li>
                                    <li>
                                        <Link href="/datatables/skin">{t('skin')}</Link>
                                    </li>
                                    <li>
                                        <Link href="/datatables/order-sorting">{t('order_sorting')}</Link>
                                    </li>
                                    <li>
                                        <Link href="/datatables/multi-column">{t('multi_column')}</Link>
                                    </li>
                                    <li>
                                        <Link href="/datatables/multiple-tables">{t('multiple_tables')}</Link>
                                    </li>
                                    <li>
                                        <Link href="/datatables/alt-pagination">{t('alt_pagination')}</Link>
                                    </li>
                                    <li>
                                        <Link href="/datatables/checkbox">{t('checkbox')}</Link>
                                    </li>
                                    <li>
                                        <Link href="/datatables/range-search">{t('range_search')}</Link>
                                    </li>
                                    <li>
                                        <Link href="/datatables/export">{t('export')}</Link>
                                    </li>
                                    <li>
                                        <Link href="/datatables/column-chooser">{t('column_chooser')}</Link>
                                    </li>
                                </ul>
                            </li>
                        </ul>
                    </li>
                    <li className="menu nav-item relative">
                        <button type="button" className="nav-link">
                            <div className="flex items-center">
                                <IconMenuForms className="shrink-0" />
                                <span className="px-1">{t('forms')}</span>
                            </div>
                            <div className="right_arrow">
                                <IconCaretDown />
                            </div>
                        </button>
                        <ul className="sub-menu">
                            <li>
                                <Link href="/forms/basic">{t('basic')}</Link>
                            </li>
                            <li>
                                <Link href="/forms/input-group">{t('input_group')}</Link>
                            </li>
                            <li>
                                <Link href="/forms/layouts">{t('layouts')}</Link>
                            </li>
                            <li>
                                <Link href="/forms/validation">{t('validation')}</Link>
                            </li>
                            <li>
                                <Link href="/forms/input-mask">{t('input_mask')}</Link>
                            </li>
                            <li>
                                <Link href="/forms/select2">{t('select2')}</Link>
                            </li>
                            <li>
                                <Link href="/forms/touchspin">{t('touchspin')}</Link>
                            </li>
                            <li>
                                <Link href="/forms/checkbox-radio">{t('checkbox_and_radio')}</Link>
                            </li>
                            <li>
                                <Link href="/forms/switches">{t('switches')}</Link>
                            </li>
                            <li>
                                <Link href="/forms/wizards">{t('wizards')}</Link>
                            </li>
                            <li>
                                <Link href="/forms/file-upload">{t('file_upload')}</Link>
                            </li>
                            <li>
                                <Link href="/forms/quill-editor">{t('quill_editor')}</Link>
                            </li>
                            <li>
                                <Link href="/forms/markdown-editor">{t('markdown_editor')}</Link>
                            </li>
                            <li>
                                <Link href="/forms/date-picker">{t('date_and_range_picker')}</Link>
                            </li>
                            <li>
                                <Link href="/forms/clipboard">{t('clipboard')}</Link>
                            </li>
                        </ul>
                    </li>
                    <li className="menu nav-item relative">
                        <button type="button" className="nav-link">
                            <div className="flex items-center">
                                <IconMenuPages className="shrink-0" />
                                <span className="px-1">{t('pages')}</span>
                            </div>
                            <div className="right_arrow">
                                <IconCaretDown />
                            </div>
                        </button>
                        <ul className="sub-menu">
                            <li className="relative">
                                <button type="button">
                                    {t('users')}
                                    <div className="-rotate-90 ltr:ml-auto rtl:mr-auto rtl:rotate-90">
                                        <IconCaretDown />
                                    </div>
                                </button>
                                <ul className="absolute top-0 z-[10] hidden min-w-[180px] rounded bg-white p-0 py-2 text-dark shadow ltr:left-[95%] rtl:right-[95%] dark:bg-[#1b2e4b] dark:text-white-dark">
                                    <li>
                                        <Link href="/users/profile">{t('profile')}</Link>
                                    </li>
                                    <li>
                                        <Link href="/users/user-account-settings">{t('account_settings')}</Link>
                                    </li>
                                </ul>
                            </li>
                            <li>
                                <Link href="/pages/knowledge-base">{t('knowledge_base')}</Link>
                            </li>
                            <li>
                                <Link href="/pages/contact-us-boxed" target="_blank">
                                    {t('contact_us_boxed')}
                                </Link>
                            </li>
                            <li>
                                <Link href="/pages/contact-us-cover" target="_blank">
                                    {t('contact_us_cover')}
                                </Link>
                            </li>
                            <li>
                                <Link href="/pages/faq">{t('faq')}</Link>
                            </li>
                            <li>
                                <Link href="/pages/coming-soon-boxed" target="_blank">
                                    {t('coming_soon_boxed')}
                                </Link>
                            </li>
                            <li>
                                <Link href="/pages/coming-soon-cover" target="_blank">
                                    {t('coming_soon_cover')}
                                </Link>
                            </li>
                            <li>
                                <Link href="/pages/maintenence" target="_blank">
                                    {t('maintenence')}
                                </Link>
                            </li>
                            <li className="relative">
                                <button type="button">
                                    {t('error')}
                                    <div className="-rotate-90 ltr:ml-auto rtl:mr-auto rtl:rotate-90">
                                        <IconCaretDown />
                                    </div>
                                </button>
                                <ul className="absolute top-0 z-[10] hidden min-w-[180px] rounded bg-white p-0 py-2 text-dark shadow ltr:left-[95%] rtl:right-[95%] dark:bg-[#1b2e4b] dark:text-white-dark">
                                    <li>
                                        <Link href="/pages/error404" target="_blank">
                                            {t('404')}
                                        </Link>
                                    </li>
                                    <li>
                                        <Link href="/pages/error500" target="_blank">
                                            {t('500')}
                                        </Link>
                                    </li>
                                    <li>
                                        <Link href="/pages/error503" target="_blank">
                                            {t('503')}
                                        </Link>
                                    </li>
                                </ul>
                            </li>
                            <li className="relative">
                                <button type="button">
                                    {t('login')}
                                    <div className="-rotate-90 ltr:ml-auto rtl:mr-auto rtl:rotate-90">
                                        <IconCaretDown />
                                    </div>
                                </button>
                                <ul className="absolute top-0 z-[10] hidden min-w-[180px] rounded bg-white p-0 py-2 text-dark shadow ltr:left-[95%] rtl:right-[95%] dark:bg-[#1b2e4b] dark:text-white-dark">
                                    <li>
                                        <Link href="/auth/cover-login" target="_blank">
                                            {t('login_cover')}
                                        </Link>
                                    </li>
                                    <li>
                                        <Link href="/auth/boxed-signin" target="_blank">
                                            {t('login_boxed')}
                                        </Link>
                                    </li>
                                </ul>
                            </li>
                            <li className="relative">
                                <button type="button">
                                    {t('register')}
                                    <div className="-rotate-90 ltr:ml-auto rtl:mr-auto rtl:rotate-90">
                                        <IconCaretDown />
                                    </div>
                                </button>
                                <ul className="absolute top-0 z-[10] hidden min-w-[180px] rounded bg-white p-0 py-2 text-dark shadow ltr:left-[95%] rtl:right-[95%] dark:bg-[#1b2e4b] dark:text-white-dark">
                                    <li>
                                        <Link href="/auth/cover-register" target="_blank">
                                            {t('register_cover')}
                                        </Link>
                                    </li>
                                    <li>
                                        <Link href="/auth/boxed-signup" target="_blank">
                                            {t('register_boxed')}
                                        </Link>
                                    </li>
                                </ul>
                            </li>
                            <li className="relative">
                                <button type="button">
                                    {t('password_recovery')}
                                    <div className="-rotate-90 ltr:ml-auto rtl:mr-auto rtl:rotate-90">
                                        <IconCaretDown />
                                    </div>
                                </button>
                                <ul className="absolute top-0 z-[10] hidden min-w-[180px] rounded bg-white p-0 py-2 text-dark shadow ltr:left-[95%] rtl:right-[95%] dark:bg-[#1b2e4b] dark:text-white-dark">
                                    <li>
                                        <Link href="/auth/cover-password-reset" target="_blank">
                                            {t('recover_id_cover')}
                                        </Link>
                                    </li>
                                    <li>
                                        <Link href="/auth/boxed-password-reset" target="_blank">
                                            {t('recover_id_boxed')}
                                        </Link>
                                    </li>
                                </ul>
                            </li>
                            <li className="relative">
                                <button type="button">
                                    {t('lockscreen')}
                                    <div className="-rotate-90 ltr:ml-auto rtl:mr-auto rtl:rotate-90">
                                        <IconCaretDown />
                                    </div>
                                </button>
                                <ul className="absolute top-0 z-[10] hidden min-w-[180px] rounded bg-white p-0 py-2 text-dark shadow ltr:left-[95%] rtl:right-[95%] dark:bg-[#1b2e4b] dark:text-white-dark">
                                    <li>
                                        <Link href="/auth/cover-lockscreen" target="_blank">
                                            {t('unlock_cover')}
                                        </Link>
                                    </li>
                                    <li>
                                        <Link href="/auth/boxed-lockscreen" target="_blank">
                                            {t('unlock_boxed')}
                                        </Link>
                                    </li>
                                </ul>
                            </li>
                        </ul>
                    </li>
                    <li className="menu nav-item relative">
                        <button type="button" className="nav-link">
                            <div className="flex items-center">
                                <IconMenuMore className="shrink-0" />
                                <span className="px-1">{t('more')}</span>
                            </div>
                            <div className="right_arrow">
                                <IconCaretDown />
                            </div>
                        </button>
                        <ul className="sub-menu">
                            <li>
                                <Link href="/dragndrop">{t('drag_and_drop')}</Link>
                            </li>
                            <li>
                                <Link href="/charts">{t('charts')}</Link>
                            </li>
                            <li>
                                <Link href="/font-icons">{t('font_icons')}</Link>
                            </li>
                            <li>
                                <Link href="/widgets">{t('widgets')}</Link>
                            </li>
                            <li>
                                <Link href="https://vristo.sbthemes.com" target="_blank">
                                    {t('documentation')}
                                </Link>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </header>
    );
};

export default Header;
