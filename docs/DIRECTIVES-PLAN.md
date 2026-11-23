# SpaceShelf — Directives (quest system) implementation plan

Status: **P1 + P2 implemented (2026-11-19). P0 partially done:
Directive I + II shipped with screenshot-verified content, Directive III
added as a fully best-effort science chapter (one research_level mission per
technology), Directive IV as a best-effort defense-structures chapter (fork
stand-in for the confirmed "Deep Space" chapter), chapters V and VII deferred
until their text can be sourced.**
The quest engine
(`DirectiveService`), overlay UI, claim endpoints and the rewired
`#ipimenucomponent` panel are live; `config/directives.php` is the catalog
where future chapters simply drop in. Directive VI (Alien Life) stays
skipped (lifeforms unimplemented, upstream #228). Upstream tracker: OGameX
issue **#843** (open feature request). Registration-time newbie/veteran
selection that activates the tutorial highlights is upstream **#978** (P3,
out of scope).

## 1. How Directives looked/behaved in original OGame

### Layout

- Left column: 165 px "Directives" box (`#ipimenucomponent`; header is an
  image bar, `.ipiMenuHead`). A round orange/green badge (`.ipiHintCollect`)
  shows the claimable count; page elements tagged `.ipiHintable` get
  collect badges too.
- Opening it shows a ~**900 px overlay** (`#ipioverviewlayer`) titled
  "Directives" with an X close button.
- **7 chapter tabs labelled with Roman numerals I–VII**
  (`#ipiOverviewChapters`). Active chapter green, inactive blue-grey;
  completed chapters carry a ✓ on the tab.
- Big chapter title: **“Directive I – A Sound Economy”** style
  (`#ipiOverviewChapterTitle`).
- Chapter body = **accordion rows, one per mission** (`.ipiTaskItem`):
  green ▼ expander | mission title (✓ when done) | progress bar
  “x / y” | state column (“Collected”).
- Expanded mission: **narrator character portrait** (e.g. *First Officer
  Nova* — portrait + dialogue text), “Complete the following missions:”
  bullet list (✓ per sub-step), right-hand “For completing the mission
  you receive:” + reward icon + amount, and a claim button at the bottom.
- Bottom strip (`#ipiOverviewChapterRewards`): “When you complete this
  directive, you will receive:” + resource icons with amounts, and a
  **“Claim All”** button.

### Terminology (important — upstream #843 was loose with it)

The UI chapters are called **“Directive N – <subtitle>”**; each contains
~10 **missions**. #843's “70 Directives in seven categories” counts the
*missions*. So: **7 chapters × ~10 missions**, each mission has its own
reward, each chapter a completion bonus.

Confirmed chapter names: I – A Sound Economy; II – The First Ship; IV –
Deep Space; VI – Alien Life (lifeforms chapter, added with the Lifeforms
expansion). III/V/VII subtitles + all mission texts/rewards = P0
extraction task.

### Sample content (verbatim from screenshots/forum)

- Directive I mission: “Open the mission overview” — a pure meta-action,
  reward **500 DM** — claimable immediately after arrival.
- Directive II missions (10): Civilian ships, Research Lab, Energy
  Technology, Combustion Drives, Robotics Factory, Shipyard, Small Cargo
  ships, **Become part of an alliance**, **Rename a planet**, **Send a
  buddy request**. Chapter reward: **1,500 metal / 750 crystal / 250
  deuterium / 2,000 DM**.
- Directive III mission 8: “Dispatch a fleet with resources”.
- Directive IV mission: “Dispatch an expeditionary fleet”.
- Directive VI missions: “Upgrade the Biosphere Farm lifeform building to
  level 22/23/24”, “Upgrade the Residential Sector lifeform building to
  level 21/22/23”, “Unlock lifeform research slot 3”.

### Behavior

- Mission types: state conditions (e.g. *positive energy balance* — only
  completes once net energy is positive), building/research/unit
  requirements, **one-off UI/social actions** (open a page, join an
  alliance, rename a planet, send a buddy request), and fleet-dispatch
  actions.
- Dispatch conditions complete **at launch** — a fleet launched and
  immediately recalled still counts (community-confirmed).
- Completion is server-side but polls/syncs with visible lag; missions
  are guided with **yellow highlighting** of the exact buttons/slots to
  use and a “Track mission” jump-to-screen flow.
- Rewards: per-mission claim on the expanded row + chapter bonus via
  “Claim All”; rewards land on the planet **active at claim time**
  (upstream #843 text). DM is a real mission reward currency here
  (500–2,000 DM per step) — consistent with our DM economy being
  expedition-limited; vanilla does the same.
- **One-off, no reset**: “it's a tutorial and not a resetting event” —
  per universe/account, Gameforge only *adds/patches* chapters via
  updates (e.g. Directive VI arrived with lifeforms).
- Vanilla quirks/bugs to treat as ground truth or consciously diverge
  from (verify live before implementing, per AGENTS game-accuracy rule):
  missions not completing despite met conditions (fixed in Update 12.0
  changelog); “Dispatch an expeditionary fleet” apparently requiring the
  *specific* Small Cargo built in an earlier mission (workarounds
  involved highlighted fleet slots); the “tear down fusion reactor”
  mission was so unpopular it was **hot-fixed to auto-complete for
  everyone with fusion reactor > 10** (Oct 2023); lifeform missions
  behave badly on universes colonised before the lifeforms chapter
  exists.

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
- Vendored CSS carries the **complete vanilla styling** for the panel and
  overlay (`.ipiMenu*`, `.ipiTaskItem*`, `#ipiOverview*`, `.ipiChapterItem`)
  — a P2 implementation gets the look for free; we only add markup + JS.
- No backend at all: no `ipioverview` route/controller, no quest tables, no
  directive definitions. 12 orphaned `data-ipi-hint` markers in the layout.

## 3. P0 — content extraction (partial, 2026-11-19)

**Shipped:** Directive I – A Sound Economy (mission "Welcome Cadet!" →
sub-step "Open the mission overview" → 500 DM, Nova dialogue verbatim) and
Directive II – The First Ship (all ten mission titles, chapter bonus
1,500/750/250 + 2,000 DM — verified). The per-mission Directive II rewards
are best-effort values marked `// best-effort, not documented upstream`
in `config/directives.php`; replace them from a vanilla dump when
possible. Chapters V and VII (and the true content of the best-effort
chapters III + IV, the Directive I chapter bonus and
Directive II sub-step texts) are not extractable from the public web —
fan wikis predate the feature, quest data ships server-side in vanilla.
Ground truth requires a live-universe `ipioverview` dump; the engine is
content-agnostic so new chapters are config + lang only.

The remaining catalog would need: all seven chapters (subtitle,
narrator, missions) and all ~70 missions — id, title, OGame-accurate
English description, condition, per-mission reward; plus chapter bonuses.

- Sources: screenshots in upstream #843; live vanilla client localization /
  quest data (the unified client ships the directive content + portraits
  client-side — dump of `ipioverview` responses is the ground truth);
  community threads quoted in §1; verify on a live universe where
  possible.
- Deliberately **skip Directive VI – Alien Life** (lifeforms not
  implemented; upstream equivalent #228). Record the skip.
- Store definitions in `config/directives.php` (idiomatic: `config/bots.php`
  precedent) — chapters as top-level keys, missions nested; conditions as
  typed arrays; texts as translation keys.
- Translation keys go in `resources/lang/en/t_directives.php` — exact
  original OGame wording (non-negotiable per AGENTS.md), including Nova's
  dialogue. Other locales may be AI-generated with the `AI-TRANSLATED`
  marker.

## 4. P1 — schema, condition engine, evaluation, claiming

### Schema (new migration, never edit merged ones)

Two status levels (mission + chapter): `create_directive_progress_table`
with `user_id` FK cascade, `directive_id` string (chapter id, e.g.
`directive_ii`), `mission_id` nullable string (null row = the chapter
bonus itself), `status` enum `completed|claimed`, `completed_at` nullable,
`claimed_at` nullable, unique (`user_id`, `directive_id`, `mission_id`).
Progress values are **computed on the fly** (no persisted counters), so
partial progress needs no storage.

### Condition engine

`DirectiveCondition` interface with `check(PlayerService $player,
PlanetService $activePlanet): int` returning progress vs the definition's
target. First implementation set (inferred from §1's samples):

- `BuildingLevel` (active planet or any colony — per definition)
- `ResearchLevel` — `PlayerService::getResearchLevel()` (:437)
- `UnitCount` / unit availability — needs a new player-wide aggregation
  helper (sum of `units` across planets + in-flight fleets; no helper
  exists today)
- `EnergyBalance` — active planet production surplus > 0
  (`getResourceProductionFactor()`/hourly stats)
- `PlanetCount` (colonies)
- `FleetDispatched` — mission type + optional unit filter; **latches at
  dispatch**, not arrival (vanilla semantics)
- `UiAction` — one-off page/flow visits: `overview_open` (mission
  overview page), `alliance_join`, `planet_rename`, `buddy_request`,
  `galaxy_view`… implemented as route-tag markers checked by the lazy
  evaluator against an `activities` marker table written by a small
  observer/middleware — cheap and matches vanilla's "Open the mission
  overview" class of mission.
- `TearDown` — **cannot be polled** (levels only go down). One-shot hook:
  dispatch a `BuildingDowngraded` event from `ObjectService` on
  successful downgrade, listener marks matching missions completed.
  (Note: teardown currently has upstream #1624/#1625 bugs from TODO.md —
  fix first.)

### Evaluation timing

No game-state event bus exists (only `ChatMessageSent`). Use **lazy
evaluation**: in `GlobalGame` middleware, after `updateFleetMissions()`,
throttled per player (cache stamp, ~once per minute), evaluating only
unclaimed missions and upserting completion rows transactionally.
Dispatch/teardown/UI events mark completion immediately. Bounded cost,
no queue jobs, no stale-singleton concerns.

### Claim flow

`POST /directives/{chapterId}/{missionId?}/claim` and
`POST /directives/{chapterId}/claim-all` (CSRF protected): re-verify
completion, **atomically transition `completed → claimed`** (conditional
update + transaction so double-clicks/two tabs can't double-grant), then
apply the reward to the **active planet** (vanilla semantics — rewards hit
whatever `$currentPlanet` is):

- Resources → `PlanetService::addResources()` (:1539) / `addResource()` (:1570)
- Ships/defense → `PlanetService::addUnits(UnitCollection)` (:1909) —
  precedent: `ExpeditionMission.php:546`
- Dark Matter → `DarkMatterService::credit($user, $amount, 'directive',
  'directive_<id>')` (:41)

Chapter bonus is the null-mission row: completable when every mission of
the chapter is claimed, granted exactly once.

## 5. P2 — UI

Reuse the existing `#ipimenucomponent` box (header is already rendered):

- Vanilla look is fully available in vendored CSS (`.ipiMenu*`,
  `.ipiTaskItem*`, `#ipioverviewlayer`, `#ipiOverview*` sections) — markup
  + JS only, no styling work.
- Content endpoint returns a **Blade partial** (chapter tabs, mission
  accordions, progress, rewards, claim/Claim All buttons) — cheaper than
  JSON+JS and fits the repo's server-rendered style.
- Preferred: feed the vanilla panel by defining `ipiMenuConfig` in the
  layout pointing at the content route so the vendored JS renders it;
  fall back to our own small JS driving `.ipiMenuBody` if the vendored
  path fights us.
- Badge counter (`ipiHintCollect`) on the panel when claimable rows
  exist.
- Remove the `#TODO_` href placeholder at that point.
- Port narrator portraits as assets from the vanilla client (first-party
  dump), e.g. Nova for chapter I.

## 6. P3 — guided-flow highlights (optional)

Yellow highlighting + "Track mission" + new-player hint markers
(`data-ipi-hint` — 12 already in the layout). Depends on the
newbie/veteran choice at registration (upstream #978). Out of scope
until that decision is made.

## 7. Testing

- **Unit**: one test per condition type with fixture players — assert exact
  boundaries (target−1 ⇒ incomplete, target ⇒ complete).
- **Feature**: build → evaluate → claim: reward lands on the **active**
  planet (resources/units/DM), claim is idempotent (second claim rejected),
  chapter bonus fires exactly once and only after all missions claimed;
  dispatch-condition latches at launch (recall still counts). Use
  `Carbon::setTestNow()`/`travel()`; fleet conditions reuse
  `FleetDispatchTestCase` patterns.
- **Concurrency**: two simultaneous claims of the same mission; run
  `php artisan spaceshelf:test:race-condition-game-mission` if anything
  lands in mission code paths.
- **Game accuracy**: every condition type and reward target verified against
  live vanilla before implementation — including the quirky ones listed in
  §1.

## 8. Phases

| Phase | Scope | Estimate | Status (2026-11-19) |
|---|---|---|---|
| P0 | Extract 7 chapters / ~70 missions (conditions, rewards, texts) into `config/directives.php` + `t_directives.php` (en) | content-heavy, blocking | **partial** — Directives I + II shipped (II per-mission rewards best-effort); III + IV shipped as best-effort chapters; V/VII pending a live vanilla dump |
| P1 | Migration, condition engine, `GlobalGame` lazy evaluation + events, claim endpoints, tests | 1 feature branch | **done** (lazy state checks instead of event counters; activity markers for non-derivable actions) |
| P2 | `#ipimenucomponent` + overlay markup/JS on the vendored CSS | medium | **done** (`directives.js` event delegation; vanilla `a.overlay` framework opens the layer) |
| P3 | Guided-flow highlights (needs #978 decision) | open | not started |

Explicitly out: Directive VI (lifeforms), registration flow (#978),
alliance classes tie-ins (upstream #842).

## 9. Research sources (2026-11-17)

- Upstream #843 screenshots (chapter tabs I–VII, Directive II mission
  list, chapter reward strip, Claim All, Nova portrait) — downloaded to
  `/tmp/opencode/directives_{wide,tall}.png`.
- Vendored vanilla CSS classes (`#ipiOverviewlayer`, `.ipiTaskItem*`,
  `.ipiMenu*`) — structure of the original client, still in our bundle.
- board.en.ogame.gameforge.com threads 841134 (Directive IV dispatch
  quirk, fusion-teardown hotfix, Directive VI mission texts,
  no-reset semantics), 842283 (Directive III mission 8), Update 12.0
  changelog snippet ("Fixed Directives not completing despite all
  requirements being met").
- forum.origin.ogame.gameforge.com PTS thread 453 (Directive I
  “Energy”-style positive-energy completion + mobile sync bug, 2026).
