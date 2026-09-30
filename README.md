# Spell Turn-ins

PoP spell turn-in tracker for an EverQuest guild quartermaster on
**Project Quarm**. The guild name is a setting (`guildName` in
`config.json`) and brands the page title and header.

## The items

- **Spectral Parchment** → random level 63-64 spell
- **Glyphed Rune Word** → random level 65 spell

Each is handed to the class NPC in the Plane of Knowledge and rolls on that
class's fixed pool (odds = 1/pool size). Pools are verified against
[pqdi.cc](https://www.pqdi.cc). A multiclass scroll counts as the NPC's
class's spell, at that class's level (e.g. Destroy Undead is CLR 64 at the
Cleric NPC but NEC 65 at the Necromancer NPC, so a level 65 spell can
sit in a Spectral pool).

## The rules

- **Phase 1: priorities.** Each class card holds a spell list where every
  spell has a priority tier (P1, P2, ..., several spells may share a tier)
  and a wanted-copy count. A tier is fully served before the next one:
  round 1 secures one copy of each class's P1 spells (walking the class
  cards in order), the following rounds their remaining copies, and only
  then do the P2 spells start. Phase 1 ends when every listed spell has
  all its wanted copies.
- **Phase 2: fair share.** Turn-ins are spread by (M1 + ratio × M2) × pool
  size (the class's 63-65 spell count; an M2 main counts at a configurable
  ratio, 50% by default). All logged turn-ins count, including phase 1's.
- **Duplicates.** Copies beyond a spell's wanted count, or repeats of
  unlisted spells, are flagged "roll it" (raid / Discord roll among mains).

Recording a turn-in requires stock in hand and decrements it; Undo refunds it.
The in-app **?** button shows this same guide.

## Running locally

```bash
python -m http.server 8123
```

Static page, no build step, open <http://localhost:8123>. Without the PHP
API the app runs in local mode: full write access, state in localStorage.

## Hosting (Discord auth)

Online, the app uses a small PHP API (`api/`): Discord sign-in, officers
write, raiders read, shared state on the server. It targets any web hosting
with **PHP 8+ and Apache** (`.htaccess` support), e.g. classic shared
hosting plans; no database is needed.

**1. Discord application**
- Create an application at <https://discord.com/developers/applications>.
- OAuth2 tab: add the redirect `https://YOUR-DOMAIN/api/callback.php`,
  copy the Client ID and Client Secret.
- In Discord (developer mode on), right-click your server and the two roles
  to copy their IDs.

**2. Configuration**
- Edit `config.json`: guild name, server ID, officer role IDs, raider role
  IDs.
- Copy `api/secrets.sample.php` to `api/secrets.php` and fill in the client
  ID/secret, the exact redirect URI, and a random `app_secret` (32+ chars).
  Never publish `secrets.php`.

**3. Upload**
- Upload everything to the site's web root.
- In your host's control panel: enable HTTPS (e.g. a free Let's Encrypt
  certificate) and select PHP 8.x. HTTPS is required by the OAuth callback
  and the session cookies.
- Make sure the `.htaccess` files are uploaded too (some FTP clients skip
  dotfiles): they block web access to `storage/` and the secrets. The API
  also recreates the `storage/` guard by itself if it goes missing.

**4. First run**
- Open the site: it shows "Sign in with Discord".
- Log in as an officer, then use **Import state** to push a local export
  (made with Export state on a localStorage version).
- Sessions last 7 days; role changes on Discord apply at the next login.
  To force-logout everyone immediately (e.g. after demoting an officer),
  increment `sessionEpoch` in `config.json`.

The API is a handful of endpoints: `me.php` (session), `state.php` (GET for
members, POST for officers with a revision check: concurrent saves get a 409
and the app reloads the newer version), `login/callback/logout.php` (OAuth),
and `health.php` (deployment self-check).

**Troubleshooting "Server save failed (...)"**
The toast shows the HTTP status and the server's message. While logged in,
open `https://YOUR-DOMAIN/api/health.php`: it reports the PHP version, curl,
HTTPS detection, and whether `storage/` exists and is writable. Typical
causes: `storage/` not writable (fix the folder permissions via FTP),
missing curl extension, or the host's security module blocking the request
(the app saves via POST precisely to avoid the commonly blocked PUT verb).

## Files

| Path | Role |
| --- | --- |
| `index.html` / `styles.css` / `app.js` | Single-page app (vanilla JS) |
| `data/spells.js` | Static dataset: classes, NPCs, turn-in pools |
| `data/pok_map.js` | Plane of Knowledge minimap shown when hovering an NPC name |
| `api/` | PHP API: Discord OAuth, sessions, shared state |
| `config.json` | Guild name, Discord server and role ids, session epoch |

In local mode the tracking state lives in `localStorage`; online it is
shared through the API. Use **Export state / Import state** to back it up
or move it around.
