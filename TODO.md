# SpaceShelf — TODO

Feature-gap inventory as of 2026-11-17 (after bot phases 0–1, random planet
names and the Collector/General/Discoverer class rework). Verified against the
codebase; file references included.

## Game engine gaps

### 1. Officer system — fully stubbed (biggest functional gap)

Player-facing Premium page exists but does nothing, and every officer-dependent
code path silently sees "no officer".

- [ ] DB: add officer fields (hired-until timestamps) to `users` (or an
      `officers` table) + migration
- [ ] Hire/renew flow with dark matter costs + routes
      (`PremiumController` currently only renders the DM balance)
- [ ] Implement `PlayerService::hasCommander/hasAdmiral/hasEngineer/
      hasGeologist/hasTechnocrat()` (all `return false; // TODO: add logic`)
- [ ] Apply the actual bonuses: production, fleet speed (admiral),
      acceleration discounts, construction speed (engineer), geologist
      crystal/geo-crawler handling, technocrat research time
- [ ] Officer expiry tick handling (lazy check on login/update is enough)
- [ ] Tests

### 2. ACS (multi-attacker) battle polish

ACS fleet unions exist (`AcsDefendMission`, union routes) but battle reports
are incomplete:

- [ ] Send battle report to all ACS Attack participants
      (`AttackMission.php:447`, `BattleReport.php:145`)
- [ ] Show individual participating fleets/defenders in reports
      (`AttackMission.php:717`)
- [ ] Reaper debris share for multi-attacker battles
      (`AttackMission.php:250`)
- [ ] Include ACS Defend fleets in espionage reports
      (`EspionageMission.php:503`) and in counter-espionage chance
      (`EspionageMission.php:118`)

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
- [ ] **Phase 5 — Officer hiring**: blocked by engine gap #1 above
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
