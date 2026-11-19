<?php

namespace OGame\Enums;

/**
 * Status of a Directives (quest system) mission or chapter bonus row.
 *
 * Completed means the conditions are met and the reward can be claimed; Claimed means
 * the reward has been handed out to the planet that was active at claim time.
 */
enum DirectiveProgressStatus: string
{
    case Completed = 'completed';
    case Claimed = 'claimed';
}
