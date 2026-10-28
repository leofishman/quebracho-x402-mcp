# AGENTS.md — quebracho-x402-mcp

1. Leé `CONTEXT.md` si existe (es local, fuera de git) y lo que enlaza, y `DECISIONS.md`, antes de tocar código.
2. Entorno: DDEV (`ddev start`; PHP corre dentro: `ddev composer …`, `ddev exec php …`, `ddev exec vendor/bin/phpunit`). No hay PHP en el host.
3. Estilo: Symfony idiomático (atributos, DI por constructor, tipado estricto), PSR en la librería, nada de dependencias que un par de líneas reemplacen.
4. Cada paso del plan termina con su criterio de "hecho cuando" verificado y registrado. No dar por hecho lo que solo compila.
5. Nunca claves reales ni mainnet. Dudas de seguridad o de dinero: parar y preguntarle a Leo.
6. Antes de cerrar: actualizar `CONTEXT.md` (sección Estado) y avisar al hub (`/home/leo/Proyects/_hub`, sesión del hub) con un resumen de 3 líneas: qué cambió, qué se verificó, qué sigue.
7. No commitear ni hacer push sin que Leo lo pida (el repo es público: revisar que no haya secretos antes).
