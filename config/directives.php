<?php

/*
|--------------------------------------------------------------------------
| Directives (quest system) catalog
|--------------------------------------------------------------------------
|
| Recreation of the vanilla "Directives" quest overlay (see
| docs/DIRECTIVES-PLAN.md §1 for the researched vanilla behaviour).
|
| Content extraction status (plan P0):
| - Verified verbatim from vanilla screenshots (upstream issue #843): the
|   chapter titles for Directive I/II, all ten Directive II mission titles,
|   the Directive I mission "Welcome Cadet!" with its narrator dialogue, its
|   sub-step "Open the mission overview" and its 500 Dark Matter reward,
|   and the Directive II chapter bonus (1,500 metal / 750 crystal /
|   250 deuterium / 2,000 Dark Matter).
| - NOT documented upstream and therefore intentionally absent: chapter V and
|   VII content (Directive VI is the Lifeforms chapter, skipped entirely as
|   lifeforms are unimplemented), the Directive I chapter bonus and
|   per-mission sub-step texts for Directive II. Chapters appear as tabs as
|   soon as they are defined here.
| - Best-effort, NOT verified: the per-mission rewards of Directive II, the
|   Directive I mission set beyond the verified "Welcome Cadet!" entry (the
|   resource-collection missions are themed on "A Sound Economy" and titled
|   with in-game building/unit names), the entire Directive III science
|   chapter (its title, its one-per-technology research missions and its
|   rewards; mission titles reuse the exact in-game research names), and the
|   Directive IV defense chapter (its title and one building_level mission
|   per defense structure, completed at structure level 1; titles reuse the
|   exact in-game defense structure names). They
|   follow the scale of the verified chapter bonus and of the other documented
|   rewards; replace them once the vanilla quest data can be dumped from a
|   live universe. Each such reward is marked below. Vanilla's Directive IV
|   is the confirmed "Deep Space" chapter (docs/DIRECTIVES-PLAN.md §1); this
|   fork's Directive IV is a deliberately defense-themed stand-in, and the one
|   sourced vanilla Directive IV mission fragment, "Dispatch an expeditionary
|   fleet" (plan §1), is intentionally NOT part of it - it lands once the
|   vanilla quest data can be dumped.
|
| Mission condition types (implemented in DirectiveService):
| - activity: one-off marker (see DirectiveService::ACTIVITY_*)
| - alliance: player is member of an alliance
| - building_level: {object, target, scope: any|active} - any leveled planet
|   object, including defense structures
| - research_level: {object, target}
| - unit_count: {objects: [machine_name, ...], target, scope: any}
|
| Rewards keys: metal, crystal, deuterium, dark_matter and units
| (machine_name => amount). Resources and units are granted to the planet
| that is active at claim time, matching vanilla behaviour.
*/

return [
    'chapters' => [
        'directive_i' => [
            'tab' => 'I',
            'title' => 't_directives.chapters.directive_i.title',
            // Directive I chapter bonus is not documented upstream; omitted until sourced.
            'chapter_rewards' => [],
            'missions' => [
                'i_welcome_cadet' => [
                    'title' => 't_directives.missions.i_welcome_cadet.title',
                    'description' => 't_directives.missions.i_welcome_cadet.description',
                    'actions' => ['t_directives.missions.i_welcome_cadet.actions.open_overview'],
                    'condition' => [
                        'type' => 'activity',
                        'key' => 'directives_opened',
                    ],
                    'rewards' => [
                        'dark_matter' => 500,
                    ],
                ],
                // The Directive I missions below are best-effort (NOT documented
                // upstream): they follow the chapter's "A Sound Economy" theme of
                // resource collection — mines, storage, power plants and solar
                // satellites. Titles use the exact in-game building/unit names and
                // rewards follow the scale of the Directive II best-effort rewards.
                // Replace them once the vanilla quest data can be dumped from a
                // live universe.
                'i_metal_mine' => [
                    'title' => 't_directives.missions.i_metal_mine.title',
                    'condition' => [
                        'type' => 'building_level',
                        'object' => 'metal_mine',
                        'target' => 1,
                        'scope' => 'any',
                    ],
                    'rewards' => [
                        'metal' => 400, // best-effort, not documented upstream
                    ],
                ],
                'i_crystal_mine' => [
                    'title' => 't_directives.missions.i_crystal_mine.title',
                    'condition' => [
                        'type' => 'building_level',
                        'object' => 'crystal_mine',
                        'target' => 1,
                        'scope' => 'any',
                    ],
                    'rewards' => [
                        'crystal' => 400, // best-effort, not documented upstream
                    ],
                ],
                'i_deuterium_synthesizer' => [
                    'title' => 't_directives.missions.i_deuterium_synthesizer.title',
                    'condition' => [
                        'type' => 'building_level',
                        'object' => 'deuterium_synthesizer',
                        'target' => 1,
                        'scope' => 'any',
                    ],
                    'rewards' => [
                        'deuterium' => 200, // best-effort, not documented upstream
                    ],
                ],
                'i_solar_plant' => [
                    'title' => 't_directives.missions.i_solar_plant.title',
                    'condition' => [
                        'type' => 'building_level',
                        'object' => 'solar_plant',
                        'target' => 1,
                        'scope' => 'any',
                    ],
                    'rewards' => [
                        'metal' => 500, // best-effort, not documented upstream
                        'crystal' => 500, // best-effort, not documented upstream
                    ],
                ],
                'i_metal_storage' => [
                    'title' => 't_directives.missions.i_metal_storage.title',
                    'condition' => [
                        'type' => 'building_level',
                        'object' => 'metal_store',
                        'target' => 1,
                        'scope' => 'any',
                    ],
                    'rewards' => [
                        'metal' => 500, // best-effort, not documented upstream
                    ],
                ],
                'i_crystal_storage' => [
                    'title' => 't_directives.missions.i_crystal_storage.title',
                    'condition' => [
                        'type' => 'building_level',
                        'object' => 'crystal_store',
                        'target' => 1,
                        'scope' => 'any',
                    ],
                    'rewards' => [
                        'crystal' => 500, // best-effort, not documented upstream
                    ],
                ],
                'i_deuterium_tank' => [
                    'title' => 't_directives.missions.i_deuterium_tank.title',
                    'condition' => [
                        'type' => 'building_level',
                        'object' => 'deuterium_store',
                        'target' => 1,
                        'scope' => 'any',
                    ],
                    'rewards' => [
                        'deuterium' => 250, // best-effort, not documented upstream
                    ],
                ],
                'i_solar_satellites' => [
                    'title' => 't_directives.missions.i_solar_satellites.title',
                    'condition' => [
                        'type' => 'unit_count',
                        'objects' => ['solar_satellite'],
                        'target' => 3,
                        'scope' => 'any',
                    ],
                    'rewards' => [
                        'metal' => 800, // best-effort, not documented upstream
                    ],
                ],
                'i_fusion_reactor' => [
                    'title' => 't_directives.missions.i_fusion_reactor.title',
                    'condition' => [
                        'type' => 'building_level',
                        'object' => 'fusion_plant',
                        'target' => 1,
                        'scope' => 'any',
                    ],
                    'rewards' => [
                        'metal' => 1000, // best-effort, not documented upstream
                        'crystal' => 500, // best-effort, not documented upstream
                    ],
                ],
            ],
        ],
        'directive_ii' => [
            'tab' => 'II',
            'title' => 't_directives.chapters.directive_ii.title',
            'chapter_rewards' => [
                'metal' => 1500,
                'crystal' => 750,
                'deuterium' => 250,
                'dark_matter' => 2000,
            ],
            'missions' => [
                // Mission titles are verified; the per-mission rewards below are
                // best-effort (NOT documented upstream).
                'ii_civilian_ships' => [
                    'title' => 't_directives.missions.ii_civilian_ships.title',
                    'condition' => [
                        'type' => 'unit_count',
                        'objects' => ['colony_ship', 'small_cargo', 'large_cargo'],
                        'target' => 1,
                        'scope' => 'any',
                    ],
                    'rewards' => [
                        'metal' => 1000, // best-effort, not documented upstream
                    ],
                ],
                'ii_research_lab' => [
                    'title' => 't_directives.missions.ii_research_lab.title',
                    'condition' => [
                        'type' => 'building_level',
                        'object' => 'research_lab',
                        'target' => 1,
                        'scope' => 'any',
                    ],
                    'rewards' => [
                        'metal' => 500, // best-effort, not documented upstream
                        'crystal' => 500, // best-effort, not documented upstream
                    ],
                ],
                'ii_energy_technology' => [
                    'title' => 't_directives.missions.ii_energy_technology.title',
                    'condition' => [
                        'type' => 'research_level',
                        'object' => 'energy_technology',
                        'target' => 1,
                    ],
                    'rewards' => [
                        'metal' => 750, // best-effort, not documented upstream
                    ],
                ],
                'ii_combustion_drives' => [
                    'title' => 't_directives.missions.ii_combustion_drives.title',
                    'condition' => [
                        'type' => 'research_level',
                        'object' => 'combustion_drive',
                        'target' => 1,
                    ],
                    'rewards' => [
                        'metal' => 500, // best-effort, not documented upstream
                        'crystal' => 250, // best-effort, not documented upstream
                    ],
                ],
                'ii_robotics_factory' => [
                    'title' => 't_directives.missions.ii_robotics_factory.title',
                    'condition' => [
                        'type' => 'building_level',
                        'object' => 'robot_factory',
                        'target' => 1,
                        'scope' => 'any',
                    ],
                    'rewards' => [
                        'metal' => 1000, // best-effort, not documented upstream
                    ],
                ],
                'ii_shipyard' => [
                    'title' => 't_directives.missions.ii_shipyard.title',
                    'condition' => [
                        'type' => 'building_level',
                        'object' => 'shipyard',
                        'target' => 1,
                        'scope' => 'any',
                    ],
                    'rewards' => [
                        'metal' => 1000, // best-effort, not documented upstream
                        'crystal' => 500, // best-effort, not documented upstream
                    ],
                ],
                'ii_small_cargo_ships' => [
                    'title' => 't_directives.missions.ii_small_cargo_ships.title',
                    'condition' => [
                        'type' => 'unit_count',
                        'objects' => ['small_cargo'],
                        'target' => 1,
                        'scope' => 'any',
                    ],
                    'rewards' => [
                        'metal' => 1500, // best-effort, not documented upstream
                        'crystal' => 500, // best-effort, not documented upstream
                    ],
                ],
                'ii_alliance' => [
                    'title' => 't_directives.missions.ii_alliance.title',
                    'condition' => [
                        'type' => 'alliance',
                    ],
                    'rewards' => [
                        'dark_matter' => 100, // best-effort, not documented upstream
                    ],
                ],
                'ii_rename_planet' => [
                    'title' => 't_directives.missions.ii_rename_planet.title',
                    'condition' => [
                        'type' => 'activity',
                        'key' => 'planet_renamed',
                    ],
                    'rewards' => [
                        'dark_matter' => 100, // best-effort, not documented upstream
                    ],
                ],
                'ii_buddy_request' => [
                    'title' => 't_directives.missions.ii_buddy_request.title',
                    'condition' => [
                        'type' => 'activity',
                        'key' => 'buddy_request_sent',
                    ],
                    'rewards' => [
                        'dark_matter' => 200, // best-effort, not documented upstream
                    ],
                ],
            ],
        ],
        'directive_iii' => [
            'tab' => 'III',
            'title' => 't_directives.chapters.directive_iii.title',
            'chapter_rewards' => [
                'metal' => 3000, // best-effort, not documented upstream
                'crystal' => 1500, // best-effort, not documented upstream
                'deuterium' => 750, // best-effort, not documented upstream
                'dark_matter' => 3000, // best-effort, not documented upstream
            ],
            'missions' => [
                // The Directive III chapter is entirely best-effort (NOT
                // documented upstream): vanilla's Directive III quest text has
                // never been sourced. The chapter is themed on science and
                // research — one research_level mission per technology,
                // completed by researching the technology to level 1. Titles
                // reuse the exact in-game research names so the rows match the
                // research screen's vocabulary. Energy Technology and
                // Combustion Drive are skipped here because Directive II
                // already has research missions for them. Rewards follow the
                // scale of the Directive II best-effort rewards. Replace the
                // whole chapter once the vanilla quest data can be dumped from
                // a live universe.
                'iii_computer_technology' => [
                    'title' => 't_directives.missions.iii_computer_technology.title',
                    'condition' => [
                        'type' => 'research_level',
                        'object' => 'computer_technology',
                        'target' => 1,
                    ],
                    'rewards' => [
                        'metal' => 600, // best-effort, not documented upstream
                        'crystal' => 600, // best-effort, not documented upstream
                    ],
                ],
                'iii_espionage_technology' => [
                    'title' => 't_directives.missions.iii_espionage_technology.title',
                    'condition' => [
                        'type' => 'research_level',
                        'object' => 'espionage_technology',
                        'target' => 1,
                    ],
                    'rewards' => [
                        'metal' => 500, // best-effort, not documented upstream
                        'crystal' => 400, // best-effort, not documented upstream
                        'deuterium' => 200, // best-effort, not documented upstream
                    ],
                ],
                'iii_weapon_technology' => [
                    'title' => 't_directives.missions.iii_weapon_technology.title',
                    'condition' => [
                        'type' => 'research_level',
                        'object' => 'weapon_technology',
                        'target' => 1,
                    ],
                    'rewards' => [
                        'metal' => 500, // best-effort, not documented upstream
                        'crystal' => 500, // best-effort, not documented upstream
                    ],
                ],
                'iii_shielding_technology' => [
                    'title' => 't_directives.missions.iii_shielding_technology.title',
                    'condition' => [
                        'type' => 'research_level',
                        'object' => 'shielding_technology',
                        'target' => 1,
                    ],
                    'rewards' => [
                        'metal' => 500, // best-effort, not documented upstream
                        'crystal' => 500, // best-effort, not documented upstream
                    ],
                ],
                'iii_armor_technology' => [
                    'title' => 't_directives.missions.iii_armor_technology.title',
                    'condition' => [
                        'type' => 'research_level',
                        'object' => 'armor_technology',
                        'target' => 1,
                    ],
                    'rewards' => [
                        'metal' => 500, // best-effort, not documented upstream
                        'crystal' => 500, // best-effort, not documented upstream
                    ],
                ],
                'iii_laser_technology' => [
                    'title' => 't_directives.missions.iii_laser_technology.title',
                    'condition' => [
                        'type' => 'research_level',
                        'object' => 'laser_technology',
                        'target' => 1,
                    ],
                    'rewards' => [
                        'metal' => 800, // best-effort, not documented upstream
                        'crystal' => 800, // best-effort, not documented upstream
                    ],
                ],
                'iii_ion_technology' => [
                    'title' => 't_directives.missions.iii_ion_technology.title',
                    'condition' => [
                        'type' => 'research_level',
                        'object' => 'ion_technology',
                        'target' => 1,
                    ],
                    'rewards' => [
                        'metal' => 1000, // best-effort, not documented upstream
                        'crystal' => 600, // best-effort, not documented upstream
                        'deuterium' => 200, // best-effort, not documented upstream
                    ],
                ],
                'iii_hyperspace_technology' => [
                    'title' => 't_directives.missions.iii_hyperspace_technology.title',
                    'condition' => [
                        'type' => 'research_level',
                        'object' => 'hyperspace_technology',
                        'target' => 1,
                    ],
                    'rewards' => [
                        'metal' => 1000, // best-effort, not documented upstream
                        'crystal' => 1000, // best-effort, not documented upstream
                    ],
                ],
                'iii_impulse_drive' => [
                    'title' => 't_directives.missions.iii_impulse_drive.title',
                    'condition' => [
                        'type' => 'research_level',
                        'object' => 'impulse_drive',
                        'target' => 1,
                    ],
                    'rewards' => [
                        'metal' => 1200, // best-effort, not documented upstream
                        'crystal' => 900, // best-effort, not documented upstream
                        'deuterium' => 300, // best-effort, not documented upstream
                    ],
                ],
                'iii_hyperspace_drive' => [
                    'title' => 't_directives.missions.iii_hyperspace_drive.title',
                    'condition' => [
                        'type' => 'research_level',
                        'object' => 'hyperspace_drive',
                        'target' => 1,
                    ],
                    'rewards' => [
                        'metal' => 1500, // best-effort, not documented upstream
                        'crystal' => 1200, // best-effort, not documented upstream
                        'deuterium' => 400, // best-effort, not documented upstream
                    ],
                ],
                'iii_astrophysics' => [
                    'title' => 't_directives.missions.iii_astrophysics.title',
                    'condition' => [
                        'type' => 'research_level',
                        'object' => 'astrophysics',
                        'target' => 1,
                    ],
                    'rewards' => [
                        'metal' => 1000, // best-effort, not documented upstream
                        'crystal' => 400, // best-effort, not documented upstream
                        'deuterium' => 200, // best-effort, not documented upstream
                    ],
                ],
                'iii_plasma_technology' => [
                    'title' => 't_directives.missions.iii_plasma_technology.title',
                    'condition' => [
                        'type' => 'research_level',
                        'object' => 'plasma_technology',
                        'target' => 1,
                    ],
                    'rewards' => [
                        'metal' => 1500, // best-effort, not documented upstream
                        'crystal' => 1500, // best-effort, not documented upstream
                        'deuterium' => 500, // best-effort, not documented upstream
                    ],
                ],
                'iii_intergalactic_research_network' => [
                    'title' => 't_directives.missions.iii_intergalactic_research_network.title',
                    'condition' => [
                        'type' => 'research_level',
                        'object' => 'intergalactic_research_network',
                        'target' => 1,
                    ],
                    'rewards' => [
                        'metal' => 1200, // best-effort, not documented upstream
                        'crystal' => 1200, // best-effort, not documented upstream
                        'deuterium' => 600, // best-effort, not documented upstream
                        'dark_matter' => 100, // best-effort, not documented upstream
                    ],
                ],
                'iii_graviton_technology' => [
                    'title' => 't_directives.missions.iii_graviton_technology.title',
                    'condition' => [
                        'type' => 'research_level',
                        'object' => 'graviton_technology',
                        'target' => 1,
                    ],
                    'rewards' => [
                        'dark_matter' => 2000, // best-effort, not documented upstream
                    ],
                ],
            ],
        ],
        'directive_iv' => [
            'tab' => 'IV',
            'title' => 't_directives.chapters.directive_iv.title',
            'chapter_rewards' => [
                'metal' => 4500, // best-effort, not documented upstream
                'crystal' => 2250, // best-effort, not documented upstream
                'deuterium' => 1125, // best-effort, not documented upstream
                'dark_matter' => 4000, // best-effort, not documented upstream
            ],
            'missions' => [
                // The Directive IV chapter is entirely best-effort (NOT
                // documented upstream as such): vanilla's Directive IV is the
                // "Deep Space" chapter (confirmed name, docs/DIRECTIVES-PLAN.md
                // §1) and its mission texts were never sourced - the only known
                // fragment is the "Dispatch an expeditionary fleet" mission,
                // which does not fit this chapter's theme and waits for a
                // vanilla dump. This fork's Directive IV is deliberately
                // defense-themed: one building_level mission per defense
                // structure from the defense screen, in screen order,
                // completed by building the structure to level 1 on any
                // planet. Titles reuse the exact in-game defense structure
                // names so the rows match the defense screen's vocabulary.
                // Rewards follow the scale of the other chapters' best-effort
                // rewards. Replace the whole chapter once the vanilla quest
                // data can be dumped from a live universe.
                'iv_rocket_launcher' => [
                    'title' => 't_directives.missions.iv_rocket_launcher.title',
                    'condition' => [
                        'type' => 'building_level',
                        'object' => 'rocket_launcher',
                        'target' => 1,
                        'scope' => 'any',
                    ],
                    'rewards' => [
                        'metal' => 400, // best-effort, not documented upstream
                        'crystal' => 200, // best-effort, not documented upstream
                    ],
                ],
                'iv_light_laser' => [
                    'title' => 't_directives.missions.iv_light_laser.title',
                    'condition' => [
                        'type' => 'building_level',
                        'object' => 'light_laser',
                        'target' => 1,
                        'scope' => 'any',
                    ],
                    'rewards' => [
                        'metal' => 500, // best-effort, not documented upstream
                        'crystal' => 250, // best-effort, not documented upstream
                    ],
                ],
                'iv_heavy_laser' => [
                    'title' => 't_directives.missions.iv_heavy_laser.title',
                    'condition' => [
                        'type' => 'building_level',
                        'object' => 'heavy_laser',
                        'target' => 1,
                        'scope' => 'any',
                    ],
                    'rewards' => [
                        'metal' => 800, // best-effort, not documented upstream
                        'crystal' => 400, // best-effort, not documented upstream
                    ],
                ],
                'iv_gauss_cannon' => [
                    'title' => 't_directives.missions.iv_gauss_cannon.title',
                    'condition' => [
                        'type' => 'building_level',
                        'object' => 'gauss_cannon',
                        'target' => 1,
                        'scope' => 'any',
                    ],
                    'rewards' => [
                        'metal' => 1200, // best-effort, not documented upstream
                        'crystal' => 600, // best-effort, not documented upstream
                        'deuterium' => 300, // best-effort, not documented upstream
                    ],
                ],
                'iv_ion_cannon' => [
                    'title' => 't_directives.missions.iv_ion_cannon.title',
                    'condition' => [
                        'type' => 'building_level',
                        'object' => 'ion_cannon',
                        'target' => 1,
                        'scope' => 'any',
                    ],
                    'rewards' => [
                        'metal' => 1000, // best-effort, not documented upstream
                        'crystal' => 500, // best-effort, not documented upstream
                        'deuterium' => 250, // best-effort, not documented upstream
                    ],
                ],
                'iv_plasma_turret' => [
                    'title' => 't_directives.missions.iv_plasma_turret.title',
                    'condition' => [
                        'type' => 'building_level',
                        'object' => 'plasma_turret',
                        'target' => 1,
                        'scope' => 'any',
                    ],
                    'rewards' => [
                        'metal' => 1500, // best-effort, not documented upstream
                        'crystal' => 800, // best-effort, not documented upstream
                        'deuterium' => 400, // best-effort, not documented upstream
                    ],
                ],
                'iv_small_shield_dome' => [
                    'title' => 't_directives.missions.iv_small_shield_dome.title',
                    'condition' => [
                        'type' => 'building_level',
                        'object' => 'small_shield_dome',
                        'target' => 1,
                        'scope' => 'any',
                    ],
                    'rewards' => [
                        'metal' => 800, // best-effort, not documented upstream
                        'crystal' => 800, // best-effort, not documented upstream
                    ],
                ],
                'iv_large_shield_dome' => [
                    'title' => 't_directives.missions.iv_large_shield_dome.title',
                    'condition' => [
                        'type' => 'building_level',
                        'object' => 'large_shield_dome',
                        'target' => 1,
                        'scope' => 'any',
                    ],
                    'rewards' => [
                        'metal' => 1500, // best-effort, not documented upstream
                        'crystal' => 1500, // best-effort, not documented upstream
                        'deuterium' => 500, // best-effort, not documented upstream
                    ],
                ],
                'iv_anti_ballistic_missile' => [
                    'title' => 't_directives.missions.iv_anti_ballistic_missile.title',
                    'condition' => [
                        'type' => 'building_level',
                        'object' => 'anti_ballistic_missile',
                        'target' => 1,
                        'scope' => 'any',
                    ],
                    'rewards' => [
                        'metal' => 2000, // best-effort, not documented upstream
                        'crystal' => 800, // best-effort, not documented upstream
                        'deuterium' => 400, // best-effort, not documented upstream
                    ],
                ],
            ],
        ],
    ],
];
