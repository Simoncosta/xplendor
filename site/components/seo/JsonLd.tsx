/**
 * Dados estruturados schema.org em JSON-LD (um <script> por objeto). O "<" é
 * escapado para o conteúdo nunca fechar o <script> antes do tempo.
 */
export default function JsonLd({ data }: { data: object | object[] }) {
  const items = Array.isArray(data) ? data : [data];
  return (
    <>
      {items.map((item, i) => (
        <script
          key={i}
          type="application/ld+json"
          dangerouslySetInnerHTML={{ __html: JSON.stringify(item).replace(/</g, "\\u003c") }}
        />
      ))}
    </>
  );
}
