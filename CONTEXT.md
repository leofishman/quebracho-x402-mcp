# quebracho-x402-mcp (nombre provisorio; alternativa `toll-mcp`)

Proyecto para el **Symfony AI & MCP Hackathon** (26/10–22/11/2026). **Entrega: repo público + video de 90 s antes del domingo 22/11 18:00 CET (14:30 ART).** Margen propio: entregar el 20/11. Equipo "Ombu", Leo solo.

## Qué es
Un servidor MCP en Symfony (`mcp/sdk` + MCP Bundle) cuyas tools se **cobran por x402**, y un agente Symfony AI que **descubre, paga y llama** la tool. Un agente que le compra a otro agente, en PHP. Licencia MIT.

## Fuente de verdad (leer antes de codear)
- Propuesta técnica, verificada vs no verificada: `/home/leo/Proyects/_hub/knowledge/hackathon-symfony-propuesta-mcp.md`
- Reglas del hackathon y alcance: `/home/leo/Proyects/_hub/knowledge/hackathon-symfony.md`
- Casos de falla a replicar como tests: `/home/leo/Proyects/_hub/knowledge/x402-conformance-analisis.md` (leer como especificación; **no instalar** el paquete de Ryan)
- Metodología y decisiones: `/home/leo/Proyects/_hub/METODOLOGIA.md`, `decisions/0023`, `0026`, `0027`

## Estructura
- `packages/x402/`: librería sin Drupal (namespace `Quebracho\X402`, solo PSR + gmp/sodium/keccak/secp256k1). `X402Middleware` (PSR-15) y `PayingHttpClient` (PSR-18).
- `app/`: Symfony con `symfony/ai-bundle` y `symfony/mcp-bundle`; sirve de servidor MCP y de cliente (`bin/console app:ask`).
- Origen del código de firma: `/home/leo/Proyects/ombu/web/modules/contrib/x402_pay/src` (Eip712, Signer, Transfer, Rpc, PaymentHeaders, Networks, Buyer). Copiar cambiando namespace y Guzzle→PSR-18; no copiar lo atado a Drupal.

## Reglas duras
- **Solo testnet** (Base Sepolia por defecto; Arc testnet opcional). Nada de claves ni wallets de producción de Ombú, nada de Laya, nada de Concordium.
- Claves de prueba solo en `.env.local` (ignorado por git). Secretos reales: Vaultwarden, nunca en el repo.
- Modo `X402_FACILITATOR=mock` (firma real, sin settle) para que cualquiera lo corra sin fondos.
- Fijar versión exacta de `mcp/sdk` (experimental hasta 1.0) en `composer.lock`.
- Verificar el efecto, no el mecanismo: un test que pasa no prueba que el 402 llegue al cliente; correr el flujo de punta a punta.
- Sin Doctrine ni frontend. Tests PHPUnit sin red. README: de `git clone` a la demo en 5 minutos.

## Plan (fechas de la propuesta)
28–29/10 repo + `symfony new` + tool gratis en MCP Inspector · 30/10–1/11 `packages/x402` + tests · 2–3/11 middleware 402 · 4–5/11 `PayingHttpClient` · 6–8/11 verificación y settle en testnet + agente HF · **9/11 corte:** si no anda de punta a punta, facilitator mock y a README/video · 10–15/11 tope de gasto, errores, `demo.sh`, README · 16–19/11 video y CI mínima · 20/11 entrega.

## Estado
2026-10-28: esqueleto + `app/` con Symfony 8.1.9, `symfony/mcp-bundle` 0.15.0, `mcp/sdk` **0.9.0 fijado**, `nyholm/psr7`. Tool gratis `echo` (`app/src/Mcp/EchoTool.php`) responde por HTTP en `/mcp` (verificado con `bin/smoke.sh`: initialize → tools/call). DDEV sin DB vía `omit_containers: [db]` (`database: none` no es válido). `packages/x402`: copiados Eip712, Signer, Transfer, PaymentHeaders, Networks, InvalidPaymentException (namespace `Quebracho\X402`, sin Drupal ni Guzzle); `ext-gmp` agregado a DDEV; `SignerTest` (vector conocido de web3.js) pasa. Próximo: tests de Eip712/EIP-3009 contra vectores conocidos, luego middleware 402. MCP Inspector sin probar.

## Prioridad (Leo, 2026-10-28)
El foco es **x402** (`packages/x402`, sus tests y el flujo de pago). El servidor MCP es la envoltura mínima que exige el hackathon: un middleware y una o dos tools pagas, nada más. Si el tiempo aprieta, se recorta el agente y la app, no la librería.

## Hugging Face (hub, 2026-10-28)
- Cupón USD 20 de HF Inference Providers (vence ~27/11). **No verificado** que esté acreditado ni que el token tenga permiso "Inference Providers": comprobar con una llamada mínima antes de depender de eso.
- Desarrollo y tests contra modelo local (bridge Generic, llama.cpp); cupón solo para la demo y el video. Modelo candidato: `openai/gpt-oss-120b` (tool calling sin verificar).
- Clave HF solo en `.env.local` y Vaultwarden.
- Pendiente: tope de gasto HF en la demo (límite de tokens/presupuesto por llamada del agente).
- decisions/0028: commits, PRs, issues y comentarios de código en inglés.

## Decisiones
- 2026-10-28 (Leo): pagos se registran solo en log (canal monolog `payments` → `app/var/log/payments.log`, JSON por línea). Sin DB: no se consulta. Implementar junto con el middleware 402.
