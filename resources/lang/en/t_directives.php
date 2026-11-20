<?php

/*
|--------------------------------------------------------------------------
| Directives (quest system) translations
|--------------------------------------------------------------------------
|
| Mission titles, chapter titles, the "Welcome Cadet!" narrator dialogue and the
| sub-step/reward headers below are transcribed verbatim from vanilla OGame
| screenshots (upstream issue #843). Do not "polish" them: they must keep the
| original OGame wording.
|
| Entries marked "NOT verified" are UI strings or content that the available
| vanilla sources do not cover; they were worded to fit the verified layout.
| They must be replaced with the exact original text once more vanilla
| material (e.g. a live ipioverview dump) is available.
*/

return [
    'chapters' => [
        'directive_i' => [
            'title' => 'Directive I – A Sound Economy',
        ],
        'directive_ii' => [
            'title' => 'Directive II – The First Ship',
        ],
        // Best-effort chapter title; verify against original OGame Directive III
        // text when it can be sourced.
        'directive_iii' => [
            'title' => 'Directive III – The Pursuit of Knowledge',
        ],
    ],

    'missions' => [
        'i_welcome_cadet' => [
            'title' => 'Welcome Cadet!',
            'description' => "Welcome Cadet! I'm First Officer Nova, and I'm here to teach you everything you need to know to become a competent intergalactic emperor. I'll guide you through the directives which will get you ahead of the game. Let's get to it!",
            'actions' => [
                'open_overview' => 'Open the mission overview',
            ],
        ],
        // Best-effort Directive I mission titles; verify against original OGame
        // Directive I quest text when it can be sourced. Titles use the exact
        // in-game building/unit names.
        'i_metal_mine' => [
            'title' => 'Metal Mine',
        ],
        'i_crystal_mine' => [
            'title' => 'Crystal Mine',
        ],
        'i_deuterium_synthesizer' => [
            'title' => 'Deuterium Synthesizer',
        ],
        'i_solar_plant' => [
            'title' => 'Solar Plant',
        ],
        'i_metal_storage' => [
            'title' => 'Metal Storage',
        ],
        'i_crystal_storage' => [
            'title' => 'Crystal Storage',
        ],
        'i_deuterium_tank' => [
            'title' => 'Deuterium Tank',
        ],
        'i_solar_satellites' => [
            'title' => 'Solar Satellites',
        ],
        'i_fusion_reactor' => [
            'title' => 'Fusion Reactor',
        ],
        'ii_civilian_ships' => [
            'title' => 'Civilian ships',
        ],
        'ii_research_lab' => [
            'title' => 'Research Lab',
        ],
        'ii_energy_technology' => [
            'title' => 'Energy Technology',
        ],
        'ii_combustion_drives' => [
            'title' => 'Combustion Drives',
        ],
        'ii_robotics_factory' => [
            'title' => 'Robotics Factory',
        ],
        'ii_shipyard' => [
            'title' => 'Shipyard',
        ],
        'ii_small_cargo_ships' => [
            'title' => 'Small Cargo ships',
        ],
        'ii_alliance' => [
            'title' => 'Become part of an alliance',
        ],
        'ii_rename_planet' => [
            'title' => 'Rename a planet',
        ],
        'ii_buddy_request' => [
            'title' => 'Send a buddy request',
        ],
        // Best-effort Directive III mission titles; the chapter itself is not
        // documented upstream. Titles reuse the exact in-game research names.
        'iii_computer_technology' => [
            'title' => 'Computer Technology',
        ],
        'iii_espionage_technology' => [
            'title' => 'Espionage Technology',
        ],
        'iii_weapon_technology' => [
            'title' => 'Weapon Technology',
        ],
        'iii_shielding_technology' => [
            'title' => 'Shield Technology',
        ],
        'iii_armor_technology' => [
            'title' => 'Armour Technology',
        ],
        'iii_laser_technology' => [
            'title' => 'Laser Technology',
        ],
        'iii_ion_technology' => [
            'title' => 'Ion Technology',
        ],
        'iii_hyperspace_technology' => [
            'title' => 'Hyperspace Technology',
        ],
        'iii_impulse_drive' => [
            'title' => 'Impulse Drive',
        ],
        'iii_hyperspace_drive' => [
            'title' => 'Hyperspace Drive',
        ],
        'iii_astrophysics' => [
            'title' => 'Astrophysics',
        ],
        'iii_plasma_technology' => [
            'title' => 'Plasma Technology',
        ],
        'iii_intergalactic_research_network' => [
            'title' => 'Intergalactic Research Network',
        ],
        'iii_graviton_technology' => [
            'title' => 'Graviton Technology',
        ],
    ],

    'ui' => [
        'complete_the_following_missions' => 'Complete the following missions:',
        'mission_reward_header' => 'For completing the mission you receive:',
        'chapter_reward_header' => 'When you complete this directive, you will receive:',
        'claim_all' => 'Claim All',
        // NOT verified: the state column wording for a claimable mission and the
        // claim button label. "Collected" (claimed state) is verified.
        'collect' => 'Collect',
        'collected' => 'Collected',
        // NOT verified: claim-result feedback strings shown via fadeBox.
        'not_found' => 'Directive not found',
        'already_collected' => 'Reward already collected',
        'not_completed' => 'Mission not completed yet',
    ],
];
