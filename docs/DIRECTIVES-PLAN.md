# SpaceShelf — Directives (quest system) implementation plan

Status: **Spec only** (2026-11-17). Nothing implemented. Upstream tracker:
OGameX issue **#843** (open feature request). Registration-time
newbie/veteran selection that activates the tutorial highlights is upstream
**#978** (out of scope here).

## 1. What Directives are (vanilla OGame)

"In-Play Intelligence" (IPI) quest tracker: **70 directives split across 7
category tabs**. Each directive has a checkable condition (build/research/
teardown/dispatch something) and grants a reward **on the planet that is
active at claim time** — mostly resources or ships, a few hinge on Dark
Matter, lifeforms or the teardown feature. Completing every directive in a
category grants a larger category reward. For new players the system also
highlights page elements (`data-ipi-hint` markers) as hints.

Known vanilla quirks to verify against the live game before coding (do not
"fix" them): directives that require *tearing down* a building (e.g. fusion
reactor); no hint that shipyard queues cannot be aborted; community reports
of "already researched to level N but task won't complete" bugs — decide
per-condition whether the fork reproduces vanilla bug-for-bug or behaves
sanely, per the game-accuracy rule.

## 2. Current state in the fork (dead stub)

- `resources/views/ingame/layouts/main.blade.php:496–511` renders the
  `#ipimenucomponent` box: translated "Directives" header
  (`t_ingame.layout.menu_directives`), `.ipiMenuBody` / `.ipiMenuFooter`
  empty + `hidden`, and a link whose href is an unreplaced upstream
  placeholder: `#TODO_page=ajax&component=ipioverview&action=overviewLayer&ajax=1`
  (clicking only appends garbage to the URL hash).
- Vendored OGame JS (`resources/js/ingame/e7c74974620fa35b197315ebdbb8c2.js`)
  contains IPI panel machinery that fetches `ipiMenuConfig.ipiMenuContentUrl`
  — that config is **never set**, so the JS no-ops.
- No backend at all: no `ipioverview` route/controller, no quest tables, no
  directive definitions. 12 orphaned `data-ipi-hint` markers in the layout.

## 3. P0 — content extraction (blocking, no code)

Needed before anything else can be written: for all 70 directives —
id, category, OGame-accurate English title/description, condition, reward;
plus the 7 category bonuses.

- Sources: screenshots in upstream #843; live vanilla client localization /
  quest data; community tables; verify on a live universe where possible.
- Deliberately **skip lifeform-gated directives** (lifeforms are not
  implemented here; upstream equivalent #228). Record the skips.
- Store definitions in `config/directives.php` (idiomatic: `config/bots.php`
  precedent), conditions as typed arrays, texts as translation keys.
- Translation keys go in `resources/lang/en/t_directives.php` — exact
  original OGame wording (non-negotiable per AGENTS.md). Other locales may
  be AI-generated with the `AI-TRANSLATED` marker.

## 4. P1 — schema, condition engine, evaluation, claiming

### Schema (new migration, never edit merged ones)

`create_directive_progress_table`: `user_id` FK cascade, `directive_id`
(string), `status` enum `completed|claimed`, `completed_at` nullable,
`claimed_at` nullable, unique (`user_id`, `directive_id`). Progress values
are **computed on the fly** (no persisted counters), so partial progress
needs no storage.

### Condition engine

`DirectiveCondition` interface with `check(PlayerService $player,
PlanetService $activePlanet): int` returning progress vs the definition's
target. First implementation set:

- `BuildingLevel` (active planet or any colony — per definition)
- `ResearchLevel` — `PlayerService::getResearchLevel()` (:437)
- `UnitCount` — needs a new player-wide aggregation helper (sum of
  `units` across planets + in-flight fleets; no helper exists today)
- `PlanetCount` (colonies) via `PlayerService::getMaxPlanetAmount()` area
- `TearDown` — **cannot be polled** (levels only go down). Needs a one-shot
  hook: an event (e.g. `BuildingDowngraded`) dispatched from
  `ObjectService` on successful downgrade, observed by a listener that
  marks matching directives completed. (Note: teardown currently has the
  upstream #1624/#1625 bug documented in TODO.md — implement/fix first.)

### Evaluation timing

No game-state event bus exists (only `ChatMessageSent`). Use **lazy
evaluation**: in `GlobalGame` middleware, after `updateFleetMissions()`,
throttled per player (cache stamp, once per minute or so), evaluating only
unclaimed directives and upserting completion rows transactionally. Bounded
cost, no queue jobs, no stale-singleton concerns. Re-evaluate immediately
after actions the UI can chain (build finished, research finished) only if
the throttling turns out to feel laggy.

### Claim flow

`POST /directives/{directiveId}/claim` (CSRF protected): re-verify
completion, **atomically transition `completed → claimed`** (conditional
update + transaction so double-clicks/two tabs can't double-grant), then
apply the reward to the **active planet** (vanilla semantics — rewards hit
whatever `$currentPlanet` is):

- Resources → `PlanetService::addResources()` (:1539) / `addResource()` (:1570)
- Ships/defense → `PlanetService::addUnits(UnitCollection)` (:1909) —
  precedent: `ExpeditionMission.php:546`
- Dark Matter → `DarkMatterService::credit($user, $amount, 'directive',
  'directive_<id>')` (:41)

Category bonus grants when the last unclaimed directive in a category is
claimed.

## 5. P2 — UI

Reuse the existing `#ipimenucomponent` box (header is already rendered):

- Preferred: feed the vanilla panel by defining `ipiMenuConfig` in the
  layout pointing at a new content route, so the vendored JS renders it;
  fall back to our own small JS driving `.ipiMenuBody` if the vendored path
  fights us (it was reverse-engineered and may expect session state).
- Content endpoint returns a **Blade partial** (categories, progress bars,
  claim buttons) — cheaper than JSON+JS and fits the repo's server-rendered
  style.
- Remove the `#TODO_` href placeholder at that point.

## 6. P3 — new-player highlights (optional)

`data-ipi-hint` markers (12 in the layout) + highlight CSS; depends on a
newbie/veteran choice (upstream #978 registration flow). Out of scope
until that decision is made.

## 7. Testing

- **Unit**: one test per condition type with fixture players — assert exact
  boundaries (target−1 ⇒ incomplete, target ⇒ complete).
- **Feature**: build → evaluate → claim: reward lands on the **active**
  planet (resources/units/DM), claim is idempotent (second claim rejected),
  category bonus fires exactly once. Use `Carbon::setTestNow()`/`travel()`
  for time-based pacing; fleet-dispatch conditions reuse
  `FleetDispatchTestCase` patterns.
- **Concurrency**: two simultaneous claims of the same directive; run
  `php artisan spaceshelf:test:race-condition-game-mission` if anything
  lands in mission code paths.
- **Game accuracy**: every condition type and reward target verified against
  live vanilla before implementation — including the quirky ones listed in
  §1.

## 8. Phases

| Phase | Scope | Estimate |
|---|---|---|
| P0 | Extract 70 directives + texts + rewards into `config/directives.php` + `t_directives.php` (en) | content-heavy, blocking |
| P1 | Migration, condition engine, `GlobalGame` lazy evaluation, claim endpoint, tests | 1 feature branch |
| P2 | `#ipimenucomponent` panel wired to content partial | medium |
| P3 | Newbie highlights (needs #978 decision) | open |

Explicitly out: lifeform-gated directives (until lifeforms exist),
registration flow (#978), alliance classes tie-ins (upstream #842).
