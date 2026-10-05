# Optional App Store source layout

This directory mirrors the source layout expected by the current ZimaOS/CasaOS v2 App Store tooling:

```text
Apps/
└── ZimaBackup/
    ├── docker-compose.yml
    └── icon.svg
```

It is provided as a starting point for a future App Store submission. A complete custom App Store repository also requires root-level `store-config.json` and `supported-languages.json`; they are intentionally not included here because this project package is for a single app, not a whole store.

For direct installation in ZimaOS/CasaOS, use the root `docker-compose.yml`. The `packaging/appstore/Apps/ZimaBackup/docker-compose.yml` copy is kept for App Store repository packaging.
