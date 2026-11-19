<?php

namespace OGame\GameMessages;

use OGame\GameMessages\Abstracts\GameMessage;

class StarterAid extends GameMessage
{
    /**
     * The full reward text is provided as the "text" param. The reward-specific
     * texts are defined in the t_rewards language file.
     */
    protected function initialize(): void
    {
        $this->key = 'starter_aid';
        $this->params = ['text'];
        $this->tab = 'universe';
        $this->subtab = 'universe';
    }
}
