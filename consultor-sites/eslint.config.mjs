import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";

const eslintConfig = [
    ...nextVitals,
    ...nextTs,
    { ignores: ["node_modules/**", ".next/**", "next-env.d.ts", "tests/**"] },
];

export default eslintConfig;
