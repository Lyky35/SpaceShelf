<?php

namespace OGame\Enums;

/**
 * Enum that represents the types of highscores.
 *
 * The backed values match the vanilla OGame highscore type IDs (see the
 * official highscore.xml API): 0 total, 1 economy, 2 research, 3 military,
 * 4 military lost, 5 military built, 6 military destroyed, 7 honor. The
 * declaration order below therefore must stay aligned with the IDs, as
 * HighscoreService resolves incoming type IDs positionally (the left-menu
 * sub-navigation sends exactly these values).
 *
 * Case names double as the highscore table column names (e.g. the
 * `military_lost` column and its `military_lost_rank`), so renaming a case
 * requires a matching migration.
 */
enum HighscoreTypeEnum: int
{
    case general = 0;
    case economy = 1;
    case research = 2;
    case military = 3;
    case military_lost = 4;
    case military_built = 5;
    case military_destroyed = 6;
    case honor = 7;
}
