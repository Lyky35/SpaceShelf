# SpaceShelf — TODO

Feature-gap inventory as of 2026-11-17 (after bot phases 0–1, random planet
names, the Collector/General/Discoverer class rework, the Legor account
disable, shop-tab removal and the full officer system including its UI).
Verified against the codebase; file references included.

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
- [ ] Engineer: Halve losses to defenses (needs identical changes in both
      `PhpBattleEngine` and `RustBattleEngine`; the Rust engine binary would
      also have to be rebuilt)
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

## Bot AI roadmap (docs/BOT-AI-PLAN.md phases 2–6)

Phases 0–1 shipped: heartbeat, economy brain, colonisation, random planet
names, Collector/General/Discoverer behavior classes wired to the in-game
character class. Still missing:

- [ ] **Phase 2 — Fleet & growth**: inter-planet transport policy (never moves
      resources between own planets), debris-field recycling/harvesters
- [ ] **Phase 3 — War & spy AI**: espionage scouting loop, probe → mass-attack
      combo with battle-engine pre-simulation, threat assessment / fleet save,
      per-target attack cooldowns + avoid-list. Bots never attack today even
      though they sit on the highscore — this is what makes "bots raid real
      players" actually true.
- [ ] **Phase 4 — Expeditions**: expedition loop (currently unused Discoverer
      class bonus)
- [ ] **Class ships**: accounts unlock Crawler/Reaper/Pathfinder, brain does
      not build them yet — crawlers for Collector mine bonuses, reapers for
      Generals, pathfinders for Discoverer expeditions
- [x] **Phase 5 — Officer hiring**: shipped — each profile keeps its key
      officer hired (collector→Geologist, general→Admiral,
      discoverer→Technocrat, renew ≤2 days left, DM reserve floor 1000; see
      `config/bots.php` `officers` + `UtilityBrain::hireOfficer()`). Throttled
      by DM income: bots currently only have the 8000 signup bonus, so
      sustainable upkeep needs Phase 4 expedition rewards.
- [ ] **Phase 6 — Scale & tuning**: admin panel "Bots" page (list / enable /
      edit personality — today this needs artisan/tinker), simulation tuning
      harness, optional per-bot highscore opt-out

### Smaller bot items

- [ ] Defense depth: `UtilityBrain::buildDefense()` deliberately stops at
      turret tiers — add small/large shield domes and missile-silo/ABM defense
- [ ] Moon play for bots (build/defend moons; moon destruction not planned)
- [ ] Headless arrival sweeper for idle accounts (fairness; open decision #6
      in the plan doc)

## Open design decisions (plan doc §12, some answered by current setup)

- Bots visible on highscore: **yes** (current behavior)
- Bots attack real players: pending Phase 3 — cap raid targets, protect top
  ranked human, no farm-loops
- Bots vs bots free PvP: recommended yes (self-balances war AI)
