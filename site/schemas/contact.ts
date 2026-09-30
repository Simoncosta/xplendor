import { z } from "zod";

export const contactSchema = z.object({
  Name: z.string().min(2, "Indique o seu nome"),
  "E-mail": z.string().email("Email inválido"),
  Message: z.string().min(5, "Escreva a sua mensagem"),
});

export type ContactForm = z.infer<typeof contactSchema>;
