# `secrets/` — runtime-mounted credentials (NOT committed)

Everything in this directory except this README and `.gitkeep` is git-ignored.
Place real credential files here on the **server**; Docker mounts them read-only.

## Required

### `secrets/google/credentials.json`
Google **service-account** JSON key. Google Sheets sync (`om:sync` enrichment,
`import:maintenance-sheet`, trips warm, etc.) fully breaks without it.

```
secrets/
└── google/
    └── credentials.json
```

- Mounted read-only at `/var/www/html/storage/app/google/credentials.json`
  in the `backend`, `scheduler` (and `queue`) containers.
- The service account must have **read** access on every spreadsheet the
  deployment syncs (share each sheet with the service-account email).
- Referenced by `GOOGLE_SHEETS_CREDENTIALS` in `backend/.env` (the default path
  already points here — no change needed).

> If you don't use Google Sheets, create an empty `secrets/google/` directory so
> the bind mount has a source, and leave Sheets-dependent scheduled commands off.
