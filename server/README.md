# XPLENDOR

TODO: Backend Laravel
Campos recomendados
	•	custom_domain (nullable, unique)
Ex: autoelite.pt (sem https, sem www, sem path)
	•	custom_domain_verified_at (nullable timestamp)
Para só activares depois do cliente apontar DNS.

Opcional (mas bom):
	•	custom_domain_status enum: pending|verified|failed


🔥 Nome comercial: Xplendor SmartTarget -> Motor Estratégico por Viatura
🔥 Nome comercial: Xplendor AutoContent -> Automação de Conteúdo (Nano Banana)
🔥 Nome comercial: Xplendor SmartAds -> Inteligência de Investimento
🔥 Nome comercial: Xplendor LeadFlow -> Leads Inteligentes
--- 
PRIMEIRO DE TUDO: Armazena:
	•	impressions (por canal)
	•	clicks
	•	CTR
	•	CPC
	•	custo por carro
	•	custo por lead
	•	tempo até primeira lead
	•	tempo até venda
	•	margem real
	•	ROI por viatura

Tabela nova: car_performance_metrics

---
⚠️ Cuidado técnico importante
IA por viatura não pode demorar 20 segundos.
Sugestão:
	•	Processar async (queue Laravel)
	•	Gerar relatório
	•	Guardar JSON na tabela vehicle_insights
	•	Mostrar no dashboard
Não chamar OpenAI a cada visualização.

---
💡 Ideia forte para te diferenciar ainda mais
Adicionar:
Índice de Potencial de Venda (0-100)
Baseado em:
	•	Tipo
	•	Preço
	•	Segmento
	•	Procura média
	•	Histórico interno
Isso vira conversa comercial absurda.

---
Se clicar em 👎 → pergunta: “O que não gostou?”

---
🏁 Produto final da V1

Checklist final:

✔ Multiempresa
✔ Stock
✔ Landing por carro
✔ SmartTarget
✔ SmartAds Assistido
✔ LeadFlow básico
✔ Índice de Potencial