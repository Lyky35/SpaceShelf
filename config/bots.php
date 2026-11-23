<?php

/*
|--------------------------------------------------------------------------
| Autonomous bot players (NPCs)
|--------------------------------------------------------------------------
|
| Bots are full game accounts driven by a scheduled heartbeat that mimics a
| player logging in, queueing build/research/ship actions and dispatching
| fleets, then logging off again.
|
| See docs/BOT-AI-PLAN.md for the full design.
|
*/

return [
    // Master switch. When false, the tick command dispatches nothing.
    'enabled' => env('BOTS_ENABLED', true),

    // Maximum number of bot heartbeats to dispatch per scheduler tick.
    'tick_max_dispatch' => 25,

    // Spread dispatched heartbeats over this many seconds so bots do not all
    // wake up in the exact same second.
    'dispatch_spread_seconds' => 40,

    'heartbeat' => [
        // Delay before the very first heartbeat after bot creation (minutes).
        'first_delay_minutes' => [1, 30],

        // Gap between two heartbeats, i.e. how long the bot "stays logged off"
        // (minutes). Randomized per heartbeat to look organic.
        'next_delay_minutes' => [45, 90],

        // How far `bot:tick` pushes next_action_at forward when it dispatches
        // a heartbeat job. This reserves the appointment at dispatch time so
        // a lagging queue worker cannot make the next tick re-dispatch the
        // same bot; if a job is ever lost, the lease simply expires and the
        // bot runs again. Also the duplicate-window used by the heartbeat job
        // to discard stale queued jobs. Must stay well below
        // next_delay_minutes so legitimate heartbeats are never skipped.
        'dispatch_lease_minutes' => 10,
    ],

    /*
    | Population lifecycle (best-effort, NOT documented upstream): keeps a
    | standing pool of bot accounts with organic churn. Bots older than
    | `min_age_days` roll dice once per week (staggered per bot): they go on
    | a multi-day hiatus, hiatuses running 7+ days can turn permanent, and
    | permanently inactive accounts are deleted like lapsed players. Every
    | deletion is refilled with a fresh bot so the pool stays at
    | `target_size`. Hiatused bots keep no fleets moving and play nothing —
    | they just stop logging in.
    */
    'population' => [
        'enabled' => env('BOTS_POPULATION_ENABLED', true),

        // Pool size counted over all non-deleted bots (hiatused included).
        'target_size' => env('BOTS_TARGET_SIZE', 450),

        // Per-bot dice cadence and the minimum account age before a bot can
        // ever go on hiatus.
        'roll_interval_days' => 7,
        'min_age_days' => 7,

        // Combined per-roll odds: long hiatus (7-30 days) is checked first,
        // short hiatus (2-5 days) otherwise.
        'short' => ['chance' => 0.20, 'days' => [2, 5]],
        'long' => ['chance' => 0.05, 'days' => [7, 30]],

        // Bots inside the top `top_rank` of the public highscore only have
        // `chance` to go inactive at all (hidden bots have no rank and get
        // the normal odds). The chance is split proportionally between the
        // short and long hiatus tiers.
        'protected' => ['top_rank' => 25, 'chance' => 0.01],

        // Hard cap on simultaneous hiatused bots (permanent ones included):
        // while at the cap no NEW hiatuses start, but existing hiatuses end
        // and permanent ones still get deleted on schedule.
        'max_hiatus_bots' => 100,

        // A hiatus that reaches `permanent_after_days` rolls this chance to
        // become permanent; permanent accounts are deleted this many days
        // after their hiatus started.
        'permanent_after_days' => 7,
        'permanent_chance' => 0.25,
        'deletion_after_days' => 35,

        // Fresh bots created per population pass to close the gap to
        // target_size (keeps large backfills gentle).
        'top_up_per_pass' => 25,
    ],

    'colonisation' => [
        // Maximum system distance from the home planet a bot will colonize.
        'max_system_distance' => 12,
    ],

    // Inter-planet logistics (docs/BOT-AI-PLAN.md §5): ships resources from
    // planets whose storage is nearly full to planets whose construction
    // backlog outgrows their own stock and production.
    'transport' => [
        // A planet only ships out resources when storage saturation of that
        // resource is at or above this fraction.
        'source_saturation' => 0.8,

        // The source keeps at least this fraction of its storage capacity of
        // the shipped resource after the mission leaves.
        'source_keep_fraction' => 0.4,

        // Demand horizon: a planet's stock plus this many hours of production
        // must cover its unfinished queue costs, otherwise the resource is
        // considered scarce on that planet.
        'demand_cover_hours' => 6,

        // Never launch a transport mission carrying less than this total.
        'min_amount' => 5000,

        // Queue at most this many large transports on a source planet per
        // visit when demand exists but no transports are in stock.
        'transporter_batch_size' => 20,

        // Standing transport bank: real players keep a small cargo fleet
        // idle on every planet, so growth and raid loot never wait on a
        // reactive batch. Targets are harbor-stock based; the "transport_hoarder"
        // habit doubles them.
        'small_cargo_target_per_planet' => 2,
        'large_cargo_target_home' => 5,
        'large_cargo_target_colony' => 2,
        'bank_batch_size' => 4,

        // Maximum transport missions dispatched per heartbeat.
        'actions_per_visit' => 1,
    ],

    // Debris harvesting: recyclers clean the debris fields on the bot's own
    // planets (battle debris is free money that otherwise decays).
    'recycle' => [
        // Total debris (metal + crystal + deuterium) required before a bot
        // bothers to dispatch recyclers.
        'min_debris_value' => 10000,

        // When debris exists but no recyclers are in stock, queue up to this
        // many recyclers for the next visit.
        'recycler_batch_size' => 3,

        // Maximum recycle missions dispatched per heartbeat.
        'actions_per_visit' => 1,
    ],

    // Crawlers boost mine production (each crawler adds 0.02%, effective only
    // up to the engine's cap of 8 crawlers per total mine level).
    'harvest' => [
        // Usable crawlers per total mine level — must match the bonus cap in
        // the production calculation, do not raise above it.
        'crawlers_per_mine_level' => 8,

        // Crawler batch size queued per visit.
        'batch_size' => 10,
    ],

    //
    // War & espionage AI (phase 3, docs/BOT-AI-PLAN.md §5).
    //
    // Fleet saving, an espionage scouting loop and personality-scaled loot
    // raids. All war "memory" (cooldowns, avoid lists, intel freshness) is
    // derived from the game's own tables — outgoing fleet missions, battle
    // reports and espionage reports — so no bot-specific persistence is
    // needed.
    //
    'war' => [
        // Master switch for the war policy.
        'enabled' => true,

        // Personality profiles with aggression at or above this value scout;
        // only profiles at or above `min_aggression_attack` ever raid.
        'min_aggression_spy' => 0.2,
        'min_aggression_attack' => 0.4,

        // Chance per heartbeat to attempt a raid: personality aggression
        // times this scale, as a percentage. With 0.4 the general (0.9)
        // raids on roughly a third of visits, the discoverer (0.35) rarely
        // and the collector (0.3) never passes the attack gate above.
        'raid_probability_scale' => 0.4,

        // Interplanetary missile strikes. When a scouted target is worth
        // raiding but the battle sim says the fleet would lose, aggressive
        // personalities drop an IGM salvo on its defense and try again
        // later — loot survives the missiles, the guns that blocked the
        // raid do not. UtilityBrain banks the missiles (see the military
        // block); only personalities at or above `min_aggression` fire.
        'missiles' => [
            'min_aggression' => 0.5,

            // Chance per raid visit (when the fleet found nothing it could
            // win) to fire one volley.
            'probability' => 0.35,

            // Maximum IGMs fired per volley, and the minimum bank worth
            // spending — a lone missile or two is not worth the launch.
            'volley_size' => 10,
            'volley_min' => 4,

            // How many raid-favored but undefendable targets to keep track
            // of per visit as missile candidates.
            'max_soft_targets' => 2,
        ],

        'spy' => [
            // Minimum effective espionage level to bother launching probes.
            'min_espionage_level' => 1,

            // Probes per scouting mission. Two probes reveal the ship list;
            // counter-espionage risk grows with probe count and level gap.
            'probes_per_mission' => 2,

            // Keep up to this many probes in stock across all planets; the
            // brain builds them on the home planet when below target.
            'probe_stock_target' => 3,

            // Re-scan coordinates whose intel is older than this (hours).
            'intel_ttl_hours' => 12,

            // Only consider planets within this many systems of any owned
            // planet.
            'max_system_distance' => 3,

            // Maximum spy missions dispatched per heartbeat.
            'actions_per_visit' => 1,
        ],

        'loot' => [
            // Minimum resources + debris (from the latest own spy report)
            // worth launching an attack over.
            'min_value' => 20000,

            // Only raid players whose general score is within this relative
            // band of our own score (0.2..2.5 means targets scoring 20%..250%
            // of ours). Prevents whale-poking and infinite farming of one
            // helpless account.
            'score_band' => [0.2, 2.5],

            // Score ceiling for targets while we have no score yet (fresh
            // bots): only raid accounts that are clearly below mid game.
            'unranked_max_score' => 50000,
        ],

        'protect' => [
            // Humans inside this highscore rank AND at or above
            // `human_min_score` are never scouted or raided — protects the
            // leaderboard. Bots are always fair game. The score floor
            // matters on small worlds where every account technically
            // ranks top-N.
            'human_top_rank' => 10,
            'human_min_score' => 100000,
        ],

        'attack' => [
            // Minimum battle-simulation win probability before committing to
            // an attack (sample-averaged, see sim_samples).
            'success_chance' => 0.7,

            // PhpBattleEngine samples per sizing attempt; combat is RNG so a
            // single sim cannot be trusted. Five samples let the win chance
            // clear `success_chance` at 4/5, three could only ever pass by
            // sweeping all sims (0/33/67/100% granularity).
            'sim_samples' => 5,

            // Fractions of the strike planet's military stock to try, smallest
            // first; the smallest fraction meeting success_chance is sent, so
            // soft loot targets never draw the whole navy. The largest
            // fraction decides whether a target counts as undefendable
            // (missile volley candidates), so keep it last.
            'fleet_fractions' => [0.2, 0.4, 0.8],

            // Never raid the same coordinate again within this many hours
            // (derived from our own outgoing attack missions).
            'cooldown_hours' => 12,

            // After a raid that returned having lost at least this fraction
            // of the dispatched fleet, skip the coordinate for this many
            // hours (derived from our attack missions and their returns).
            'avoid_loss_ratio' => 0.3,
            'avoid_hours' => 48,

            // Minimum military unit count worth dispatching as a strike
            // fleet; planets without one are ignored as strike origins.
            'min_strike_size' => 10,

            // Battle-sim budget per heartbeat: stop evaluating targets
            // after this many have been simulated.
            'max_sim_targets' => 3,

            // Maximum attack missions dispatched per heartbeat.
            'actions_per_visit' => 1,
        ],

        'save_fleet' => [
            // Fly the military fleet away when our own fleet plus defense
            // sample a win chance below this ratio against the incoming
            // attack (plain attacks AND ACS attack fleets): to another
            // owned planet (transport), or to deep space as an expedition
            // when we only have one planet and have researched astrophysics.
            'min_win_chance' => 0.3,

            // Chance that an endangered planet actually attempts the save
            // when everything else lines up. Humans do not always notice
            // (or bother to) — a roll above this and the fleet sits put.
            // Endangered planets still count as unsafe havens either way.
            'attempt_chance' => 0.4,

            // Ignore incoming attack fleets smaller than this many units.
            'min_threat_size' => 10,

            // Do not bother evacuating fleets smaller than this many units.
            'min_fleet_size' => 5,

            // Maximum fleet-save missions dispatched per heartbeat.
            'actions_per_visit' => 1,
        ],
    ],

    'economy' => [
        // Target share of total resource production per resource. The brain
        // mines whichever resource lags behind this ratio the most.
        'targets' => [
            'metal' => 2.4,
            'crystal' => 1.2,
            'deuterium' => 0.4,
        ],

        // When resource storage exceeds this fraction of its capacity, a
        // corresponding storage building gets priority.
        'storage_build_saturation' => 0.85,

        // Below this energy-satisfaction factor (0-100) power plants are built.
        'energy_floor_factor' => 100,

        // From this solar plant level on, energy-starved planets also
        // consider the fusion reactor as the parallel power candidate.
        'fusion_min_solar_level' => 8,

        // Maximum number of buildings/research/units a bot queues per visit.
        'build_actions_per_visit' => 3,

        // Never spend more than (1 - reserve_fraction) of stockpiled resources
        // in a single heartbeat.
        'reserve_fraction' => 0.1,

        // Rough OGame pacing rule: one robot factory per N mine levels.
        'robot_factory_ratio' => 10,
    ],

    // Persistent per-bot quirks, picked at creation and stored with the bot
    // (see PersonalityFactory). They give same-profile bots human-ish build
    // texture instead of pure number jitter. The brain applies them as small,
    // bounded modifiers — see UtilityBrain for what each one does.
    'habits' => [
        'pool' => ['solar_eager', 'hoarder', 'transport_hoarder', 'fodder_first'],

        // Chance a freshly created bot gets a second habit on top of its first.
        'double_chance_percent' => 30,
    ],

    'research' => [
        // Research order. The first affordable + researchable item wins, so
        // the vanilla expansion chain leads: impulse drive 3 plus espionage
        // 4 plus a level-3 lab gate astrophysics, which is what opens colony
        // slots (colonisation) and expedition gates. Items whose
        // prerequisites are unmet or which are too expensive just fall
        // through the list, so a fresh bot still self-paces on computer and
        // energy tech while it saves up for the chain.
        'priority' => [
            'impulse_drive',
            'espionage_technology',
            'astrophysics',
            'computer_technology',
            'energy_technology',
            'laser_technology',
            'combustion_drive',
            'hyperspace_technology',
            'weapon_technology',
            'shielding_technology',
            'armor_technology',
            'ion_technology',
            'plasma_technology',
            'intergalactic_research_network',
        ],

        // Hard caps per research, scaled by personality (see below).
        'caps' => [
            'energy_technology' => 17,
            'computer_technology' => 15,
            'astrophysics' => 20,
            'impulse_drive' => 10,
            'combustion_drive' => 10,
            'hyperspace_technology' => 10,
            'espionage_technology' => 8,
            'weapon_technology' => 15,
            'shielding_technology' => 15,
            'armor_technology' => 15,
            'laser_technology' => 12,
            'ion_technology' => 8,
            'plasma_technology' => 6,
            'intergalactic_research_network' => 6,
        ],

        // Which personality trait scales which research group's cap. Defense
        // tech (turret tiers, shields) follows defense_bias; weapons and the
        // drive technologies (which also gate fighters) follow aggression.
        // Espionage tracks research drive instead: astrophysics requires
        // espionage level 4, so an aggression-scaled cap would sink passive
        // profiles below the astrophysics gate for good.
        'cap_scaling' => [
            'economy' => [
                'group' => [
                    'energy_technology', 'computer_technology', 'astrophysics',
                    'intergalactic_research_network',
                ],
                'trait' => 'researchDrive',
            ],
            'defense' => [
                'group' => [
                    'laser_technology', 'shielding_technology', 'armor_technology',
                    'ion_technology',
                ],
                'trait' => 'defenseBias',
            ],
            'fleet' => [
                'group' => [
                    'weapon_technology', 'plasma_technology', 'impulse_drive',
                    'combustion_drive', 'hyperspace_technology',
                ],
                'trait' => 'aggression',
            ],
            'intel' => [
                'group' => [
                    'espionage_technology',
                ],
                'trait' => 'researchDrive',
            ],
        ],
    ],

    'military' => [
        // Standing fleet target: 200 * military_share * planet_count units.
        'unit_value_base' => 200,

        // Number of combat units to queue at once when below target.
        'batch_size' => 5,

        // Relative weights of which hull a standing-fleet batch draws.
        // Light fighters stay the bulk of a player fleet; the heavier
        // hulls join in the "a bit of everything" doses real players
        // build once the shipyard and research unlock them. Unlocked and
        // affordable status still gates the pick — an unaffordable draw
        // falls through to the next candidate.
        'fleet_mix' => [
            'light_fighter' => 65,
            'heavy_fighter' => 12,
            'cruiser' => 10,
            'battle_ship' => 5,
            'battlecruiser' => 4,
            'bomber' => 3,
        ],

        // Per-planet defense target: defense_bias * defense_unit_base units.
        'defense_unit_base' => 120,

        // Number of defense units to queue at once when below target.
        'defense_batch_size' => 10,

        // Personalities below this defense bias never build planetary defense.
        'defense_min_bias' => 0.2,

        // Defense depth, once the combat tier target holds: shield domes
        // (each dome shields the whole defense stack one extra regenerating
        // round in the original game) and an anti-ballistic missile stock.
        // The engine stores ABMs in the missile silo (10 slots per level)
        // and requires silo level 2 to build them, so the brain raises the
        // silo to abm_silo_level before ordering missiles.
        'shield_dome_target' => 2,
        'abm_target' => 20,
        'abm_silo_level' => 2,

        // Interplanetary missile bank for aggressive personalities: they
        // raise a missile silo to igm_silo_level (the engine only lets them
        // build IGMs from level 4) and keep up to igm_stock_target IGMs in
        // it, in igm_batch_size doses, after the ABM stock took its slots.
        // The war policy spends them on raid targets the fleet sim says
        // would lose — "can't win with the fleet, lob missiles anyway".
        'igm_stock_target' => 10,
        'igm_batch_size' => 5,
        'igm_silo_level' => 4,
    ],

    // Moon play: every owned moon gets this facility line built out, and
    // defensive personalities also garrison half their planetary combat
    // defense there. Moons produce nothing, so transportResources() ships
    // the plan's cost from storage-saturated planets.
    'moon' => [
        'lunar_base_target' => 3,
        'sensor_phalanx_target' => 1,
        'robot_factory_target' => 2,
        'shipyard_target' => 2,
    ],

    // Class ships: the engine builds the Reaper only for Generals and the
    // Pathfinder only for Discoverers (ObjectService::objectCharacterClassMet).
    // The brain keeps a small pack of them on the home planet: reapers raise
    // the war policy's raid ceiling, pathfinders feed the expedition policy's
    // salvage loop and double its Dark Matter finds.
    'classships' => [
        'reaper_stock_target' => 2,
        'pathfinder_stock_target' => 3,
    ],

    // Behavior classes. See docs/BOT-AI-PLAN.md:
    // - collector: bunker worlds, heavy defense, tiny mostly passive fleet
    // - general:   fleet building over everything, zero planetary defense
    // - discoverer: balanced mix of some fleet and some defense
    'personalities' => [
        'collector' => [
            // Aggression here only paces fleet-group research: drives are
            // needed for colony ships (impulse 3) and fighters (combustion 1)
            // even for defensive empires. The tiny fleet is enforced by
            // military_share.
            'aggression' => 0.3,
            'colonisation_bias' => 0.4,
            'military_share' => 0.04,
            'defense_bias' => 0.9,
            'research_drive' => 1.0,
        ],
        'general' => [
            'aggression' => 0.9,
            'colonisation_bias' => 0.8,
            'military_share' => 0.55,
            'defense_bias' => 0.0,
            'research_drive' => 0.85,
        ],
        'discoverer' => [
            'aggression' => 0.35,
            'colonisation_bias' => 1.0,
            'military_share' => 0.3,
            'defense_bias' => 0.4,
            'research_drive' => 1.0,
        ],
    ],

    // Deep space expeditions (docs/BOT-AI-PLAN.md §6). Every profile with
    // astrophysics explores, but Discoverers do it constantly: their class
    // bonus is +2 expedition slots and half the expedition combat risk.
    // This loop is also the bots' only Dark Matter income, which in turn
    // funds the officer hiring policy below.
    'expedition' => [
        'enabled' => true,

        // Chance per brain visit to send an expedition when a slot is
        // free, astrophysics allows it and the fuel is there.
        'probability' => [
            'collector' => 0.4,
            'general' => 0.7,
            'discoverer' => 1.0,
        ],

        // Share of the harbour's strike fleet sent along (15% is inside
        // the plan's 15-25% band; deep space fights can eat the fleet,
        // so keep this well below what a war would need).
        'fleet_share' => 0.15,
        'min_fleet_size' => 4,

        // The launch planet must still hold this much deuterium after
        // paying for the round trip — the war machine's fuel reserve.
        'deuterium_reserve' => 1000,

        // Do not redispatch within this many hours of a fleet coming
        // home; instant return-to-expedition re-rolls look machine-made.
        // The fleet sits in deep space for exactly its astrophysics level
        // in hours, so this only paces the gap between expeditions.
        'redispatch_cooldown_hours' => 4,

        // One Pathfinder rides along when the harbour holds more than
        // this many — it doubles expedition Dark Matter finds (original
        // OGame Pathfinder bonus), while the reserve stays home for the
        // position-16 salvage loop below.
        'pathfinder_reserve' => 1,

        // Debris from expedition battles settles at position 16 and only
        // Pathfinders can harvest it. Bots salvage whatever they happen
        // to have in harbour (expedition ship rewards, later Pathfinder
        // production once the class-ship item lands).
        'debris' => [
            'enabled' => true,
            'min_value' => 10000,
            'actions_per_visit' => 1,
        ],
    ],

    // Officer hiring (docs/BOT-AI-PLAN.md §7). Bots keep one key officer
    // hired via Dark Matter; no DM income yet beyond the initial signup bonus
    // and expedition rewards, so hiring naturally throttles itself.
    'officers' => [
        // Officer each behavior profile keeps hired. Commanding Staff
        // (buy-all) and Commander (pure UI perks) are never bought.
        'profile_mapping' => [
            'collector' => 'geologist',   // +10% mine production on the bunker worlds
            'general' => 'admiral',       // +2 fleet slots, better tactical retreat
            'discoverer' => 'technocrat', // -25% research time, +2 espionage levels
        ],

        // Renew the officer only once its period is down to this many days.
        'renew_within_days' => 2,

        // Never spend Dark Matter that would leave the bot below this reserve.
        'dm_reserve' => 1000,
    ],
];
