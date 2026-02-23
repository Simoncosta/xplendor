// import React, { useEffect, useRef } from "react";
// import Prism from "prismjs";

// const PrismCode = (props : any) => {
//     const ref : any = useRef();

//     useEffect(() => {
//         highlight();
//     }, []);

//     const highlight = () => {
//         if (ref && ref.current) {
//             Prism.highlightElement(ref.current);
//         }
//     };

//     const { code, language } = props;
//     return (
//         <React.Fragment>
//             <pre className="line-numbers">
//                 <code ref={ref} className={`language-${language}`}>
//                     {code.trim()}
//                 </code>
//             </pre>
//         </React.Fragment>
//     );
// };

// export default PrismCode;

import React, { useEffect, useRef } from "react";
import Prism from "prismjs";

interface PrismCodeProps {
    code: string;
    language?: string;
    plugins?: string[];
}

const PrismCode = (props: PrismCodeProps) => {
    const ref = useRef<HTMLElement>(null);

    useEffect(() => {
        highlight();
    }, []);

    const highlight = () => {
        if (ref && ref.current) {
            Prism.highlightElement(ref.current);
        }
    };

    const { code, language = "markup", plugins = [] } = props;
    return (
        <React.Fragment>
            <pre className={`line-numbers ${plugins.includes('line-numbers') ? 'line-numbers' : ''}`}>
                <code ref={ref} className={`language-${language}`}>
                    {code.trim()}
                </code>
            </pre>
        </React.Fragment>
    );
};

export default PrismCode;