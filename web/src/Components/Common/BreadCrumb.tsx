import PageHeader from "./PageHeader";

/** Compatibilidade: o cabeçalho antigo passa a ser o PageHeader (o mesmo aspeto em toda a app). */
interface BreadCrumbProps {
    title: string;
    pageTitle: string;
    pageLink?: string;
}

const BreadCrumb = ({ title, pageTitle, pageLink }: BreadCrumbProps) => (
    <PageHeader title={title} breadcrumbs={[{ label: pageTitle, to: pageLink }]} />
);

export default BreadCrumb;
