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
    ],

    'colonisation' => [
        // Maximum system distance from the home planet a bot will colonize.
        'max_system_distance' => 12,
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

        // Maximum number of buildings/research/units a bot queues per visit.
        'build_actions_per_visit' => 3,

        // Never spend more than (1 - reserve_fraction) of stockpiled resources
        // in a single heartbeat.
        'reserve_fraction' => 0.1,

        // Rough OGame pacing rule: one robot factory per N mine levels.
        'robot_factory_ratio' => 10,
    ],

    'research' => [
        // Research order. The first affordable + researchable item wins.
        'priority' => [
            'energy_technology',
            'computer_technology',
            'astrophysics',
            'laser_technology',
            'impulse_drive',
            'combustion_drive',
            'hyperspace_technology',
            'espionage_technology',
            'weapon_technology',
            'shielding_technology',
            'armor_technology',
            'ion_technology',
            'plasma_technology',
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
        ],

        // Which personality trait scales which research group's cap. Defense
        // tech (turret tiers, shields) follows defense_bias; weapons and all
        // drive technologies (which also gate colony ships and fighters)
        // follow aggression.
        'cap_scaling' => [
            'economy' => [
                'group' => ['energy_technology', 'computer_technology', 'astrophysics'],
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
                    'combustion_drive', 'hyperspace_technology', 'espionage_technology',
                ],
                'trait' => 'aggression',
            ],
        ],
    ],

    'military' => [
        // Standing fleet target: 200 * military_share * planet_count units.
        'unit_value_base' => 200,

        // Number of combat units to queue at once when below target.
        'batch_size' => 5,

        // Per-planet defense target: defense_bias * defense_unit_base units.
        'defense_unit_base' => 120,

        // Number of defense units to queue at once when below target.
        'defense_batch_size' => 10,

        // Personalities below this defense bias never build planetary defense.
        'defense_min_bias' => 0.2,
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
