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

### 5. Directives (quest system) — engine + Directives I–III live (upstream #843)

**Status: P1 + P2 implemented (2026-11-19); P0 content now covers Directives
I–III (2026-11-20). The quest engine and the full overlay UI are live;
Directive I missions ship screenshot-verified text, Directive II mission
titles and chapter bonus are verified, Directive III is best-effort with its
titles reusing the exact in-game research names; chapters IV/V/VII stay
pending a vanilla dump. Full spec and phase table in
`docs/DIRECTIVES-PLAN.md`.**

Implemented:

- `config/directives.php` catalog: **Directive I – A Sound Economy**
  ("Welcome Cadet!" → "Open the mission overview" → 500 DM, Nova dialogue
  verbatim) and **Directive II – The First Ship** (10 mission titles +
  chapter bonus 1,500/750/250 + 2,000 DM verified). Directive II per-mission
  rewards are best-effort, marked `// best-effort, not documented upstream`
  in the config. Directive VI – Alien Life stays skipped (lifeforms
  unimplemented, #228). New chapters drop into the config as tabs.
  Directive I gained 9 best-effort resource-collection missions (one per
  mine/storage/solar/fusion/satellite build), themed on "A Sound Economy"
  (2026-11-20); **Directive III – The Pursuit of Knowledge** adds 14
  best-effort `research_level` missions (one per remaining tech at level 1;
  titles are the exact in-game research names, skipping energy technology
  and combustion drive which Directive II already covers) (2026-11-20).
- `directive_progress` + `directive_activity_markers` migrations,
  `DirectiveProgressStatus` enum, `DirectiveService` condition engine
  (`activity` / `alliance` / `building_level` / `research_level` /
  `unit_count`), lazy evaluation throttled to 60 s/player in the
  `GlobalGame` middleware, atomic claims (conditional update inside a
  transaction; resources/units to the **active** planet at claim time, Dark
  Matter credited to the account via a `DIRECTIVES` transaction type), and
  "Claim All" chapter bonus rows (`mission_id=''`) that unlock once every
  mission of a chapter is claimed.
- UI: `#ipioverviewlayer` overlay (chapter tabs, accordion mission rows,
  reward sprites, Claim All strip) built on the vendored vanilla CSS,
  opened through the vanilla `a.overlay` framework — the dead `#TODO_`
  href is gone; `resources/js/ingame/directives.js` drives tabs/accordions/
  claims via event delegation. Left panel now shows the claimable-count
  badge (`.ipiHintCollect`) and the current mission.
- Activity hooks: overlay open (`directives_opened`), planet rename
  (`PlanetAbandonController::rename`), buddy request
  (`BuddyService::sendRequest`).
- Tests: `tests/Feature/DirectivesTest.php` (12 tests).

Remaining:

- [ ] P0 follow-up: chapters IV, V, VII (plus Directive I chapter
      bonus, Directive II per-mission sub-steps/rewards, and the
      un-verified "Collect"/claim-feedback strings) — needs a live vanilla
      `ipioverview` dump; the engine is content-agnostic, so it is config
      + translation work only.
- [ ] P3 (optional): newbie `data-ipi-hint` highlights — depends on the
      #978 registration decision.

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

## Test / CI quirks

### 1. The "42 notices" in parallel test runs — diagnosed (2026-11-20), fix pending

`pest --parallel` (and the CI `--shard=X/3 --parallel` jobs) print a yellow
run of `!` marks and `Tests: 42 notices` even though everything passes.
Diagnosed 2026-11-20; deliberately left unfixed, documented here so nobody
re-investigates.

- **Not a PHP notice, not a race, not a code bug.** They are PHPUnit 13
  "PHPUnit Notice" events: *"No expectations were configured for the mock
  object for `…`. Consider refactoring your test code to use a test stub
  instead. The `#[AllowMockObjectsWithoutExpectations]` attribute can be
  used to opt out of this check."* — raised by `MockObjectRegistry` when a
  `createMock()` double is built as a pure placeholder (no `expects()`,
  no stubbed methods, none ever called).
- **All 42 come from two files** (deterministic, sums exactly):
  - `tests/Unit/CharacterClassServiceTest.php` — 34 tests, `DarkMatterService`
    + `SettingsService` mocked in `setUp()`, never configured → 34 notices
    (CI shard 2).
  - `tests/Unit/JumpGateCooldownTest.php` — 8 tests, one placeholder
    `SettingsService` mock (line 169) → 8 notices (CI shard 3).
- **Why only parallel:** serial Pest treats PHPUnit-Notice events as
  informational (a serial `./vendor/bin/pest` run of the whole suite reports
  1355 passed, zero notices). The parallel `WrapperRunner` aggregates
  `testTriggeredPhpunitNoticeEvents()` from worker streams and
  `StateGenerator.php:118/142` maps them onto `TestResult::NOTICE`, which
  produces the `!` marks and the summary line. Reporting-path asymmetry only;
  exit code stays 0 and CI stays green.
- **Fix options when desired** (pick one, ~1 line each file):
  1. Add `#[AllowMockObjectsWithoutExpectations]` to the two test classes
     (PHPUnit's own opt-out — recommended).
  2. Replace `createMock()` with `createStub()` for the placeholder mocks.
  3. Leave as-is (cost: confusing "N notices" line in every CI log).
- **Re-counting / attribution recipe** if the number ever changes: bisect by
  test class — `pest tests/Unit --parallel --filter "Tests.Unit.[A-E]"`, etc.
  (paratest takes a single path, so split with `--filter` regexes, not
  multiple file args), then per file
  `pest tests/Unit/SomeTest.php --parallel --display-notices` to see the
  message text.

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
- [x] **#1629/#1630 — energy values on the resource settings page do not
      match the header when the production factor is below 100%.** With an
      energy deficit, the per-building energy rows, the building-overlay
      energy values and the "Total per hour" energy cell were all reduced
      by the production factor while the header used unscaled stored
      values. **Fixed 2026-11-18** (audit of the "presumed present"
      cluster confirmed the bug: upstream #1629 is open and PR #1630
      unmerged). Because #1661 above already split `$production_factor`
      from `$building_percentage`, the fork only needed two changes rather
      than the full upstream PR port: `calculateMine()` no longer
      multiplies the energy formula by `$production_factor` (the player's
      building percentage still applies to energy, as in vanilla), and
      `ResourcesController::settings()` now overrides the energy cell of
      the totals row with the same stored `PlanetService::energy()` value
      the header uses. The single-factor `actual_energy_use` column and
      the building-overlay callers (`ObjectAjaxTrait`) were already
      arithmetic-correct and become nominal for free. Tests:
      `ResourceProductionTest::testProductionFactorDoesNotAffectEnergy`
      (per-object energy identical with and without the factor) and
      `ResourceSettingsEnergyTest` (totals match the header, solar plant
      row shows nominal production, mine rows show nominal consumption
      with actual floored by the factor once).


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
- [x] **#1645 / #1646 / #1650 / #1651** — realtime silently disabled in
      production, NOT_AUTHORIZED reply re-send loop, missing reply author
      on live messages, no mobile WS reconnect/fallback. Same unmodified
      chat code; present as reported (not individually re-verified).
      **Fixed 2026-11-18:**
      **#1645:** the Reverb/Echo vars in `main.blade.php` now read
      `config('broadcasting.connections.reverb.*')` instead of `env()`, so they
      survive `config:cache` in the production entrypoint; stale
      `.env.example-prod` refreshed (`BROADCAST_CONNECTION`/`QUEUE_CONNECTION`
      renamed, `REVERB_*` block added). Regression test:
      `ChatTest::testReverbChatConfigVarsRenderFromConfig`.
      **#1646:** `chat.js` `sendMessage` no longer re-posts on
      `NOT_AUTHORIZED` (endless loop); it now shows
      `t_ingame.layout.chat_not_authorized` and stops.
      **#1650:** live-message reply reference showed no author because
      `chat.js` read `h.refAuhtor` (typo) — now `h.refAuthor`, matching the
      `ChatMessageSent` broadcast payload. (The same typo lingers in
      `e7c7…js`, but only inside the dead `_ogame_chat_removed` block.)
      **#1651:** new `watchConnection()` hooks Echo's pusher-js connection
      state — on `disconnected`/`unavailable` it starts polling the open chat
      boxes every 5s (also used when Reverb isn't configured at all), and on
      `failed` it nudges the client to reconnect via `retryConnection()`
      (which previously was dead code re-running `initConnection()`, risking
      duplicate listeners). The `isMobile` gate that skipped Echo on mobile
      was removed — mobile uses the same layout and bundle, so it only broke
      realtime.


### Mechanics & correctness (verified present)

- [x] **#1624 / #1625 — teardown blocked by completed research.**
      `ObjectService::canDowngradeBuilding()` (`:967–1035`) blocks a
      downgrade whenever any dependent object — including *completed*
      research — meets the requirement level. Vanilla only blocks active
      dependencies.
      **Fixed 2026-11-18:** ported upstream PR #1625 (still unmerged
      upstream) semantically — removed the requirement loop from the
      tear-down gate entirely (vanilla: requirements only gate
      *starting* a build/research, never tearing one down). Added
      `GameObject::$canBeTornDown` (Terraformer/Lunar Base/Space Dock
      permanent → no button at all); Nanite Factory stays blocked while
      ships/defense are queued; a queued tear-down now counts as a
      construction (`objectInBuildingQueue` level param nullable,
      `isBuildingObject` no longer defaults to current+1), so research
      cannot be started while the lab is being dismantled; the button is
      disabled with a reason tooltip instead of hidden. Tests: 3
      `BuildQueueTest` feature tests + 5 `ObjectServiceTest` unit tests.
- [x] **#1537 — wreck field loaded by coordinates without owner scope.**
      `WreckFieldService::loadForCoordinates()` (`:98`) took whatever
      field row matched the coordinates; needs owner scoping.
      **Fixed 2026-11-18:** scoped by the service's player id and ordered
      like `getAllWreckFieldsForCurrentPlanet` (repairing → active →
      blocked → completed, oldest first), so the facilities UI's display
      check and the subsequent `startRepairs`/`burnWreckField` act on the
      same record; previously two players' wreck fields at one coordinate
      let one player start repairs on (or burn) another's field. The two
      unscoped siblings (`loadOrCreateForCoordinates`,
      `loadActiveOrBlockedForCoordinates`) have zero call sites and are
      left as-is. `WreckFieldTest`: five fixtures now pin
      `owner_player_id` to the current user (they previously relied on the
      factory's random-user owner plus the unscoped load), plus three new
      regression tests: cross-owner load isolation, foreign-only → false,
      and primary-status ordering incl. burned-never-loaded.
- [x] **#1203 — admin accounts are attackable.** No admin/role check in
      the fleet dispatch validation path.
      **Fixed 2026-11-18:** the fork already had `checkAdminProtection()`
      in AttackMission/EspionageMission, but it keyed on the username
      `'Legor'` (the lore planet) instead of the spatie `admin` role, so
      the real admin account was unprotected in any universe without a
      user literally named Legor; MissileMission had no check at all.
      The helper now checks `hasRole('admin')` on the target planet's
      owner (Legor is covered: the InitializeLegorAccount command
      assigns the admin role). Added the same check to
      MissileMission::isMissionPossible(), to GalaxyController's
      missile-attack overlay POST handler and its GET overlay (those
      create the missile's FleetMission row directly, bypassing
      GameMission::start() and thus isMissionPossible), and to the bot
      brain (WarPolicy::scanTargets target filter replaces the
      username skip with the role check, plus a guard in the bot's
      manual missile launch). ACS attack inherits the block (mission
      type 2 is only enabled when attack is possible and resolves to
      AttackMission). New `AdminProtectionTest`: check-target disables
      attack/espionage, attack/missile/espionage dispatches through
      send-fleet are rejected, galaxy missile overlay POST rejected with
      no mission row created, and non-admin targets stay attackable.
- [x] **#1288 — current planet selection shared across sessions.** It is
      user-record state (`PlayerService::setCurrentPlanetId()`), not
      session state, so a new login resumes on the last visited planet
      (hide-activity abuse + cross-device clobbering). Vanilla: per-session,
      home planet on fresh login.
      **Fixed 2026-11-18:** current-planet selection now lives in the HTTP
      session (`current_planet_id` key); `PlayerService::get/setCurrentPlanetId()`
      read/write it (writes guarded on `session()->driver()->isStarted()`,
      reads null-safe so console/queue contexts get the home-planet default).
      The `users.planet_current` column was dropped via a new migration
      (applied on the live DB). `CurrentPlanetSessionTest`: 4 tests pinning
      fresh-session → home planet, per-session isolation (flush+save
      simulation), and cross-process behaviour.
- [x] **#1516 — galaxy colonise ignores the colony-slot cap.**
      `PlayerService::canColonizePosition()` (`:894`) checks astrophysics
      level/position only and never consults `getMaxPlanetAmount()`; the
      mission itself fails, but the galaxy UI offers an impossible action.
      **Fixed 2026-11-18:** galaxy-UI scope only (per the upstream issue):
      new `PlayerService::hasFreeColonySlot()`; empty-slot colonise link and
      its tooltip plus the AJAX `system.canColonize` endpoint now respect
      `getMaxPlanetAmount()` (new tooltip text reuses vanilla arrival-failure
      wording, key `t_galaxy.mission.colonize.astro_limit`). The dispatch
      path stays lenient on purpose — arrival-failure semantics remain pinned
      by `FleetDispatchColoniseTest`. 2 `GalaxyTest` tests.
- [x] **#1188 — spy report never shows the player class.**
      `espionage_report.blade.php:13` (`<!-- TODO: implement player
      class -->`) and `espionage_report_full.blade.php:45` hardcode
      "Class: Unknown".
      **Fixed 2026-11-18:** `EspionageMission` now snapshots the target's
      `character_class` into the report's `player_info['player_class']` at
      creation ('0' = no class). Both templates resolve the class label at
      render time: snapshot → legacy fallback to the current owner's class
      (for pre-existing reports) → Unknown; a player with no class renders
      the existing `t_ingame.layout.class_none` string. No new lang strings.
      3 `MessagesTest` tests (snapshot capture, legacy fallback via the
      factory-resolved service instance, no-class rendering).

### Battle engine / reports / UI (verified present)

- [ ] **#1558 — Rust FFI memory leak.** `RustBattleEngine.php:46` still
      loads `storage/rust-libs/libbattle_engine_ffi.so` through PHP FFI and
      our `lib.rs` is upstream code verbatim (fork diff = comment renames
      only), so the per-call leak is ours too. Upstream's fix is the v2
      engine rewrite (PR #1558, verified still OPEN 2026-11-22).
      **Empirically confirmed 2026-11-22 (review only, deliberately not
      fixed):** `lib.rs:157` returns `CString::into_raw()` and the crate
      exports no free counterpart; `RustBattleEngine.php:71-72` copies the
      result via `FFI::string()` and drops the pointer. Looping 50 battles
      through the real .so in the app container leaked ~32 kB RSS per
      medium battle (~14 kB output + allocator overhead); a control loop
      that freed the same pointer via libc `free()` grew 0 kB, so a
      `free_battle_result()` export is exactly sufficient. Exposure: the
      default engine is rust (settings `battle_engine`), the queue worker
      runs `queue:work` without `--max-memory` and php-fpm workers never
      recycle, so leakage is unbounded — but slow: ~300 MB per 10 000
      battles per process, capped in practice by deploy restarts. Output
      scales with unit-types × fleets × rounds, not raw unit counts.
      Secondary: `update_peak_memory()` polls `memory_stats()` per round
      unconditionally (PHP never reads the metrics); measured negligible
      (14–28 ms per battle, dominated by the fight itself).
      Options if we act: (a) wait for upstream PR #1558; (b) ~6-line
      fork-local fix: `free_battle_result` FFI export + call after
      `FFI::string()` — the .so is built from source in the Dockerfile and
      not git-tracked, so CI rebuilds it; (c) ops-only bounds:
      `queue:work --max-memory=…` + fpm `pm.max_requests`.
- [x] **#1561 — battle report templates carry stale hardcoded `data-raw-*`
      mock metadata** (`battle_report_full.blade.php:74–76`: fixed
      timestamp, fake hashcode).
      **Fixed 2026-11-18:** audited both templates — the entire
      `<div class="rawMessageData">` blocks were unused 2024 client-dump
      mocks (hidden via `.rawMessageData{display:none}`, zero JS
      consumers). Removed the divs from `battle_report.blade.php` and
      `battle_report_full.blade.php`, dropped the now-dead CSS rule, and
      rebuilt assets. Visible report text unchanged; `testBattleReport`
      now asserts no `data-raw-` metadata in the full report or the
      message list preview.
- [x] **#1216 — fleet event tooltip hardcodes "Own fleet"** for every
      row including hostile ones (`eventrow.blade.php`, both row variants).
      **Fixed 2026-11-18:** new `FleetEventRowViewModel::getOwnershipLabel()`
      maps the row's `friendly_status` to the vanilla labels ("Own fleet" /
      "Enemy fleet" / "Friendly fleet") and both `eventrow.blade.php` tooltips
      use it; the synthetic return/wait-end rows inherit the parent's status;
      `determineFriendly()` now classifies incoming Colonisation (type 7) as
      hostile too (types 1/2/6/9/10 already were). Tests:
      `FleetDispatchEventLabelsTest` (sender sees "Own fleet", victim sees
      "Enemy fleet").
- [x] **#1514/#1541 — Recycle mission is not auto-selected for the
      expedition debris field at position 16.** Once #1497 (fork commit
      `6ed418f7`, upstream commit 2026-07-21) blocked expedition missions
      on debris fields, Recycle became the only available mission for
      pos-16 expedition debris, yet Fleet Dispatch II still defaults to no
      mission selected. **Fixed 2026-11-18** (bug verified present: the
      server's `checkTarget` `orders` map enables only mission 8 for a
      pos-16 debris target, and `refreshMissions` in the bundle only
      auto-selects Expedition). Ported upstream PR #1541 verbatim:
      `refreshMissions` now also selects the Recycle mission when it is
      the only available one (`isOnlyMissionAvailable` + no currently
      valid mission), mirroring the Expedition branch and leaving
      multi-mission targets untouched. JS-only change in
      `resources/js/ingame/e7c74974620fa35b197315ebdbb8c2.js`; assets
      rebuilt (`node --check` clean, `npm run build` → hashed
      `ingame-5fd92af6.js` + manifest).
- [x] **#1542/#1539 — Facilities menu shortcut icon does not reach the
      Space Dock.** On planets the little icon next to "Facilities" in the
      top menu was a dead `<div>` (only moons with a Jump Gate got a live
      shortcut). Upstream #1539 is still open and PR #1542 unmerged.
      **Fixed 2026-11-18** (audit confirmed the bug present, while the fork
      already carried the receiving half: `facilities/index.blade.php`
      auto-clicks the Space Dock card on `?openSpaceDock=1`): ported PR
      #1542 verbatim — `@elseif ($currentPlanet->isPlanet())` in
      `main.blade.php` wraps the icon in a link to
      `/facilities?openSpaceDock=1` titled with
      `t_resources.space_dock.title`; Jump Gate shortcut on moons and the
      inert icon on moonless-without-gate remain unchanged. Ported the PR's
      regression test as `FacilitiesMenuShortcutTest` (overview must render
      the Space Dock shortcut href), based on the fork's
      `IsolatedAccountTestCase` instead of upstream's `AccountTestCase`.
- [x] **#1004 — class selection screen clips into the fleet event widget.**
      The class-selection page tags `<body id="characterclassselection">`,
      and its CSS rule `#characterclassselection p { padding: 10px }`
      leaked into the collapsed fleet-event widget's two `p.event_list`
      paragraphs, inflating the widget content to 77px inside its
      deliberately 38px `#messages_collapsed` box; the "Next: …" row
      overflowed behind the page's 250px header banner. Reproduced
      headlessly (Chromium+Playwright against the live app, with a real
      active expedition fleet): widget text spanned y=136–153 and was
      clipped by the banner top at y=143. **Fixed 2026-11-18:** added
      `padding: 0` to `#messages_collapsed .event_list` in
      `469500b3…css` so the shared widget is immune to page-scoped `p`
      rules (this also immunises it against the identical
      `#allianceclassselection p` leak). Upstream has the same rule and
      no fix PR exists. Assets rebuilt (`ingame-CwR1lBWK.css`); live
      headless re-check: content fits (scrollHeight 38) and the "Next"
      row renders fully inside the box at y=116–133.

- [x] **#296 — full battle report loot/debris tooltips show nothing.**
      Hovering a loot or debris amount in the full combat report showed
      no tooltip at all: the 2024-scraped template carries the amounts
      in `data-tooltip-title`, but this UI's legacy Tipped tooltip
      engine (`tooltips.js`) only reads the `title` attribute and skips
      elements without one — the correct amount was already rendered,
      just never displayed. The espionage report already uses `title=`
      for its resource amounts, making the battle report the outlier.
      Upstream #296 is still open (maintainer TODO: audit all tooltip
      occurrences in messages). **Fixed 2026-11-18:** converted the 19
      `data-tooltip-title` attributes holding correct dynamic amounts in
      `battle_report_full.blade.php` to `title=` (the loot
      metal/crystal/deuterium rows plus the debris total and
      metal/crystal/deuterium breakdown rows in the total,
      attacker-collected, defender-collected and remaining sections);
      visible content unchanged. Chrome tooltips (buttons, planet icon,
      retreat info), the commented-out food/population rows and the
      stale hardcoded API-key hashcode tooltip deliberately left
      untouched. `testBattleReport` now asserts `title="1,000"` and
      `title="500"` render and that no `res_value` span carries
      `data-tooltip-title` anymore; live Chromium+Playwright check
      against a real report message showed a `tpd-skin-cloud` tooltip
      with "70,000" when hovering "70K".

- [x] **#1510/#668 — incoming fleet details not gated by espionage
      level.** The fleet event list and the fleet movement page revealed
      a foreign attacker's full fleet composition and cargo to the
      target at any espionage technology, and the sensor phalanx overlay
      shipped a per-ship breakdown (`ships` map + "Fleet details"
      tooltip) for every fleet in range. Vanilla gates foreign fleet
      intel on Espionage Technology: 0-1 shows only the attack
      notification, 2-3 the total ship count, 4-7 the ship types without
      counts and 8+ the exact per-type counts; cargo is never revealed
      for foreign fleets while own and alliance ("gold") fleets stay
      fully visible. Upstream #668 and its duplicate #1510 are still
      open; #1510 is a Cursor-authored report whose author admits vanilla
      errors in the linked stalled PR, so it was treated as reference
      only. **Fixed 2026-11-18:** added `FleetIntelLevel` enum,
      `FleetIntel` value object and `IncomingFleetIntelService` (tier
      from `getEffectiveEspionageLevel()`; the own/alliance check reads
      the fleet owner's alliance straight from the database to dodge
      stale `PlayerServiceFactory` cache state), wired into
      `FleetEventsController` (main rows, hold/return synthetic rows,
      foreign ACS member rows, union summary — a union is gated by its
      initiator and foreign unions get the merged ship-type table the
      allowed tier reveals) and `FleetController::movement`; blades
      (`eventrow`, `eventrow-union`, `movement-row`) render through new
      `sees*()` view-model helpers; `PhalanxService` now sends totals
      only and the phalanx template dropped its composition tooltip
      (vanilla phalanx never shows fleet composition). New
      `IncomingFleetEspionageIntelTest` (8 tests) covers all four tiers,
      own-fleet visibility, the alliance rule and both pages;
      `PhalanxTest::testSuccessfulScanWithIncomingFleet` now asserts the
      scan shows no composition. Live check with three throwaway users
      at 1:26:4: the defender's event list showed nothing (spy 0), the
      union total 17 only (spy 2), merged ship types without counts
      (spy 4) and exact per-type counts (spy 8) — never "Shipment" —
      and a real ACS union (two allied attackers, one defender)
      exercised the union summary/member gates including per-member
      counts; the movement page matched; the union initiator still saw
      the full member-breakdown tooltip. Audit users removed afterwards.

### Presumed present (identical upstream code; not individually verified)

None remaining — every entry has been audited; the last one (#1538) moved
to the audit exclusions below on 2026-11-19.

### Audit exclusions (verified NOT present — don't re-investigate)

- **#307** (large shipyard orders build fewer units than requested) —
  upstream still open, but root cause (planet row saved with data read
  before the update lock was acquired; lost-update race wiped granted
  units) was fixed upstream by PR #321, which this fork carries:
  `PlanetService::update()` takes `lockForUpdate()` on the planet row and
  `reloadPlanet()`s before processing, all inside one transaction. The
  award math itself cannot lose units (full remainder is granted once
  `now >= time_end`, and float→int truncation of `time_progress` only
  ever awards early). Upstream's #321 regression tests are present and
  green on HEAD: `UnitQueueTest::testUnitQueueLargeAmountLowShipyardLevel`
  (1000 units) and `testUnitQueueLargeAmountHighShipyardLevel` (10000
  units). Upstream keeps #307 open only for the full architectural race
  rewrite (#322); out of scope.
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
- **#1168** (galaxy mini-fleet dispatch green text lists ship names instead
  of the vanilla `Fleet dispatch [g:s:p] (n) Ok`) — the upstream issue is
  still open but was already fixed upstream, and the fork carries the fix:
  upstream commits `00c9fa24` (2026-03-08) and `39e15a4c` (2026-03-18)
  replaced the old ship-name messages ("Send recycler to:", "Send pathfinder
  to:", "Send espionage probe to:") with
  `__('t_ingame.fleet.fleet_dispatch')` = "Fleet dispatch" in all three
  mini-dispatch cases. The JS bundle is byte-identical to upstream HEAD: the
  only live call sites are `sendShips` (debris recycler icons, expedition
  pos-16 debris Pathfinder send, spy-probe icons; `sendShipsWithPopup` is
  dead code with zero callers) → `displayMiniFleetMessage` appends
  `' [g:s:p]'`, and `addToTable` appends `' (shipsSent) Ok'`
  (`LocalizationStrings.ok` = "Ok") to the green `#fleetstatusrow` at the
  bottom of the galaxy — rendering exactly the vanilla string. No fork
  change needed.
- **#1513** (dev shortcut for expedition debris field reports success but
  creates nothing) — still open upstream, but the symptom does not
  reproduce here. `DeveloperShortcutsController::createDebris()` (blame:
  unchanged since 2024-11, predating the 2026-07-21 report; fork code
  byte-identical to upstream HEAD) runs
  `loadOrCreateForCoordinates` → `appendResources` → `save()`, and an
  empirical tinker run of that exact flow persisted a full-resources
  debris_fields row at 1:1:16. The display half is present since 2025-12:
  `GalaxyController::createExpeditionDebrisRow()` renders the pos-16 row
  whenever `getResources()->any()`, gated behind `hasExpeditionDebrisFieldsVisible`
  (Discoverer class only, by design — a non-Discoverer tester creating
  pos-16 debris would see success but no row, which likely explains the
  original misdiagnosis). Upstream's maintainer himself routes further
  work to PR #1541 (the Recycle auto-selection, shipped here on
  2026-11-18). No fork change needed.
- **#305** (admin server settings form has no validation) — verified
  present, but *present by design and upstream scope*: upstream keeps
  it as an open **enhancement** whose UX the maintainer has not settled
  (the issue body contrasts basic vs per-field validation; the comment
  thread adds a third "dynamic validation" option the maintainer
  prefers, plus a generic wrapper for admin and user forms, with open
  questions about Laravel's built-in validation). The fork's
  `Admin\ServerSettingsController::update()` is byte-identical to
  upstream HEAD and writes all ~50 `request()` values unvalidated.
  Impact is an admin-only footgun: the page sits behind the `admin`
  middleware and every `SettingsService` getter casts (`(int)`,
  `(float)`), so garbage input degrades predictably ("abc" → 0) rather
  than crashing or reaching players — nothing a regular bug fix would
  address. A fork-side fix would mean committing to one undecided UX
  for ~50 fields and colliding with whatever upstream ships. Skip;
  revisit only if upstream implements a design worth porting.
- **#1538** (flaky `testFleetDispatchValidSpeeds` — random foreign-planet
  fixture in `tests/Traits/CreatesForeignFixtures.php`) — the upstream
  flake mechanism does not exist in the fork. The Cursor-authored upstream
  report claims `getNearbyForeignPlanet()` picks a target with
  `inRandomOrder()` and can land on a vacation-mode player whose account
  blocks attack dispatch, failing the `speed 1` assertion. The fork
  replaced those probes: `CreatesForeignFixtures::createForeignPlanet()`
  always creates a fresh `User::factory()` player, and the factory sets
  neither `vacation_mode` nor any ban flag; separately, the fork's fleet
  dispatch path has no vacation-mode gate at all (`vacation_mode` appears
  only in views and the dev `PreviewSeedUsers` command). The fixture's
  remaining randomness is bounded: `TestCase::getSafeEmptyCoordinate()`
  (`:31`) stays in the viewer's own galaxy, picks systems anchor±10 at
  positions 13–15 with a DB collision check, so the worst-case fuel for
  one light fighter at 10% speed is ~20k deuterium against the 1M granted
  by `FleetDispatchAttackTest::basicSetup()` (`:56`). 40 consecutive
  green runs of the test on HEAD (1.5s each, 40 assertions) show no
  flakiness; no code change needed.

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
