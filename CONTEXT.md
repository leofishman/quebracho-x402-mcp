# quebracho-x402-mcp (nombre provisorio; alternativa `toll-mcp`)

Proyecto para el **Symfony AI & MCP Hackathon** (26/10–22/11/2026). **Entrega: repo público + video de 90 s antes del domingo 22/11 18:00 CET (14:30 ART).** Margen propio: entregar el 20/11. Equipo "Ombu", Leo solo.

## Qué es
Un servidor MCP en Symfony (`mcp/sdk` + MCP Bundle) cuyas tools se **cobran por x402**, y un agente Symfony AI que **descubre, paga y llama** la tool. Un agente que le compra a otro agente, en PHP. Licencia MIT.

## Fuente de verdad (leer antes de codear)
- Propuesta técnica, verificada vs no verificada: `/home/leo/Proyects/_hub/knowledge/hackathon-symfony-propuesta-mcp.md`
- Reglas del hackathon y alcance: `/home/leo/Proyects/_hub/knowledge/hackathon-symfony.md`
- Casos de falla a replicar como tests: `/home/leo/Proyects/_hub/knowledge/x402-conformance-analisis.md` (leer como especificación; **no instalar** el paquete de Ryan)
- Metodología y decisiones: `/home/leo/Proyects/_hub/METODOLOGIA.md`, `decisions/0023`, `0026`, `0027`
- Decisiones de este repo: `DECISIONS.md` (D1–D4)

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

## Roadmap extra
- Solana devnet como `SolanaExact`: portar `Solana.php`/`SolanaKey.php` de x402_pay (blockhash vía RPC inyectado en el constructor; verify offline decodificando la transacción). Después del middleware 402.
- NEAR (`near:testnet`) como segunda familia de `Scheme`: delegate action NEP-366 firmada ed25519 (ext-sodium) + relayer. Estimado 3–5 días, sin verificar. Investigación/borrador delegable a Jules en una rama; entra solo si el flujo EVM anda el 9/11.

## Plan (fechas de la propuesta)
28–29/10 repo + `symfony new` + tool gratis en MCP Inspector · 30/10–1/11 `packages/x402` + tests · 2–3/11 middleware 402 · 4–5/11 `PayingHttpClient` · 6–8/11 verificación y settle en testnet + agente HF · **9/11 corte:** si no anda de punta a punta, facilitator mock y a README/video · 10–15/11 tope de gasto, errores, `demo.sh`, README · 16–19/11 video y CI mínima · 20/11 entrega.

## Estado
2026-10-28: esqueleto + `app/` con Symfony 8.1.9, `symfony/mcp-bundle` 0.15.0, `mcp/sdk` **0.9.0 fijado**, `nyholm/psr7`. Tool gratis `echo` (`app/src/Mcp/EchoTool.php`) responde por HTTP en `/mcp` (verificado con `bin/smoke.sh`: initialize → tools/call). DDEV sin DB vía `omit_containers: [db]` (`database: none` no es válido). `packages/x402`: copiados Eip712, Signer, Transfer, PaymentHeaders, Networks, InvalidPaymentException (namespace `Quebracho\X402`, sin Drupal ni Guzzle); `ext-gmp` agregado a DDEV; `SignerTest` (vector web3.js) y `Eip712Test` (digest y firma r‖s‖v del ejemplo Mail de la spec EIP-712) pasan. Repo renombrado a `quebracho-x402-mcp`, namespace `Quebracho\X402`. `Authorization` (EIP-3009 → payload x402 v2, extraído de Buyer sin HTTP) con test. `Networks` reducido a testnets: Base Sepolia (USDC `0x036C…CF7e`, dominio EIP-712 name `USDC` v`2` **NV contra el contrato**) y Arc testnet; Solana y mainnets fuera. Repo público: github.com/leofishman/quebracho-x402-mcp. `Signer::recover` (ecrecover en GMP sobre la curva de paragonie/ecc, sin red) probado contra la spec. Interfaz `Scheme` (supports/pay/verify, forma tomada de la spec x402, experimental hasta 1.0) + `EvmExact` (verificación offline: scheme/red/asset/payTo/monto/ventana/ecrecover) con tests de pagos adulterados. `X402Middleware` PSR-15 (402 → verify → handler → settle solo si 2xx; settle fallido retiene la respuesta; settle NULL = mock) con 7 tests. **Integrado en la app y verificado de punta a punta** (`bin/smoke.sh`): `echo` gratis 200, `wisdom` sin pago 402 + `PAYMENT-REQUIRED`, con pago (`bin/pay.php`) 200 + `PAYMENT-RESPONSE`. Enganche: `App\Controller\McpController` reemplaza `mcp.server.default.controller` (el `MiddlewareFactory` del bundle es final) y agrega el middleware después de los defaults del SDK. Precio por `App\X402\Pricing` (env `X402_*`; batch cobra N×). `PayingHttpClient` PSR-18 (paga el 402 y reintenta una vez con el mismo body) + `Budget` (tope por red en unidades atómicas, en memoria por proceso; descuenta solo si el vendedor confirma settle) con 3 tests comprador↔vendedor sin red. Próximo: agente Symfony AI (`app:ask`) usando el cliente MCP con `PayingHttpClient`, luego settle en testnet. `X402_PAY_TO` por defecto = vendedor principal de Ombú en Arc (`0x7e51…6510`), a revisar antes de la entrega. LLM local en `app/.env.local` (`LLM_BASE_URL`, `LLM_MODEL`, `LLM_API_KEY`, tomado de yt2action; nunca commitear). 2026-10-28 (2ª sesión): `NearExact` mergeado a main como **experimental** (de PR #1 de Jules, solo los 3 archivos NEAR): `pay()` acepta secret key de 64 bytes (`sodium_crypto_sign_publickey_from_secretkey`), `verify()` exige deposit == 1 yocto, ponytail documentando que no liga public key ↔ `sender_id`; `ext-sodium` en composer.json. Del audit de Gemini: recibo mock toma payer de `authorization.from ?? sender_id`; `Pricing`/`PayingHttpClient` rewind solo si `isSeekable()`; batch pago con llamada fallida igual cobra todo → documentado con ponytail en `Pricing`. `packages/x402/README.md` escrito. 34 tests verdes, `bin/smoke.sh` OK de punta a punta.

2026-10-28 (3ª sesión): revisión contra la implementación oficial (github.com/x402-foundation/x402, spec + TypeScript; Go tiene la misma estructura). (1) `NearExact` alineado a `specs/schemes/exact/scheme_exact_near.md`: payload solo `signedDelegateAction`, USDC testnet `3e2210…b8af`, `max_block_height = altura + maxTimeoutSeconds` (clock = altura, obligatorio también para pagar), tope de ventana, nonce < altura×1e6, gas ≤ 100 TGas, solo ed25519 documentado; payer ya no se toma de `sender_id` (la spec lo prohíbe). (2) `EvmExact`: códigos de error de la spec §9, exige `x402Version` 2 y `extra.name/version`, margen de 6 s en validBefore; `Signer::recover` rechaza firmas high-s (test probado fallando sin el arreglo). (3) **Transporte MCP oficial** (`specs/transports-v2/mcp.md`): nuevo `X402McpMiddleware` (pago en `params._meta["x402/payment"]`, PaymentRequired como tool result `isError`, recibo en `_meta["x402/payment-response"]`, HTTP 200; JSON y SSE buffered; settle fallido retiene la salida; batches pagos rechazados). La app lo usa; `Pricing` recibe los params del tools/call. `X402Middleware` HTTP queda para endpoints comunes. 48 tests verdes, `bin/smoke.sh` OK de punta a punta en el formato nuevo. Sin commitear.

2026-10-28 (4ª sesión, review): symfony-reviewer FIX y x402-reviewer BLOCK. Corregido: claim atómico y durable por pago antes de ejecutar (`FileClaims`, `Settlement::key`; probado en vivo: 20 en paralelo → 1 entrega, reenvío desde otra sesión → `duplicate_settlement`), tope de `validBefore`, `X402_FACILITATOR=mock` obligatorio (la app no arranca con otro valor, probado), settle robusto (excepción, `"false"`, null, errorReason raro → retiene), tipos en `EvmExact` (array en nonce ya no da 500), eco de `accepted` exacto, notificación paga → 202 sin ejecutar, respuesta no-200 del transporte pasa sin cobrar, `PayingHttpClient` con decode tolerante y `success === true`, `strict_types` en toda la librería. 59 tests, smoke OK. Ver D5 (incluye por qué no se ata el pago a la tool).

2026-10-28 (5ª sesión): agente `app:ask` hecho. Comprador MCP en la librería: `PayingMcpClient` (PSR-18 decorator bajo el transporte HTTP del cliente MCP; paga el PaymentRequired en `_meta`, lee el recibo, gasta del `Budget` solo si `success === true`; JSON y SSE) con 5 tests sin red. App: `symfony/ai-bundle`, `ai-agent`, `ai-mcp-tool`, `ai-generic-platform` 0.15.0 + `symfony/http-client` 8.1; agente `default` con plataforma generic (`LLM_*` de `.env.local`) y toolbox `mcp_server: buyer.self` (cliente MCP del bundle, `http_client: PayingMcpClient`); `App\X402\Receipts` junta recibos; `--tool` llama sin LLM. Verificado: 64 tests, `bin/smoke.sh` OK, `app:ask "dame un consejo de ingeniería pago"` con el llama.cpp local llama `wisdom`, paga 0.01 USDC mock y muestra respuesta + recibo; `X402_BUDGET_USD=0.001` → no paga. Sin commitear.

2026-10-28 (6ª, review del comprador): x402-reviewer BLOCK sobre `PayingMcpClient` → corregido (D6): el Budget se gasta al firmar y nunca se devuelve por lo que diga el vendedor; `Budget::allows` solo firma tokens conocidos (asset + dominio EIP-712 de `Networks`), montos enteros positivos y `maxTimeoutSeconds` ≤ 600. Probado: vendedor que nunca confirma → 5 firmas con tope de 5. 77 tests, smoke y `app:ask --tool wisdom` OK.

**Próxima sesión:** settle en testnet (6–8/11) con facilitator real detrás de `App\X402\Facilitator`, Budget durable y allowlist de `payTo` en el comprador. Redes EVM extra **NV**. NEAR experimental fuera de la demo. MCP Inspector sin probar.

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
- 2026-10-28 (Leo): licencia MIT (la de Symfony). El código portado de x402_pay es de Leo, que como único autor lo relicencia MIT (`packages/x402/NOTICE`). Fuera de eso, no derivar más código de x402_pay: lo nuevo se escribe propio. Plan: el módulo Drupal (GPL) pasará a usar esta lib.
