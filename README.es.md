# Notificaciones de Telegram para osTicket

[![Licencia: GPL v2](https://img.shields.io/badge/Licencia-GPL_v2-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt)
[![osTicket](https://img.shields.io/badge/osTicket-%E2%89%A5%201.17-orange.svg)](https://github.com/osTicket/osTicket)
[![Telegram Bot API](https://img.shields.io/badge/Telegram_Bot_API-supported-26A5E4.svg)](https://core.telegram.org/bots/api)

> *Read this in [English](./README.md).*

Plugin de osTicket que envía notificaciones de **Telegram** a clientes y administradores en los eventos del ciclo de vida del ticket, con **teclados inline** (botones Ver ticket / Responder), matriz por evento × audiencia, y un **flujo de vinculación de cuenta vía deep-link del bot** para que el cliente conecte su Telegram con un solo clic.

Hermano del plugin [`osticket-evolution-api`](https://github.com/RenatoAscencio/osticket-evolution-api): misma arquitectura, postura de seguridad y nivel de calidad — sólo cambia el destino (Telegram Bot API en lugar de Evolution).

---

## Funcionalidades

- **Vinculación por deep-link del bot.** El cliente da clic en "Vincular Telegram" en su perfil → abre `https://t.me/<tu-bot>?start=<token>` → el bot responde "✅ Vinculado!" en segundos.
- **Alternativa manual.** El cliente puede pegar su `chat_id` en un campo custom del formulario de Contact Information. Funciona sin webhook.
- **Botones inline** en cada mensaje (opcional). "Ver ticket" para clientes; "Ver ticket" + "Responder" para admins. Etiquetas configurables.
- **Matriz evento × audiencia.** Ocho toggles independientes:
  - Ticket creado → cliente / admin
  - Respuesta del cliente → admin
  - Respuesta del staff → cliente / admin
  - Cambio de estado → cliente / admin
  - Cambio de asignación → admin
- **Tres parse modes:** MarkdownV2 (recomendado, escape automático de variables), HTML, o texto plano.
- **Opt-in del cliente** vía checkbox `telegram_opt_in` en el formulario Contact Information.
- **HTTP retries** ante 429 / 5xx / errores de red con backoff exponencial. Honra `parameters.retry_after` de Telegram y el header `Retry-After`.
- **Endpoint webhook** procesa `/start`, `/unlink`, `/status`. Asegurado con header `X-Telegram-Bot-Api-Secret-Token`.
- **Credenciales enmascaradas en UI** (`PasswordField` para bot token, webhook secret, Sentry DSN).
- **Redacción de PII en logs** (chat IDs enmascarados parcialmente, mensajes truncados, secretos redactados).
- **Integración opcional con Sentry** vía el mismo cliente envelope ligero del plugin Evolution.

---

## Empezar

### 1. Crear bot en Telegram

En Telegram, manda mensaje a **@BotFather**:

```
/newbot
<nombre del bot>
<username terminado en "bot">
```

BotFather te da un token. Cópialo. Apunta también el **username del bot** (sin el `@`).

### 2. Copiar plugin a osTicket

```bash
git clone --branch v0.1.0 --depth 1 https://github.com/RenatoAscencio/osticket-telegram-bot.git
rsync -av osticket-telegram-bot/plugin/ /ruta/a/osticket/include/plugins/telegram-bot/
```

### 3. Instalar + configurar

En admin osTicket: **Manage → Plugins → Add New Plugin** → *Telegram Bot Notifications* → **Install** → entra a configurarlo.

Obligatorios:

| Sección | Campo | Valor |
| ------- | ----- | ----- |
| Bot — Connection | Bot token | de BotFather |
| Bot — Connection | Bot username | sin `@`, ej. `MiEmpresaSoporteBot` |
| Recipients | Admin Telegram chat IDs | uno por línea; obtén con [@userinfobot](https://t.me/userinfobot) |
| Misc | osTicket base URL | `https://tu-osticket.ejemplo.com` |

Para webhook + flujo de linking (recomendado):

| Sección | Campo | Valor |
| ------- | ----- | ----- |
| Webhook | Public webhook URL | `https://tu-osticket/include/plugins/telegram-bot/webhook.php` |
| Webhook | Webhook secret token | genera con: `openssl rand -hex 24` |

Después **Enable** el plugin y registra el webhook con Telegram (ver [docs/webhook-setup.md](./docs/webhook-setup.md)).

---

## Vincular cuentas de clientes

Dos métodos, pueden coexistir:

### A — Bot deep-link (recomendado)

1. Cliente da clic en **"Vincular Telegram"** en su perfil de osTicket.
2. Se abre `https://t.me/<bot>?start=<token-one-shot>`.
3. Cliente presiona **Start**; el bot pre-llena `/start <token>`.
4. Bot responde `✅ Vinculado!`; el plugin guarda `(user_id, chat_id)`.

Tokens de 32 hex chars, TTL 15 min (configurable), one-shot.

### B — Pegar chat_id manual

1. Admin agrega campo custom `telegram_chat_id` al formulario Contact Information.
2. Cliente (o admin a su nombre) pega su chat_id ahí.

Detalle + troubleshooting: [docs/user-linking.md](./docs/user-linking.md).

---

## Licencia

[GPL-2.0-or-later](./LICENSE). Compatible con osTicket.
