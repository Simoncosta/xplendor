# X-TAG

Isto vai ser o único código obrigatório no site do cliente:
```js
<script>
        window.xplendor = window.xplendor || function () { (window.xplendor.q = window.xplendor.q || []).push(arguments); };
</script>

<script src="https://xplendor.tech/xplendor.js" defer></script>

<script>
	window.addEventListener("load", function () {
		window.xplendor("init", {
			token: "496eca6a-4146-47d6-89ef-abc797813517",
			api_base: "https://xplendor.tech",
			debug: false,
			auto_page_view: true,
		});
	});
</script>
```

Depois, no site, em pontos específicos:
```js
<script>
    window.xplendor('event', 'car_view', { car_id: 123 });
    window.xplendor('event', 'lead', { car_id: 123, name, email, phone, message });

	// interaction com car_id explícito
	window.xplendor("interaction", "whatsapp_click", {
		car_id: carId,
		interaction_target: "car_detail",
		whatsapp_number: phone,
		page_context: "primary_cta",
		meta: {
			button_label: "Falar no WhatsApp"
		}
	});

	// Tenta usar o último carro visto e limpa depois:
	window.xplendor("interaction", "whatsapp_click", {
		interaction_target: "floating_button",
		whatsapp_number: phone,
		meta: {
			button_label: "WhatsApp flutuante"
		}
	});

	// Mostrar telefone
	window.xplendor("interaction", "show_phone", {
		interaction_target: "sticky_cta",
		phone: phone
	});
</script>
```