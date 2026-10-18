"use client";

import * as Accordion from "@radix-ui/react-accordion";
import { FAQ } from "@/content/textos";

/** As perguntas frequentes dos proprietários (rascunhos para validar com o contrato de mediação). */
export function Faq() {
    return (
        <section aria-labelledby="faq-titulo" className="section bg-cream-2">
            <div className="container-site grid gap-12 md:grid-cols-12">
                <div className="md:col-span-4">
                    <h2 id="faq-titulo" className="text-h2 max-w-[14ch]">{FAQ.titulo}</h2>
                    <span className="accent-rule" aria-hidden="true" />
                </div>
                <Accordion.Root type="single" collapsible className="border-t border-navy md:col-span-7 md:col-start-6">
                    {FAQ.perguntas.map((q, i) => (
                        <Accordion.Item key={q.p} value={`q${i}`} className="border-b border-line">
                            <Accordion.Header asChild>
                                <h3>
                                    <Accordion.Trigger className="group flex w-full items-start justify-between gap-6 py-6 text-left font-display text-[1.35rem] leading-snug md:text-[1.55rem]">
                                        {q.p}
                                        <span className="relative mt-2 h-4 w-4 shrink-0" aria-hidden="true">
                                            <span className="absolute left-0 top-1/2 h-px w-4 bg-current" />
                                            <span className="absolute left-1/2 top-0 h-4 w-px bg-current transition-transform duration-300 group-data-[state=open]:scale-y-0" />
                                        </span>
                                    </Accordion.Trigger>
                                </h3>
                            </Accordion.Header>
                            <Accordion.Content className="overflow-hidden data-[state=closed]:animate-[faq-up_.25s_ease] data-[state=open]:animate-[faq-down_.3s_ease]">
                                <p className="muted max-w-[62ch] pb-7">{q.r}</p>
                            </Accordion.Content>
                        </Accordion.Item>
                    ))}
                </Accordion.Root>
            </div>
        </section>
    );
}
