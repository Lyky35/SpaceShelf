{{-- Overview page statistics timeline box, rendered in the middle column
     directly below the fleet events (Events/Missions) box. Only shown on
     pages whose controller provides $stats_scores / $stats_ships (the
     overview). Uses the stretchable contentbox skin (same look as the
     planet header panels) so it scales with the 670px content column. --}}
<div class="statsCharts">
    <div class="contentbox">
        <div class="header">
            <span class="c-left"></span>
            <span class="c-right"></span>
            <h3>{{ __('t_ingame.stats.title') }}</h3>
        </div>
        <div class="content">
            <div class="statsTotals">
                <div class="statsTotal">
                    <span>{{ __('t_ingame.stats.ships_built') }}</span>
                    <b>{{ number_format($stats_ships['total_built']) }}</b>
                </div>
                <div class="statsTotal">
                    <span>{{ __('t_ingame.stats.ships_destroyed') }}</span>
                    <b>{{ number_format($stats_ships['total_destroyed']) }}</b>
                </div>
            </div>

            @if (count($stats_scores['dates']) === 0)
                <p class="statsEmpty">{{ __('t_ingame.stats.no_data') }}</p>
            @else
                <h4 class="statsSubtitle">{{ __('t_ingame.stats.score_history') }}</h4>
                <div id="statsScoreChart" class="statsChart"></div>
                <div id="statsScoreLegend" class="statsLegend"></div>

                <h4 class="statsSubtitle">{{ __('t_ingame.stats.ships_history') }}</h4>
                <div id="statsShipChart" class="statsChart"></div>
                <div id="statsShipLegend" class="statsLegend"></div>
            @endif
        </div>
        <div class="footer">
            <span class="c-left"></span>
            <span class="c-right"></span>
        </div>
    </div>
</div>

<script type="text/javascript">
    var STATS_SCORE_DATA = {!! json_encode($stats_scores, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
    var STATS_SHIP_DATA = {!! json_encode(array_merge($stats_ships, [
        'label_built' => __('t_ingame.stats.ships_built'),
        'label_destroyed' => __('t_ingame.stats.ships_destroyed'),
    ]), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
</script>
