export interface Submenu {
  label: string;
  href: string;
  active?: boolean;
}
export interface MenuItem {
  title: string;
  href?: string;
  submenu?: Submenu[];
}
