# ZimaBackup translations

ZimaBackup uses English source messages as canonical translation IDs. If a message is missing from a locale file, the English source text is displayed automatically.

## Included languages

- `en` — English source language
- `fr` — French

The selected language is stored in SQLite under `ui.language` and can be changed from **Settings → Interface → Language**.

## Adding a language

1. Copy `translations/fr.php` to `translations/<locale>.php` and translate the values while keeping the English keys unchanged.
2. Add the locale code and display name to `Translator::supportedLocales()` in `src/Service/Translator.php`.
3. Keep technical values such as paths, Docker image names, Restic snapshot IDs and confirmation keywords (`RESTORE`, `INSTALL`) unchanged.
4. Unknown messages should be allowed to fall back to English rather than being replaced with an empty string.

Twig uses `t("Message")` for UI text, `status_label` for application states, `trans` for known runtime errors, and `local_datetime` for localized timestamps. JavaScript messages are generated from the same translator catalog and exposed through `window.ZB_I18N`.
