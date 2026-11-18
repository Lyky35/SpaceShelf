# SpaceShelf — TODO

Feature-gap inventory as of 2026-11-17 (after bot phases 0–4, random planet
names, the Collector/General/Discoverer class rework, the removal of the
Legor account, shop-tab removal and the full officer system including its
UI).
Verified against the codebase; file references included. Also includes an
audit of the open upstream OGameX issues (2026-11-17) — see *Inherited
upstream bugs* below.

## Game engine gaps

### 1. Officer system — implemented (2026-11-17), remaining sub-items

Shipped: `Officer` enum (weekly prices per classic OGame: Commander/Technocrat
10000, Admiral/Engineer 5000, Geologist 12500 DM), `OfficerService` (hire with
Dark Matter, expiry stacking), 5 `*_expires_at` columns on `users`,
`premium.officer.hire` route, hire forms + status on the premium page and
real `PlayerService::has*()` switches. Bonuses active: Engineer +10% energy,
Geologist +10% mines, Commanding Staff +2% mines/+2% energy/+1 fleet slot/
+1 espionage, Admiral +2 fleet slots, +1 expedition slot and tactical retreat
ratio 3, Technocrat -25% research time and +2 espionage levels (effective
level used in espionage missions, galaxy view and research page `(+N)` badge).

UI: two-line hire button (label + per-week cost) positioned inside each
officer card; top-bar officer icons show the colored sprite frame (`#officers
a.<officer>.on`) when hired and link to the premium page; the icon strip gets
the vanilla framed border when one / all officers are hired.

Remaining officer bonus items from the recruitment tooltips:

- [ ] Admiral: Combat simulation save slots +20 (no combat save feature exists)
- [x] Engineer: Halve losses to defenses — shipped (2026-11-17; defense repair
      is a shared post-battle pass in `BattleEngine::simulateBattle()`, not in
      the per-engine round code, so one boost covers both engines and the Rust
      binary was untouched. The defending planet owner's engineer halves the
      losses surviving the base repair rate, lifting the 70% default to the
      vanilla 85% and a 0% rate to 50%.)
- [ ] Commander: Interface perks (+40 favorites, shortcuts, transport scanner,
      advertisement-free) — no corresponding server-side features

### 2. ACS (multi-attacker) battle polish

ACS fleet unions exist (`AcsDefendMission`, union routes) but battle reports
are incomplete:

- [x] Send battle report to all ACS Attack participants — shipped (2026-11-17;
      every union member that dispatched a fleet now receives the same combat
      report, and a first-round wipe sends exactly one lost-contact message per
      participant). `AttackMission.php:447` fixed, `BattleReport.php:145` was
      the message fan-out path.
- [x] Show individual participating fleets/defenders in reports
      (`AttackMission.php:717` data layer — per-fleet data was available in
      `$battleResult->attackerFleetResults` / `defenderFleetResults` but unused;
      battle reports now store a per-fleet breakdown (owning player, origin
      coordinates snapshotted at report creation, unit composition and losses)
      and the report view lists each fleet separately under the aggregated
      totals whenever a side has more than one fleet, matching the original
      game's ACS combat reports)
- [x] Reaper debris share for multi-attacker battles — shipped (2026-11-17;
      collected debris pool is split between union fleets by surviving Reaper
      cargo capacity, credited to each fleet's return mission, one harvest
      message per harvesting player; previously the pool vanished in ACS
      battles). `AttackMission.php:250` fixed.
- [x] Include ACS Defend fleets in espionage reports
      (`EspionageMission.php:503`) and in counter-espionage chance
      (`EspionageMission.php:118`) — shipped (2026-11-17; verified against the
      original game: the ACS wiki states "when a planet with an ACS defence is
      being probed, the number of ACS ships will be added to the planet's
      original fleet", so docked ACS Defend fleet ships now raise the
      counter-espionage chance and appear merged into the spy report's fleet
      section; snapshot taken before any counter-espionage battle).

### 3. Expedition battle debris

- [x] Collect debris fields from expedition combat battles
      (`BattleReport.php:246`) — shipped (2026-11-17; verified against the
      original game: deep space (position 16) debris fields created by
      expedition battles can only be harvested by Pathfinders, so battle
      reports of expedition battles now hint at the required Pathfinder count
      instead of Recyclers; the pos-16 debris field creation itself already
      existed)

### 4. Robustness / correctness debt (~72 TODOs, notable ones)

- [x] DB transaction wrappers in `PlayerService` (`:685`, `:1816` area
      `PlanetService`) and related queue-service paths — shipped (2026-11-17;
      the two `@TODO: add DB transaction wrapper` markers were in
      `PlayerService::updateResearchQueue()` and
      `PlanetService::updateUnitQueue()`, now both wrapped in
      `DB::transaction()` so queue completion, level/unit awarding and next-item
      scheduling commit atomically; the `spaceshelf:test:race-condition-unitqueue`
      command fails identically on unmodified HEAD, so its remaining race is
      unrelated to this change)
- [x] Espionage: validate probe slot 16 edge case (`EspionageMission.php:494`)
      — shipped (2026-11-17; verified the report builder is unreachable for deep
      space: `isMissionPossible()` rejects non-planet/moon targets and missing
      target planets and `processArrival()` requires a target planet ID, so a
      target player is always present; documented in code and covered by the new
      `testFleetCheckToPosition16Error` asserting probes to slot 16 are rejected
      as both planet and debris-field targets, matching vanilla's lack of a spy
      option on debris fields)
- [x] Bulk-delete mission rows to avoid per-mission bottleneck
      (`PlayerService.php:914`) — shipped (2026-11-17; `PlayerService::delete()`
      now collects the involved mission IDs once per planet and issues two bulk
      `DELETE`s — children via `parent_id` first, then the involved rows —
      matching the row-by-row behavior for the realistic two-level
      outbound/return chains; covered by the new `PlayerServiceDeleteTest`)
- [x] Unit/feature tests for battle report edge cases (deleted planets,
      attacker/defender research levels) and fractional-resource updates
      (`BattleReport.php:119/335`, `PlanetService.php:1395/1400`) — shipped
      (2026-11-17; new tests: `testBattleReportRendersAfterTargetPlanetDeletion`
      (report falls back to stored coordinates after the production
      `permanentlyDeletePlanet()` purge),
      `testBattleReportShowsAttackerAndDefenderResearchLevels` (both sides'
      snapshotted tech render as ×10 percent),
      `testDispatchFleetResourcesArriveAtDestination` (transport cargo lands on
      the destination), `testUpdateResourcesAccumulatesFractionalProduction`
      (30 updates × 30s at 30/h still yields 30) and
      `testBulkResourceUpdateIsCappedAtStorageLimit` (2 days' production stops
      at the storage cap); the stale TODO comments in
      `BattleReport.php`/`PlanetService.php` are replaced accordingly)

### 5. Directives (quest system) — unimplemented, UI is a dead stub (upstream #843)

**Status: research + implementation plan complete (docs/DIRECTIVES-PLAN.md);
parked for later (2026-11-17). Not scheduled, not active work — the dead
stub button stays as-is until someone picks it up. Re-read plan §1 before
implementing.**

Vanilla has **7 chapters** ("Directive I – A Sound Economy" … "Directive
VI – Alien Life"), each with ~10 missions with individual rewards (incl.
Dark Matter, e.g. 500 DM for "Open the mission overview") plus a chapter
bonus claimable via "Claim All"; rewards land on the planet active at
claim time. Vanilla look/behavior fully researched in
`docs/DIRECTIVES-PLAN.md` §1. Implementation plan: same file. Dependency
status: Dark Matter (upstream #770) — done here; teardown exists (but has
the #1624/#1625 bug below); lifeforms (upstream #228) — missing,
Directive VI is skipped.

Current state — the button is dead leftover HTML from the vanilla client
dump:

- `main.blade.php:496–511` renders the `#ipimenucomponent` box: "Directives"
  header (`t_ingame.layout.menu_directives`), empty `hidden`
  `.ipiMenuBody`/`.ipiMenuFooter`, and an unreplaced placeholder href
  `#TODO_page=ajax&component=ipioverview&action=overviewLayer&ajax=1` —
  clicking only appends garbage to the URL hash.
- The vendored OGame JS has the IPI panel machinery
  (`ipiMenuConfig.ipiMenuContentUrl`) but it is never configured, so it
  silently no-ops. No routes, no tables, no quest data exist. 12
  `data-ipi-hint` highlight markers in the layout are orphaned.
- Related upstream: #978 (registration newbie/veteran choice, gates the
  highlight hints) — open.

- [ ] P0: extract the 7 vanilla chapters + ~70 missions (conditions/
      rewards/texts incl. narrator dialogue) into `config/directives.php`
      + `resources/lang/en/t_directives.php` (blocking; Directive VI
      lifeform chapter skipped and recorded)
- [ ] P1: `directive_progress` migration, condition engine, lazy evaluation
      in `GlobalGame` middleware, atomic claim endpoint (rewards to the
      **active** planet via `PlanetService::addResources/addUnits`,
      `DarkMatterService::credit`), tests
- [ ] P2: wire the existing `#ipimenucomponent` panel to a content partial;
      delete the `#TODO_` href
- [ ] P3 (optional): newbie `data-ipi-hint` highlights — depends on the
      #978 registration decision

### 6. Bot diplomacy / alliances — researched, PARKED (2026-11-17)

Feasibility survey for letting bots ally with players and each other, done
in conversation; parked on the user's request, no code or spec written.
Key finding: **the fork already ships a full alliance system**, so the
human-accurate approach is simply "use it" (bots are normal player
accounts) rather than scripting pseudo-diplomacy.

- `app/Services/AllianceService.php`: complete programmatic API —
  `createAlliance`, `applyToAlliance`, `getPendingApplications`,
  `acceptApplication`/`rejectApplication`, `kickMember`, `leaveAlliance`,
  ranks/permissions/texts; plus alliance depot + chat services.
- Tier plan agreed in outline (each tier shippable as its own PR):
  1. emergent diplomacy, no alliances: grudge/revenge loop and gift-giver
     protection derived from `FleetMission` history; garrison support.
  2. `AlliancePolicy` in the brain: personality-driven apply/create/accept
     (collectors stay lone wolves), own-alliance raid ban, ride along on
     ally attack history, betrayal-driven departures.
  3. coordination: synced-ETA attacks with ally fleets, tribute, rare
     canned alliance-chat lines.
- Gap to note for any future PR: `AttackMission` does **not** block attacks
  on own-alliance members — humans can currently raid allies; bots would
  have to self-enforce until the engine enforces it.

## Inherited upstream bugs — open OGameX issues verified present (2026-11-17)

Audit of all 113 open issues on the upstream repository (`lanedirt/OGameX`).
The fork has merged upstream up to tip `a3d5822d` (2026-11-16), so an open
upstream bug lives in this tree unless the fork's own diff already touches
the affected code. Every entry below was checked against the current tree
(upstream issue numbers are cited for cross-reference).

### Fleet mission crash chain (#1639 / #1640 / #1641 / #1341)

Most operationally dangerous cluster: one broken fleet mission can lock an
account out of every in-game page.

- [x] **#1641 — one failing fleet mission → 500 on every page.**
      `GlobalGame.php:61` calls `PlayerService::updateFleetMissions()` on
      every in-game request; `PlayerService.php:729–776` processed all
      arrived missions in a single `DB::transaction` and rethrew any
      per-mission exception, so the broken mission replayed on every page
      load (admins impersonating the victim were locked out too). Related:
      `getArrivedMissionsByPlanetIds()` (`FleetMissionService.php:411`) had
      no `orderBy`, so arrival order wasn't guaranteed even today.
      **Fixed 2026-11-17 (containment):** each mission now processes inside
      a savepoint (nested transaction), so one failure rolls back only that
      mission and never reaches the request; a mission that fails 3 times
      is quarantined (`update_failures` column, logged, marked processed) so
      it stops being retried forever; arrivals are now ordered by
      `time_arrival, id`. Root causes (#1639/#1640) remain below.
- [x] **#1639 — fleet arriving at an abandoned moon fights a battle and
      crashes the attacker.** `markAsDestroyed()` (`PlanetService.php:233`,
      fork-modified) blocks abandoning only while the **owner's own** fleets
      are outbound; incoming foreign fleets were not handled:
      `checkDestroyedTarget()` (`GameMission.php:487`) runs at dispatch
      only, `GameMission::process()` had no destroyed-target check, and the
      battle at the destroyed moon reached `calculateWreckField()`
      (`BattleEngine.php:727`) which calls `->planet()` unconditionally →
      throws → chains into #1641.
      **Fixed 2026-11-17:** `GameMission::process()` now validates the
      destination at arrival (`handleDestroyedTargetArrival`): return
      fleets are rerouted to the parent planet (same rebind the daily
      purge already does), every other fleet does nothing at the dead
      moon and returns home intact, and no battle is ever fought there.
      Destroyed planets stay attackable by design.
- [x] **#1640 — `planet()`/`hasPlanet()` miss a destroyed parent planet.**
      Both resolved through the player's planet list
      (`PlanetService.php:1640–1677`), which filters `destroyed = 0`
      (`PlanetListService.php:44`), while `moon()`/`getParentPlanet()`
      resolve via the factory and do find destroyed bodies. This was the
      crash site for #1639 (`No planet found for this moon.`).
      **Fixed 2026-11-17:** `planet()`/`hasPlanet()` now resolve through
      `getParentPlanet()` (coordinate factory, destroyed bodies included),
      consistent with `moon()`.
- [ ] **#1341 — destroyed moons break messages.** Upstream fix PR #1494
      (`FleetMissionPlanetFormatter` + mission redirection + battle-report
      origin snapshots) is still unmerged and absent from this tree;
      messages and combat reports for bodies purged mid-flight render
      broken `[planet][/planet]` placeholders.
      **Partial fix 2026-11-17 (crash):** espionage reports whose reported
      player's account was deleted (`planet_user_id` FK is `ON DELETE
      SET NULL`) no longer 500 the messages page —
      `EspionageReport::getEspionageReportParams()` now resolves the
      display name via the current planet owner, the stored user id, the
      report's own `player_info` name snapshot, then `__('Unknown')`,
      mirroring the `BattleReport` convention. Placeholder rendering for
      purged bodies remains upstream #1494 work.

### Ops — root-owned daily log disables php-fpm error logging — fixed (2026-11-17)

- [x] **#1642 — daily log rotation writes a root-owned file.** Verified
      live in our deployment: `storage/logs/laravel-<today>.log` is owned
      by `root:root` (created by the root scheduler/cron process) while the
      php-fpm pool runs as `www-data` and cannot append to it the rest of
      the day. Run scheduled tasks as `www-data` in the container (or make
      the log dir group-writable) so php-fpm errors don't silently vanish.
      Fixed in `docker/entrypoint.sh`: scheduler, queue-worker and reverb
      roles now `su` their PHP process to `www-data` and chown
      `storage/`+`bootstrap/cache` on start, so midnight rotations and
      every file a background job creates are pool-writable.

### Production factor math (#1660 / #1661; pairs with #1629 energy display)

- [x] **#1660 — production factor floored to a whole percent before use.**
      `getResourceProductionFactor()` did `floor($production / $consumption
      * 100)` and fed that floored value to the stored-stats path and the
      per-object path, underpaying mines by up to ~1 percentage point on
      fractional energy deficits.
      **Fixed 2026-11-17:** the method now returns the exact ratio as a float
      (clamped 0–100, docblock updated); `updateResourceProductionStatsInner`,
      `getObjectProductionIndex` and the bot/utility comparisons consume it
      unscaled. Only the `ResourcesController::settings()` label floors
      (`(int) floor(...)`) to keep the vanilla "Production factor: X%" display.
      `ResourceProductionTest::testProductionFactorAppliedAsExactRatio` covers
      a 98.75% deficit (exact ratio vs floored 98% diverges on both the mine
      row and stored per-hour metal).
- [x] **#1661 — fusion reactor deuterium burn scaled by the production
      factor.** `updateResourceProductionStatsInner()` ran
      `$building_production_total->multiply($production_factor)` on a total
      that already contained the reactor's negative deuterium formula, so
      deficit planets burned less deuterium than they should; the per-object
      path (`calculateMine`) scaled it the same way.
      **Fixed 2026-11-17:** the production factor is now a separate argument
      to `GameObjectProduction::calculate()`/`calculateMine()` (previously
      merged into `$building_percentage`) and is applied only to mine output —
      metal, crystal and positive deuterium (synthesizer) — never to a
      negative deuterium formula (the reactor's burn). In the stored-stats
      path the reactor's deuterium is stripped before `multiply()` and added
      back unscaled, mirroring the existing energy restore.
      `testFusionReactorDeuteriumBurnNotReducedByProductionFactor` asserts
      identical per-object and stored −11/h burn at 98.75% and 100% factors.
      Energy display weirdness below 100% factor remains with #1629.


### Chat cluster (#1644–#1651) — chat ships from upstream unmodified

- [x] **#1644 — alliance chat history has no membership check.**
      `ChatService` queries history by the request-supplied alliance id
      (`:88`), so any logged-in user can pull another alliance's history.
      **Fixed 2026-11-17:** the gap was `ChatController::loadMore()`
      (`/chat/more`) — the only alliance-chat entry point without a
      membership check (`index()`, `sendMessage` mode 3 and `getHistory`
      mode 4 already compared `$user->alliance_id` against the request's
      `associationId`). `loadMore()` now returns `NOT_AUTHORIZED` for
      non-members, and the Reverb `chat.alliance.{id}` channel auth
      (`routes/channels.php`) already required membership. Tests:
      `ChatTest` verifies non-members are rejected from `/chat/more` and
      members can still page through their own alliance chat.
- [x] **#1648 — player names inserted into HTML unescaped.**
      `resources/js/ingame/chat.js:517` string-concatenates names into
      markup (XSS). Same class of issue nearby: `espionage_report.blade.php:7`
      renders `{!! $playername !!}`.
      **Fixed 2026-11-17:** escaped at the server root plus the client
      render sites. `GameMessage::replacePlaceholders()` now `e()`s the
      `[player]` and `[planet]` callback results, covering every game
      message body/subject rendered via `{!! !!}` (chat content was already
      `e()`'d server-side). Both espionage report templates render
      `$playername` with `{{ }}`. `chat.js` gained an `ogame.chat.escapeHtml()`
      helper applied at the four name-injection sites: chat bar tab, message
      title, reply-reference author, and the online player list. Tests:
      `MessagesTest` asserts crafted usernames/planet names reach message HTML
      escaped only.
- [x] **#1647 — no rate limiting on the chat send endpoint.** No throttle
      on the `chat.send` route nor in `ChatController`.
      **Fixed 2026-11-17:** named `chatsend` limiter (30 sends/minute per
      authenticated user, keying by user id) defined in
      `AppServiceProvider` following the `FortifyServiceProvider` login
      limiter convention, applied via `->middleware('throttle:chatsend')`
      on the `chat.send` route. Overshoots now return 429;
      `ChatTest::testChatSendRateLimited` covers it.
- [x] **#1649 — Reverb accepts WebSocket connections from any origin.**
      `config/reverb.php:85` sets `'allowed_origins' => ['*']`.
      **Fixed 2026-11-17:** `allowed_origins` defaults to the host of
      `APP_URL`, overridable via comma-separated `REVERB_ALLOWED_ORIGINS`
      (hosts with `*` wildcards; full URLs are normalized to their host,
      since Reverb's `verifyOrigin()` compares hostnames only via
      `Str::is`). Verified against the live server: `APP_URL` host clients
      establish normally, foreign origins get Pusher error 4009. Guarded
      by `WebsocketConfigTest`.
- [ ] **#1645 / #1646 / #1650 / #1651** — realtime silently disabled in
      production, NOT_AUTHORIZED reply re-send loop, missing reply author
      on live messages, no mobile WS reconnect/fallback. Same unmodified
      chat code; present as reported (not individually re-verified).
      **Partial fix 2026-11-17 (#1650):** the live-message reply reference
      showed no author because `chat.js` read `h.refAuhtor` (typo) — now
      `h.refAuthor`, matching the `ChatMessageSent` broadcast payload.
      (The same typo lingers in `e7c7…js`, but only inside the dead
      `_ogame_chat_removed` block.)


### Mechanics & correctness (verified present)

- [ ] **#1624 / #1625 — teardown blocked by completed research.**
      `ObjectService::canDowngradeBuilding()` (`:967–1035`) blocks a
      downgrade whenever any dependent object — including *completed*
      research — meets the requirement level. Vanilla only blocks active
      dependencies.
- [ ] **#1537 — wreck field loaded by coordinates without owner scope.**
      `WreckFieldService::loadForCoordinates()` (`:98`) takes whatever
      field row matches the coordinates; needs owner scoping.
- [ ] **#1203 — admin accounts are attackable.** No admin/role check in
      the fleet dispatch validation path.
- [ ] **#1288 — current planet selection shared across sessions.** It is
      user-record state (`PlayerService::setCurrentPlanetId()`), not
      session state, so a new login resumes on the last visited planet
      (hide-activity abuse + cross-device clobbering). Vanilla: per-session,
      home planet on fresh login.
- [ ] **#1516 — galaxy colonise ignores the colony-slot cap.**
      `PlayerService::canColonizePosition()` (`:894`) checks astrophysics
      level/position only and never consults `getMaxPlanetAmount()`; the
      mission itself fails, but the galaxy UI offers an impossible action.
- [ ] **#1188 — spy report never shows the player class.**
      `espionage_report.blade.php:13` (`<!-- TODO: implement player
      class -->`) and `espionage_report_full.blade.php:45` hardcode
      "Class: Unknown".

### Battle engine / reports / UI (verified present)

- [ ] **#1558 — Rust FFI memory leak.** `RustBattleEngine.php:46` still
      loads `storage/rust-libs/libbattle_engine_ffi.so` through PHP FFI and
      our `lib.rs` is upstream code verbatim (fork diff = comment renames
      only), so the per-call leak is ours too. Upstream's fix is the v2
      engine rewrite (open PR).
- [ ] **#1561 — battle report templates carry stale hardcoded `data-raw-*`
      mock metadata** (`battle_report_full.blade.php:74–76`: fixed
      timestamp, fake hashcode).
- [ ] **#1216 — fleet event tooltip hardcodes "Own fleet"** for every
      row including hostile ones (`eventrow.blade.php`, both row variants).

### Presumed present (identical upstream code; not individually verified)

- [ ] #307 (large shipyard orders build fewer units than requested),
      #1629/#1630 (resource-page energy values below 100% factor), #1168
      (dispatch confirmation lists ship names instead of "Fleet dispatch
      [time] (n) Ok"), #1514/#1541 (recycle mission not auto-selected at
      position 16), #1513 (dev shortcut creates no expedition debris
      field), #1542/#1539 (Facilities shortcut doesn't reach Space Dock),
      #1004 (class selection screen clips into fleet widget), #296 (full
      report tooltip covers loot amounts), #305 (admin settings form
      validation), #1510/#668 (incoming fleet details not gated by
      espionage level), #1538 (flaky `testFleetDispatchValidSpeeds` —
      random foreign-planet fixture unchanged, in
      `tests/Traits/CreatesForeignFixtures.php`).

### Audit exclusions (verified NOT present — don't re-investigate)

- **#1350** (quick mining yields half of expedition debris) — quick mining
  is not implemented in this fork; deep-space pos-16 debris is Pathfinder
  harvested (fork feature, covered by `PathfinderExpeditionDebrisHarvestTest`).
- **#1670** (plasma bonus missing from hourly production) — the plasma row
  is computed in `GameObjectProduction::calculate()` (`:124`), summed into
  the total (`:558`) and included in the resource page breakdown
  (`ResourcesController.php:131`); the upstream report is self-uncertain
  and no code-level gap was found.
- **#1613** (docker startup errors) — reporter-environment specific; our
  stack runs.

## Bot AI roadmap (docs/BOT-AI-PLAN.md phases 2–6)

Phases 0–6 are all shipped: heartbeat, economy brain, colonisation, random
planet names, Collector/General/Discoverer behavior classes wired to the
in-game character class, fleet growth, war & spy AI, expeditions with
Pathfinder class ships, officer hiring, and the admin Bots page plus the
`spaceshelf:bot:report` tuning harness. Remaining polish lives in the
smaller-items list below.

- [x] **Phase 2 — Fleet & growth**: shipped — inter-planet transports move a
      resource from storage-saturated planets with no own demand to planets
      whose unfinished queue backlog outruns their stock+production (cargo is
      capped by harbor `large_cargo`, transporters are built on demand; see
      `bots.transport` + `UtilityBrain::transportResources()`); recyclers
      harvest the debris fields at the bot's own planets and are built when
      debris exists with none in stock (`bots.recycle` +
      `UtilityBrain::recycleDebris()`); crawlers grow toward the engine's
      usable bonus cap of 8 per total mine level, gated to the Collector
      character class (`bots.harvest` + `UtilityBrain::crawlerCap()`).
- [x] **Phase 3 — War & spy AI**: shipped — `WarPolicy` runs after the
      recycle step: fleet saving (incoming attack fleet that outsamples our
      fleet + defense above the configured win ratio is evacuated to another
      own, unthreatened planet, or to an astrophysics-gated deep-space
      expedition fallback), an espionage scouting loop (probe stock of 3
      kept on the home planet, neighbours re-scanned when intel is older
      than 12h), and personality-scaled loot raids (aggression gates the
      raid roll; PhpBattleEngine pre-simulation averaged over 3 samples
      sizes the smallest fleet fraction that wins with 70% probability;
      large cargo rides along for the loot; unaffordable fuel skips the
      raid). All war "memory" derives from our own fleet missions (12h
      per-coordinate attack cooldown, 30%-loss avoid list reconstructed
      from return missions via `parent_id`) plus delivered espionage
      reports. Protected humans (top 10 on highscore with a ≥100k score
      floor so small worlds stay raidable), vacationers and out-of-score-
      band players ([0.2, 2.5]× ours; ≤50000 while we are unranked) are
      never scouted or raided; bots are always fair game. See
      `config/bots.php` `bots.war` + `app/Bots/Brain/WarPolicy.php`
      (tests: `tests/Feature/Bots/BotWarAITest.php`).
- [x] **Phase 4 – Expeditions**: shipped — `ExpeditionPolicy` runs after the
      war step: personality-gated expedition dispatch (per-profile per-visit
      odds: discoverer 1.0 / general 0.7 / collector 0.4; astrophysics ≥1
      gates it exactly like a human fleet and fixes the hold time; the fleet
      is 15% of the harbour's strike stock launched from the planet that can
      pay the trip (incl. hold-time burn) and keep a 1000 D war-fuel reserve;
      the engine's own slot cap throttles it — Discoverers' +2 class slots
      keep them sailing — and a 4h redispatch cooldown paces the gap after a
      return, read back from our own finished expedition return missions),
      plus Pathfinder salvage of expedition battle debris at position 16
      (stock-gated, ≥10k metal+crystal, fleet sized to the field; recyclers
      cannot touch deep-space debris). Expedition rewards — including Dark
      Matter — are the bots' recurring income stream for Phase 5 officer
      upkeep. See `config/bots.php` `bots.expedition` +
      `app/Bots/Brain/ExpeditionPolicy.php`
      (tests: `tests/Feature/Bots/BotExpeditionTest.php`).
- [x] **Class ships**: shipped — Generals build a small Reaper pack
      (`bots.classships.reaper_stock_target`, home planet) and Discoverers a
      Pathfinder pack (`pathfinder_stock_target`); the expedition policy rides
      exactly one Pathfinder when stock exceeds `bots.expedition.pathfinder_reserve`
      (`ExpeditionPolicy::expeditionFleet()`). The Pathfinder expedition Dark
      Matter bonus is enabled in `ExpeditionMission` (verified against the
      original game: a Pathfinder doubles positive expedition finds;
      `FleetDispatchExpeditionTest` covers both DM bands). (Crawlers for the
      Collector mine bonus shipped with Phase 2.)
- [x] **Phase 5 — Officer hiring**: shipped — each profile keeps its key
      officer hired (collector→Geologist, general→Admiral,
      discoverer→Technocrat, renew ≤2 days left, DM reserve floor 1000; see
      `config/bots.php` `officers` + `UtilityBrain::hireOfficer()`). Throttled
      by DM income: bots currently only have the 8000 signup bonus, so
      sustainable upkeep needs Phase 4 expedition rewards.
- [x] **Phase 6 — Scale & tuning**: shipped — admin "Bots" page
      (`/admin/bots`: pause/resume, profile switch that re-jitters the
      personality and syncs the character class — class sync rides the
      engine's cost/active-fleet rules and flashes an error when it cannot
      apply; per-bot highscore opt-out via `bots.hide_from_highscore`,
      filtered in `HighscoreService` with the hidden count in the cache key),
      and `spaceshelf:bot:report` as the tuning harness: live per-profile
      score, mission-mix and Dark Matter income instead of a separate
      simulator. Profile→class map now lives in `PersonalityFactory::CLASS_MAP`.

### Smaller bot items

- [x] Defense depth: `UtilityBrain::buildDefense()` now grows the shooting tiers
      to target, then spends the surplus on depth — shield dome layers
      (`shield_dome_target`), a missile silo raised through the scored building
      pass (`abm_silo_level`), and an ABM stock capped by `abm_target` and the
      engine's 10-per-silo-level storage.
- [x] Moon play for bots — shipped (2026-11-17; every owned moon gets the
      lunar facility line (lunar base, sensor phalanx, then robot factory and
      shipyard, which this fork gates moon defense behind) and defensive
      personalities garrison half their planetary combat target there. Moons
      produce nothing, so `transportResources()` ships the development plan's
      cost from storage-saturated planets. Moon destruction remains out of scope.)
- [ ] Headless arrival sweeper for idle accounts (fairness; open decision #6
      in the plan doc)

## Open design decisions (plan doc §12, some answered by current setup)

- Bots visible on highscore: **yes** (current behavior)
- Bots attack real players: **yes, since Phase 3** — score-banded targets,
  top-ranked humans (with score floor) and vacationers protected, no
  farm-loops via cooldowns + avoid-list
- Bots vs bots free PvP: recommended yes (self-balances war AI)
