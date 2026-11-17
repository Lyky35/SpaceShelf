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
- [ ] Show individual participating fleets/defenders in reports
      (`AttackMission.php:717` data layer — per-fleet data is available in
      `$battleResult->attackerFleetResults` / `defenderFleetResults`; report
      view still renders aggregated attacker/defender blocks)
- [x] Reaper debris share for multi-attacker battles — shipped (2026-11-17;
      collected debris pool is split between union fleets by surviving Reaper
      cargo capacity, credited to each fleet's return mission, one harvest
      message per harvesting player; previously the pool vanished in ACS
      battles). `AttackMission.php:250` fixed.
- [ ] Include ACS Defend fleets in espionage reports
      (`EspionageMission.php:503`) and in counter-espionage chance
      (`EspionageMission.php:118`) — needs verification against original
      OGame behavior before implementing (AGENTS.md game-accuracy rule)

### 3. Expedition battle debris

- [ ] Collect debris fields from expedition combat battles
      (`BattleReport.php:246`)

### 4. Robustness / correctness debt (~72 TODOs, notable ones)

- [ ] DB transaction wrappers in `PlayerService` (`:685`, `:1816` area
      `PlanetService`) and related queue-service paths
- [ ] Espionage: validate probe slot 16 edge case (`EspionageMission.php:494`)
- [ ] Bulk-delete mission rows to avoid per-mission bottleneck
      (`PlayerService.php:914`)
- [ ] Unit/feature tests for battle report edge cases (deleted planets,
      attacker/defender research levels) and fractional-resource updates
      (`BattleReport.php:119/335`, `PlanetService.php:1395/1400`)

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
