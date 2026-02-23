import PrismCode from "../../../Components/Common/Prism";

// Default List

const defaultListCode =
    `
<!-- Default List -->
<p><Link to="#" className="link-primary">Primary link</Link></p>

<p><Link to="#" className="link-secondary">Secondary link</Link></p>

<p><Link to="#" className="link-success">Success link</Link></p>

<p><Link to="#" className="link-danger">Danger link</Link></p>

<p><Link to="#" className="link-warning">Warning link</Link></p>

<p><Link to="#" className="link-info">Info link</Link></p>

<p><Link to="#" className="link-light">Light link</Link></p>

<p><Link to="#" className="text-body">Dark link</Link></p>

<p><Link to="#" className="link-body-emphasis mb-0">Emphasis link</Link></p>
`;

const DefaultLinkExample = () => (
    <PrismCode
        code={defaultListCode}
        language={("js")}
        plugins={["line-numbers"]}
    />
);


const utilitiesCode =
`
<p><Link to="#" className="link-primary link-offset-2 text-decoration-underline link-underline-opacity-25 link-underline-opacity-100-hover">Primary link</Link></p>

<p><Link to="#" className="link-secondary link-offset-2 text-decoration-underline link-underline-opacity-25 link-underline-opacity-100-hover">Secondary link</Link></p>

<p><Link to="#" className="link-success link-offset-2 text-decoration-underline link-underline-opacity-25 link-underline-opacity-100-hover">Success link</Link></p>

<p><Link to="#" className="link-danger link-offset-2 text-decoration-underline link-underline-opacity-25 link-underline-opacity-100-hover">Danger link</Link></p>

<p><Link to="#" className="link-warning link-offset-2 text-decoration-underline link-underline-opacity-25 link-underline-opacity-100-hover">Warning link</Link></p>

<p><Link to="#" className="link-info link-offset-2 text-decoration-underline link-underline-opacity-25 link-underline-opacity-100-hover">Info link</Link></p>

<p><Link to="#" className="link-light link-offset-2 text-decoration-underline link-underline-opacity-25 link-underline-opacity-100-hover">Light link</Link></p>

<p><Link to="#" className="text-body link-offset-2 text-decoration-underline link-underline-opacity-25 link-underline-opacity-100-hover">Dark link</Link></p>

<p><Link to="#" className="link-body-emphasis link-offset-2 text-decoration-underline link-underline-opacity-25 link-underline-opacity-75-hover mb-0">Emphasis link</Link></p>

`;
const UtilitiesExample = () => (
    <PrismCode
        code={utilitiesCode}
        language={("js")}
        plugins={["line-numbers"]}
    />
);

const opacityCode = 
`
<p><Link className="link-opacity-10" to="#">Link opacity 10</Link></p>

<p><Link className="link-opacity-25" to="#">Link opacity 25</Link></p>

<p><Link className="link-opacity-50" to="#">Link opacity 50</Link></p>

<p><Link className="link-opacity-75" to="#">Link opacity 75</Link></p>

<p className="mb-0"><Link className="link-opacity-100" to="#">Link opacity 100</Link></p>
`;
const OpacityExample = () => (
    <PrismCode
        code={opacityCode}
        language={("js")}
        plugins={["line-numbers"]}
    />
);

const opcacityhoverCode =
`
<p><Link className="link-opacity-10-hover" to="#">Link hover opacity 10</Link></p>

<p><Link className="link-opacity-25-hover" to="#">Link hover opacity 25</Link></p>

<p><Link className="link-opacity-50-hover" to="#">Link hover opacity 50</Link></p>

<p><Link className="link-opacity-75-hover" to="#">Link hover opacity 75</Link></p>

<p className="mb-0"><Link className="link-opacity-100-hover" to="#">Link hover opacity 100</Link></p>
`;
const OpacityHoverExample = () => (
    <PrismCode
        code={opcacityhoverCode}
        language={("js")}
        plugins={["line-numbers"]}
    />
);


const colorCode =
`
<p><Link to="#" className="text-decoration-underline link-underline-primary">Primary underline</Link></p>

<p><Link to="#" className="text-decoration-underline link-underline-secondary">Secondary underline</Link></p>

<p><Link to="#" className="text-decoration-underline link-underline-success">Success underline</Link></p>

<p><Link to="#" className="text-decoration-underline link-underline-danger">Danger underline</Link></p>

<p><Link to="#" className="text-decoration-underline link-underline-warning">Warning underline</Link></p>

<p><Link to="#" className="text-decoration-underline link-underline-info">Info underline</Link></p>

<p><Link to="#" className="text-decoration-underline link-underline-light">Light underline</Link></p>

<p className="mb-0"><Link to="#" className="text-decoration-underline link-underline-dark">Dark underline</Link></p>
`;
const ColorExample = () => (
    <PrismCode
        code={colorCode}
        language={("js")}
        plugins={["line-numbers"]}
    />
);


const utilityopacityCode =
`
<p><Link className="link-offset-2 text-decoration-underline link-underline link-underline-opacity-0" to="#">Underline opacity 0</Link></p>

<p><Link className="link-offset-2 text-decoration-underline link-underline link-underline-opacity-10" to="#">Underline opacity 10</Link></p>

<p><Link className="link-offset-2 text-decoration-underline link-underline link-underline-opacity-25" to="#">Underline opacity 25</Link></p>

<p><Link className="link-offset-2 text-decoration-underline link-underline link-underline-opacity-50" to="#">Underline opacity 50</Link></p>

<p><Link className="link-offset-2 text-decoration-underline link-underline link-underline-opacity-75" to="#">Underline opacity 75</Link></p>

<p className="mb-0"><Link className="link-offset-2 text-decoration-underline link-underline link-underline-opacity-100" to="#">Underline opacity 100</Link></p>
                           
`;
const UtilityOpacityExample = () => (
    <PrismCode
        code={ utilityopacityCode}
        language={("js")}
        plugins={["line-numbers"]}
    />
);

const offsetCode =
`
<p><Link to="#">Default link</Link></p>

<p><Link className="text-decoration-underline link-offset-1" to="#">Offset 1 link</Link></p>

<p><Link className="text-decoration-underline link-offset-2" to="#">Offset 2 link</Link></p>

<p className="mb-0"><Link className="text-decoration-underline link-offset-3" to="#">Offset 3 link</Link></p>
`;
const OffsetExample = () => (
    <PrismCode
        code={ offsetCode}
        language={("js")}
        plugins={["line-numbers"]}
    />
);
export { DefaultLinkExample, UtilitiesExample, OpacityExample, OpacityHoverExample, ColorExample, UtilityOpacityExample, OffsetExample } ;