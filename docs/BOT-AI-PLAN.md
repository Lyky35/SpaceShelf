# SpaceShelf — Autonomous Bot Players (Implementation Plan)

Status: **Phases 0–1 implemented** (`spaceshelf:bot:create`, `spaceshelf:bot:tick`,
`BotHeartbeatJob`, `SnapshotBuilder`, `UtilityBrain` v1, `bots` table + `bot` role,
detection-panel exclusion). Colonisation (colony-ship pacing + target finder) was
pulled forward from Phase 2 into Phase 1 because it is core economy behavior;
transports, war/spy AI and expeditions remain in later phases below.
Goal: populate the universe with NPC players ("bots") that log in periodically, play
the game competently (build, research, fleet, colonize, spy, attack, expedition,
hire officers), and coexist with real players without degrading the game or tripping
the bot-detection system.

---

## 1. Ground truth from the engine (why the design is shaped this way)

Verified against the current codebase:

1. **Everything is lazy / request-driven.** Production, build/research/unit queues and
   fleet arrivals only advance when someone loads a page:
   `GlobalGame` middleware → `PlayerService::update()`, `PlanetService::update()`,
   `PlayerService::updateFleetMissions()` → `FleetMissionService::updateMission()` →
   `GameMission::process()`. A headless bot gets the same effect by invoking these
   methods directly on a timer — this **is** our "log in".
2. **The service layer is the whole API.** Controllers are thin wrappers. Canonical
   entry points an AI needs:
   | Action | Service call |
   |---|---|
   | Build building | `BuildingQueueService::add(PlanetService, int $objectId)` |
   | Research | `ResearchQueueService::add(PlayerService, PlanetService, int $objectId)` |
   | Ships/defense | `UnitQueueService::add(PlanetService, int $objectId, int $amount)` |
   | Any fleet mission | `FleetMissionService::createNewFromPlanet(PlanetService, Coordinate, PlanetType, int $missionType, UnitCollection, Resources, float $speedPercent, int $holdingHours)` resolved per player: `resolve(FleetMissionService::class, ['player' => $player])` |
   | Read state | `PlanetService::getResources()/energy()/getMetalProductionPerHour()/getShipUnits()/getObjectLevel()`, `PlayerService::getResearchLevel()` |
   | Requirements/costs | `ObjectService::objectRequirementsMetWithQueue()`, `ObjectService::getObjectPrice()` |
   | Tech checks | `ResearchQueueService::add()` throws on requirement failure — reuse as validator |
3. **Mission type ids** (`GameMissionFactory`): 1 Attack, 3 Transport, 4 Deployment,
   6 Espionage, 7 Colonisation, 8 Recycle, 10 Missile, 15 Expedition (pos 16 only).
4. **Spy data is structured**: `espionage_reports` table (JSON `resources`, `ships`,
   `defense`, `buildings`, `research`, `debris`, `planet_user_id`). Messages only
   *reference* it. Bots read the table directly.
5. **Dark matter** fully implemented: `DarkMatterService::credit/debit`, regen
   scheduler, expedition income. Merchant implemented. **Officers are stubs** —
   `PlayerService::hasCommander()/hasAdmiral()/hasEngineer()/hasGeologist()/hasTechnocrat()`
   all `return false` (TODOs). Consumers already wired: geologist production bonus,
   admiral → tactical retreat 3:1 vs 5:1, admiral → extra spy hints.
6. **No empty-position finder** exists (`PlanetServiceFactory::determineNewPlanetPosition()`
   is new-player placement only) → we add one.
7. **Bot detection already exists** (admin panel) and flags from `fleet_missions`:
   ≥18 active-hours/day + ≥50 missions + ≥18 missions/slot/day; expedition re-dispatch
   <10 s after return; fleet-save <10 s after being attacked; shared IPs. Mission
   types 6 (espionage) and 9/10 (missile) are excluded from rate checks.
8. **No `is_bot` flag** on users; roles are Spatie (`admin/moderator/player`).
   `Highscore::scopeValidRanks()` already lets us hide accounts from rankings
   (rank 0), and Legor proves the pattern.

**Design consequence:** bots are *real full user accounts* (so the entire game loop —
highscores, messages, attacks on them, galaxy view, alliances — works with zero
special-casing), tagged with a dedicated Spatie role `bot`, driven by per-bot
scheduled jobs.

---

## 2. Architecture

```
Scheduler (routes/console.php)
   └─ spaceshelf:bot:tick (every minute)
        ├─ find enabled bots with next_action_at <= now
        └─ dispatch BotHeartbeatJob with delay = jitter(0…59 s) per bot
                                   │
queue worker (existing spaceshelf-queue-worker)
                                   ▼
BotHeartbeatJob (fresh app scope per job, runs as the bot player)
   1. "login":    PlanetService::update() on each planet; PlayerService::update();
                  PlayerService::updateFleetMissions()   ← mirrors GlobalGame middleware
   2. "observe":  build a BrainSnapshot (resources, queues, fleets, techs, threats,
                  own spy reports, target candidates)
   3. "decide":   BotBrain::decide(snapshot, personality) → ordered action list
   4. "act":      execute via the same service calls controllers make
   5. "logout":   record stats; set next_action_at = now + jitter(45…90 min)
```

- **One bot = one PlayerService per job**, fresh instances, `DB::transaction` around
  multi-step fleet actions — matches AGENTS.md singleton-state warning.
- **No bots share a job** — a bad decision or crash is contained per bot; scales
  linearly with queue workers.
- Tick every minute with randomized per-bot intervals means bots do *not* all wake at
  :00 — activity is naturally smeared across the day.

### Proposed packages / files

```
app/Bots/
  Brain/
    BotBrain.php                  # interface: decide(BrainSnapshot): list<Action>
    UtilityBrain.php              # v1: utility-scored action selection
    Snapshot/
      BrainSnapshot.php           # immutable "world view" the brain may read
      SnapshotBuilder.php         # loads it via services; owns all DB reads
    Actions/
      BuildBuilding / Research / BuildUnits / DispatchFleet
      TransportResources / Colonise / Espionage / Attack / Expedition / RepairFleet
      (each implements Action with canApply()+apply())
  Personality/
    Personality.php               # weights + traits (aggression, eco bias, explorer)
    PersonalityFactory.php        # profiles: pacifist/economizer/warrior/explorer/dormant
  BotRegistry.php                 # enable/disable/next-tick bookkeeping
app/Models/
  Bot.php                         # user_id, personality(json), next_action_at,
                                  # last_action_at, total_ticks, state(json debug)
app/Jobs/
  BotHeartbeatJob.php
app/Console/Commands/Bots/
  CreateBots.php                  # spaceshelf:bot:create --count --profile --galaxy
  BotStatus.php                   # per-bot state/debug
  ToggleBots.php
app/Services/
  ColonisationTargetFinder.php    # new: random valid empty coord honoring astro levels
  ThreatAssessor.php              # incoming-fleet/raid-risk heuristics for defense
database/migrations/
  2026_xx_create_bots_table.php
config/bots.php                   # tick cadence, action caps, detection tuning
```

---

## 3. Bot creation & identity

- `spaceshelf:bot:create --count=25 --profile=mixed --galaxy=1 [--seed-start=eco]`
  - Creates a `User` exactly like registration does: reuse
    `CreateNewUser::create()` / `InitialUserDataService::createFor()` → correct
    starting resources, `UserTech` row, first planet via the standard placement tier
    logic, then `assignRole('bot')`, random personality, `Bot` row.
  - Names: reuse `CreateNewUser::generateUniqueName()` (space-themed pools) so bots
    look like normal players in chat/messages/highscores.
- Highscore visibility: **config option** `bots.show_in_highscore`
  (default true → bots populate rankings, which makes the universe feel alive; set
  rank 0 via the `validRanks` pattern if admins want them hidden).
- `users.last_ip`/`register_ip`: set a synthetic per-bot-pool IP (or the host's) once
  and keep bots out of the shared-IP detector (below).

---

## 4. The decision engine (v1 = utility scoring, not ML)

Each heartbeat the brain proposes candidate actions and picks the best by score:
`score = base_weight(personality) × feasibility × urgency − cost_penalty`.
Deterministic, explainable, easy to tune in `config/bots.php`, trivially unit-testable.

**Pre-decision "housekeeping" (runs first, in order — this is how a real OGame
player behaves):**

1. **Fleet bookkeeping** (see §7): recall/save doomed fleets, process finished
   missions, recycle own debris fields near home, re-dispatch expeditions with jitter.
2. **Colonise** if planet slots and colony ship available (§6).
3. **Resource logistics** (§5): queue transports to unblock the biggest growth bottleneck.
4. **Queues**: fill building queue, then research queue, then shipyard/defense queues
   according to the build policy below.

**Build policy (economic core).** The brain computes the planet's
"production vector" (metal/crystal/deuterium/hour, energy factor 0–100 %):

- Energy factor < 100 % → build power (solar) / solar satellites first.
- Otherwise pick the resource whose **storage saturation × demand** is worst:
  crystal starves shipyard → prioritize crystal mine when crystal < production
  needs; deuterium blocks research/colonies → prioritizes fusion + terraforming
  weighting; build the *cheapest upgrade that removes the current bottleneck*,
  i.e. the classic OGame ratio heuristics encoded as weights (v1 defaults derived
  from common OGame economy guides, then tuned by playing bots against each other).
- Research policy: economy techs to next colony tier; then to next fleet tier
  (impulse → hyperdrive → propulsion → laser/ion/plasma) with per-personality bias;
  astrophysics keeps pace with colonisation ambitions; espionage to the level of the
  strongest local rival (spy counter-chance).
- Fleet/defense policy: maintain a standing fleet sized to `threat × wealth`;
  offense ships (cruisers/battlecruisers) vs defense (heavy/plasma) split per
  personality; never spend below a reserve floor (20 % of stockpile).

---

## 5–7. Feature mapping (your list → concrete design)

### 5. Resource management / inter-planet transport
- Every tick per planet: compute `deficit(resource) = (queued build cost beyond this
  planet's production in N hours)` vs `surplus(other planets)`.
- Candidate action → `UnitQueueService::add` for `large_transporter` when queue is
  empty, then `createNewFromPlanet(..., missionType=3, [transporters], Resources(...))`
  to the deficit planet (Transport has no friend/foe check, so it works fine to any
  of the bot's own planets; target must exist — `makeForCoordinate`).
- Cargo sizing: amount ≤ cheapest transporter capacity × min(count, affordable);
  ships dispatched at 100 % speed, cargo sent in one ship per resource mix.

### 1. Buildings / research / fleets / defense
All via the §1 table calls, driven by the build policy. Defense also reacts to
`ThreatAssessor`: number of hostiles within ≤2 systems with >X fleet value, plus
recent battle reports against the bot (from `battle_reports` joined via messages or
direct query). Defense budget = `min(wealth×p_defense, expected raid value × k)`.

### 2. Colonisation (planet → system → galaxy)
- New service `ColonisationTargetFinder`:
  `findFor(PlayerService, int $maxSystemsFromHome, bool $crossGalaxy=false): ?Coordinate`
  — random-walks systems by `CoordinateDistanceCalculator`, filters `planets` rows
  where position empty in 1–15, honors `PlayerService::canColonizePosition()`
  (astro-gated edge positions), prefers high-temperature/high-metal positions via
  `getPlanetDescription()`/terrain fields when equal.
- Trigger: colony ship affordable in ≤ X hours (queue it in shipyard early, it idles
  until dispatch), astrophysics slot available, transport pre-stages needed metal to
  the new planet (start with a colonize+transport combo).
- Cross-galaxy: only for `explorer` personality with huge D/A reserves (long trips).

### 4. Espionage scouting loop
- Continuous target list generation: `TargetScout`:
  1. neighbors (same system, distance ≤ 3),
  2. recent highscore entrants with similar score (±1 tier),
  3. re-scan own cached intel when older than `intel_ttl` (default 24 h).
- Dispatch `missionType=6` with N probes (`espionage_probes_amount` on the user row
  exists already), probe count scaled to desired intel depth (ships/defense/buildings
  tiers from `canRevealData` gates).
- **Intel store = the `espionage_reports` table itself** (query own reports, freshest
  per coordinate). No new persistence needed.
- Decision gate for §5:
  `canProfit(target) = lootable(=min(resources, 50% by LootService semantics,
  + debris)) × success_probability(report.ships vs our strike power, using the
  battle engine as a free simulator — see below) − raid cost − retaliation risk`.

### 5. Probing attacks → massive attack
- **Probe first**: send a light, cheap attack fleet (e.g. 1–2 destroyers or scouts)
  *or* rely on the fresh espionage report as the "probe"; if it returns a fleet-move
  (target recalled their fleet), the planet is a soft target.
- **Attack**: build a strike force sized to `defense_value × 1.3` (simulating with
  the PHP battle engine before committing: run `PhpBattleEngine` offline against the
  *reported* defense — it's already available as a library; cheap, deterministic-ish
  sanity check before dispatch), cap fleet value to what's replaceable in ≤ 2 days.
  Dispatch `missionType=1` at 100 % speed, cargo ships included in-wave to load loot.
- **Loot run**: second wave of large transports timed to arrive after the attacker
  wave (`holdingHours` on the transport = attack flight time delta) — the classic
  attack+loot combo.
- **Cooldowns**: per-target attack cooldown (randomized 8–24 h); never re-attack the
  same target within 2 returns; if battle report shows loss > threshold, add target
  to "avoid" list with expiry.
- **Fleet save on incoming**: `ThreatAssessor` watches `fleet_missions` for inbound
  hostile missions to own planets; respond only if win chance < 0.3 → fly the fleet
  to a friendlier ally/bot or send on expedition (fleet-housing). **Response delay is
  randomized 5–15 minutes, never < 1 minute** (detection: <10 s fleet-save is flagged;
  also, an "offline" bot that reacts instantly is immersion-breaking).

### 6. Deep space expeditions
- When an expedition slot is free and deuterium surplus > threshold:
  `createNewFromPlanet(..., Coordinate(g, s, 16), PlanetType::DeepSpace, 15,
  scouts+few destroyers, Resources(...), speed, holdingHours=astro_level)`.
  (Expedition forces DeepSpace type internally; holding hours capped by
  astrophysics.)
- Re-dispatch on return with **random delay ≥ 90 seconds** (detection: <10 s after
  return is flagged — enforce a hard floor of minutes, personality-randomized).
- Pathfinder boost when available; expedition fleet capped at ~15–25 % of military.

### 7. Officers
**Blocked on game feature first** — see "Prerequisite work" below. Once real:
`OfficerService::hire(user, OfficerType, days)` with OGame DM cost formula
(300·days? no — costs scale: e.g. Engineer 500·days … exact formula to be verified
against the original game per AGENTS.md "game accuracy matters").
Bot rule: when `dark_matter > hireable_floor(personality)` and the officer's effect
is relevant to current goals:
- Engineer → before mega construction runs,
- Geologist → during long mining expansions,
- Technician → research-heavy phases,
- Admiral → before spy campaigns (spy-craft hints) or when tactical-retreat saving is
  valuable,
- Commander → speed up simultaneous multi-planet builds.
Bots spend DM via existing `DarkMatterService::debit()`.

---

## 8. Coexistence with the bot-detection system

Two-layer answer:

1. **Exclude by role.** Add a one-line `whereNotExists` / `role <> 'bot'` filter to
   `ServerAdministrationController`'s detection queries (and shared-IP groups).
   Detection is about *human* cheaters; our NPCs shouldn't pollute it. (Small,
   explicit, admin-visible: the panel gets a "bots (excluded): N" count.)
2. **Still behave believably** (so logs/observations don't scream "bot"):
   - randomized heartbeat intervals (45–90 min) with per-bot time-of-day bias
     (a "sleepy" bot does one tick in the evening; a "night owl" at 3 a.m.),
   - ≤ ~12 fleet dispatches/day, ≤ 1 expedition per return-visit,
   - no reaction to events under 60 s,
   - no perfectly regular actions — jitter every cooldown ±30 %,
   - per-bot personality means wildly different behavior patterns.

---

## 9. Prerequisite engine work discovered during planning

| Gap | Effort | Needed by |
|---|---|---|
| Officers feature (hiring UI+service+DB, 5–7 officer types, DM costs, effects: engineer build speed, geologist production, technician research, commander queue speed, admiral tactical retreat/spy) | **medium–large** | §7; also unlocks real-player premium features currently stubbed |
| Empty-colony finder service | small | §2 |
| `ThreatAssessor` service | small | §5, defense |
| Bot role filter in detection queries | tiny | §8 |
| Optional: headless fleet-arrival sweeper (scheduler cmd calling `updateFleetMissions()` for planets whose owner hasn't been "active" in 24 h) | small | fairness (idle players' defenders wouldn't process vs bot attackers) — mirrors OGameX roadmap "improved fleet mission processing" |
| Optional: per-player `universe_seed` for bot target-pool variety | tiny | §4/§5 |

Nothing else — building, research, ships, all 7 mission types, loot, expeditions,
DM, and espionage intel already exist.

---

## 10. Testing strategy (per AGENTS.md)

- **Unit tests**: `UtilityBrain` is a pure function `(Snapshot, Personality) → Actions`
  — snapshot fixtures golden-tested (classic OGame ratio scenarios: e.g. crystal-starved
  planet must queue metal/crystal mine before solar; must not build fleet below reserve).
- **Feature tests**: dispatch a bot heartbeat against a real seeded universe
  (existing test infra: `FleetDispatchTestCase` patterns, `Carbon::setTestNow`):
  - run N=100 simulated days → assert bot's score grows monotonically, colonies
    colonized, ≥1 battle fought, DM earned, no exceptions;
  - two hostile bots vs each other → assert attack/loot/fleet-save happens and
    both sides' state remains consistent (no NaN resources, no stuck queues);
  - detection test: run a bot for a simulated week → detection queries report 0 suspects.
- **Race safety**: heartbeat jobs + a simultaneous real-player attack = exactly the
  scenarios in `tests/Feature/FleetDispatch/`; run
  `spaceshelf:test:race-condition-game-mission` / `-unitqueue` after phase 2.
- **Simulation harness**: `spaceshelf:bot:simulate --days=30 --bots=20` that
  time-jumps in-process (Carbon + `travelTo` loop calling heartbeats) — lets us tune
  the economy without real time. This is the tool that will actually make the AI good.

---

## 11. Phased roadmap (each phase independently shippable)

| Phase | Deliverable | Est. |
|---|---|---|
| **0. Foundation** | `bots` table + `Bot` model + `bot` role + `ColonisationTargetFinder` + detection role-exclusion + config skeleton | S |
| **1. Heartbeat + economy bot** | `BotHeartbeatJob` + scheduler tick + `SnapshotBuilder` + `UtilityBrain` v1 doing §1-only (buildings/research/defense-lite) + `bot:create` | M |
| **2. Fleet & growth bot** | shipyard policy, transports (§5), colonisation loop (§2), recycling | M |
| **3. War & spy AI** | scouting loop (§4), probe→mass attack combo (§5), threat assessment, fleet save, avoid-list | M |
| **4. Expeditions + personalities** | expedition loop (§6), 5–7 personality profiles, behavior jitter & realism pass (§8) | S |
| **5. Officers** | prerequisite officers feature + officer-hire brain rule (§7) | M+L |
| **6. Scale & tuning** | simulation harness tuning pass, admin panel page "Bots" (list/enable/personality/edit), optional highscore opt-in/out per bot | M |

**v1 target = Phases 0–3**: bots that build sensibly, colonize, transport, spy, and
fight — covering items 1–5 of your list. Expeditions (6) slot in cheaply (Phase 4);
officers (7) require the game feature first.

---

## 12. Open decisions for the user

1. **Bot visibility**: on the highscore list (populated universe) or hidden (Legor-style)?
2. **Can bots attack real players?** Recommended yes, but capped (max N raid targets,
   never the #1-ranked human, no farm-loop against a player who lost >X in 24 h).
3. **Bots vs bots**: allow free PvP between bots? (Recommended: yes, it self-balances
   the war AI.)
4. **Officer prerequisite**: implement officers now, or defer bot item 7 and ship
   Phases 0–4 first?
5. **Scale**: how many bots to target as the design point (25? 250?) — affects
   queue-worker count and tick design.
6. **Headless arrival sweeper** for idle accounts (fairness): include in Phase 0?
