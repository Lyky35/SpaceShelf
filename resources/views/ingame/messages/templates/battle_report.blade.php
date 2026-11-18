
<div class="combatInfo">
        <div class="basicInfo">
            <span class="msg_ctn msg_ctn2 {{ $attacker_class }} tooltipLeft" data-tooltip-title="12,000">{{ __('t_ingame.messages.battle_attacker') }}: ({{ $attacker_name }}): {{ $attacker_losses }}</span>
            <span class="msg_ctn msg_ctn3">{{ __('t_ingame.messages.battle_resources') }}: {{ $loot }}, {{ __('t_ingame.messages.battle_loot') }}: {{ $loot_percentage }}%</span>
            <span class="msg_ctn msg_ctn3 tooltipLeft" data-tooltip-title="{{ $debris_sum_formatted }}">{{ __('t_ingame.messages.battle_debris_new') }}: {{ $debris_sum_formatted }}</span>
        </div>
        <div class="miscInfo">
            <span class="msg_ctn msg_ctn2 {{ $defender_class }} tooltipRight" data-tooltip-title="10,210,000">{{ __('t_ingame.messages.battle_defender') }}: ({{ $defender_name }}): {{ $defender_losses }}</span>
            <span class="msg_ctn msg_ctn3 tooltipRight" data-tooltip-title="1,539">{{ __('t_ingame.messages.battle_repaired') }}: {{ $repaired_defenses_count }}</span>
@if (!$moon_existed)
            <span class="msg_ctn msg_ctn3 tooltipRight" data-tooltip-title="1,539">{{ __('t_ingame.messages.battle_moon_chance') }}: {{ $moon_chance }}%</span>
@endif
        </div>
    </div>
