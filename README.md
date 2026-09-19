# CHIM-Custom

Optional CHIM integrations for third-party Skyrim mods.

This package is split into two pieces:

- HerikaServer plugin files in this folder. These install into `HerikaServer/ext/CHIM-Custom`.
- Skyrim SKSE plugin source under `SkyrimPlugin/`. This builds `CHIMCustom.dll`.

Supported integrations:

- Dirt and Blood - Dynamic Visual Effects: player dirt, blood, clean, and washing state.
- Bathing in Skyrim - Renewed: player dirtiness tier, bathing, and soapy state.
- SunHelm Survival: player hunger, thirst, exhaustion, and cold state.
- Starfrost - A Survival Overhaul: player hunger, exhaustion, and cold state through Starfrost and Survival Mode Improved.

All integrations are optional. If a supported mod is not loaded, `CHIMCustom.dll` silently skips that integration.

## Flow

1. `CHIMCustom.dll` loads in SKSE.
2. It reads CHIM server connection settings from `Data/SKSE/Plugins/CHIMCustom.ini`.
3. It polls the server plugin config endpoint.
4. If a supported mod is loaded, it checks the relevant player spells/globals.
5. It posts compact visible-state JSON to `ext/CHIM-Custom/api/state.php`.
6. The HerikaServer plugin stores the state.
7. `context_pre.php` registers current-character cleanliness and survival state into focused prompt blocks.
8. `globals.php` registers nearby actor profile enrichers so the same state can appear beside equipment/activity details in `<nearby_actors>`.

State is prompt-only by default. It is not written into event history.

## Database

CHIM creates a shared `plugins` PostgreSQL schema for server plugins. CHIM-Custom stores its tables under that schema using plugin-prefixed names, such as `plugins.chim_custom_integrations`. Future server plugin migrations should follow the same pattern and avoid creating plugin-owned tables in `public`.

### Per-NPC integration state

On servers with the NPC plugin-data API ([HerikaServer #96](https://github.com/Dwemer-Dynamics/HerikaServer/pull/96)), each accepted live update also saves sanitized state on an existing NPC in `core_npc_master.plugin_extended_data`:

- `chim_custom_dirt_and_blood`
- `chim_custom_bathing_in_skyrim`
- `chim_custom_sunhelm_survival`
- `chim_custom_starfrost_survival`

Each namespace contains `state`, `actor_key`, `actor_name`, `actor_type`, `integration_id`, `gamets`, and a UTC `updated_at`. Plugins can read it with `NpcMaster::getPluginData($npcId, $namespace)`. Each update replaces only its integration namespace through `setPluginData`; other integrations and plugins are preserved. These writes do not create NPC history. Ordinary profile snapshots include the data.

The runtime FormID must identify exactly one existing NPC. If none matches, an exact, unique name may identify a profile whose FormID has not yet been initialized. Ambiguous or conflicting identities are skipped; this extension does not create NPC profiles. A later poll retries after the profile exists.

The existing actor-state table remains the live prompt/diagnostic cache, including its freshness checks and bathing-event transition detection. History rollback restores the NPC snapshot, not this transient cache; the next poll refreshes the NPC copy. Existing cached rows are retained and copied only on their next accepted live update. Global settings and heartbeats stay in the `plugins` schema.

Older servers without both the API and migrated column continue using the cache. If an optional NPC write fails, live state remains available and the server logs a warning. No game-plugin update, schema migration, or configuration change is required by this extension change.

## Release Packaging

Embed the server extension into the normal Skyrim mod package:

```powershell
.\scripts\build-dwpkg.ps1
```

The generated file is placed at `SkyrimPlugin/package/CHIM/server-plugins/CHIM-Custom/<version>.dwpkg`. CHIM detects it when a save loads and transfers it to HerikaServer automatically. The generated DLL and `.dwpkg` remain local release artifacts and are not committed to pull requests.

After building `CHIMCustom.dll`, create the complete user-facing release archive with:

```powershell
.\scripts\build-release.ps1
```

This stages the game files, generates the matching server package, and writes `release/CHIM - Custom.zip` without modifying the source package directory.

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
CHIM/server-plugins/CHIM-Custom/<version>.dwpkg
```

Upload the Skyrim package as `CHIM - Custom.zip`.
