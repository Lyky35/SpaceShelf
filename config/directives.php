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
| - NOT documented upstream and therefore intentionally absent: chapter III,
|   V and VII content (Directive VI is the Lifeforms chapter, skipped
|   entirely as lifeforms are unimplemented), the Directive I chapter bonus
|   and per-mission sub-step texts for Directive II. Chapters appear as tabs
|   as soon as they are defined here.
| - Best-effort, NOT verified: the per-mission rewards of Directive II.
|   They follow the scale of the verified chapter bonus and of the other
|   documented rewards; replace them once the vanilla quest data can be
|   dumped from a live universe. Each such reward is marked below.
|
| Mission condition types (implemented in DirectiveService):
| - activity: one-off marker (see DirectiveService::ACTIVITY_*)
| - alliance: player is member of an alliance
| - building_level: {object, target, scope: any|active}
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
    ],
];
