# CHIM-Custom

Optional CHIM integrations for third-party Skyrim mods.

This package is split into two pieces:

- HerikaServer plugin files in this folder. These install into `HerikaServer/ext/CHIM-Custom`.
- Skyrim SKSE plugin source under `SkyrimPlugin/`. This builds `CHIMCustom.dll`.

The first supported integration is Dirt and Blood - Dynamic Visual Effects. It is optional: if `Dirt and Blood - Dynamic Visuals.esp` is not loaded, `CHIMCustom.dll` silently skips it.

## Flow

1. `CHIMCustom.dll` loads in SKSE.
2. It reads CHIM server connection settings from `Data/SKSE/Plugins/CHIMCustom.ini`.
3. It polls the server plugin config endpoint.
4. If Dirt and Blood is loaded, it checks the player's Dirt and Blood spells.
5. It posts compact visible-state JSON to `ext/CHIM-Custom/api/state.php`.
6. The HerikaServer plugin stores the state.
7. `context_pre.php` registers current-character cleanliness into the `character_bottom` prompt injection slot as a focused `<cleaniness>` block.
8. `globals.php` registers a nearby actor profile enricher so the same state can appear beside equipment/activity details in `<nearby_actors>`.

State is prompt-only by default. It is not written into event history.

## Release Packaging

Server plugin release:

Package only the HerikaServer plugin files into a top-level `CHIM-Custom/` folder, then upload it as `CHIM-Custom.tar.gz`. The server plugin installer extracts this archive into `HerikaServer/ext/CHIM-Custom`.

Required server archive contents:

```text
CHIM-Custom/api/
CHIM-Custom/lib/
CHIM-Custom/migrations/
CHIM-Custom/context_pre.php
CHIM-Custom/globals.php
CHIM-Custom/index.php
CHIM-Custom/manifest.json
CHIM-Custom/README.md
```

Skyrim plugin release should include:

```text
SKSE/Plugins/CHIMCustom.dll
SKSE/Plugins/CHIMCustom.ini
```

Upload the Skyrim package as `CHIM-Custom-Skyrim.zip`.
