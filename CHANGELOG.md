# Changelog

Všechny významné změny tohoto balíčku jsou dokumentovány zde.

Formát vychází z [Keep a Changelog](https://keepachangelog.com/cs/1.1.0/),
verzování dle [SemVer](https://semver.org/lang/cs/).

## [Nezveřejněno]

### Přidáno
- Kostra balíčku (TASK-090): `ChatbotServiceProvider` nad Spatie Package
  Tools (config `chatbot`, discovery migrací, routa `web`).
- Config `config/chatbot.php` — bloky `user_model`, `features`, `routes`,
  `api`, `default_model`/`model`, `models` (allowlist), `pricing`, `retry`,
  `timeouts`, `rate` (per purpose), `chat` (historie + tool-loop), `tools`
  a `actions` (discovery cesty hosta), `prompts.context`. Bez closures —
  cacheovatelný přes `config:cache`.
- Kontrakty `Contracts\ChatAuthorizer`, `Contracts\ChatTool`,
  `Contracts\ChatActionHandler` (parametry `$user` typované `mixed` —
  balíček host User model neimportuje).
- Defaulty `Support\AllowAuthenticatedChatAuthorizer` (chat smí kdokoli
  přihlášený), `Support\ChatActionResult`, `Support\Purpose`.
- Registr `Chatbot` s `registerTool()` / `registerActionHandler()`.
- Testy na Orchestra Testbench + Pest (smoke, bindingy kontraktů,
  cacheovatelnost configu).

### Pozn. k odchylkám od kontraktu
- Kontrakt uvádí klíč `models` dvakrát (`models.user_model` i allowlist AI
  modelů) — v PHP jde o týž klíč. Allowlist zůstal na `chatbot.models`
  (dle mapy migrace `ai.models` → `chatbot.models`), User model je na
  `chatbot.user_model` (env `CHATBOT_USER_MODEL` beze změny).
