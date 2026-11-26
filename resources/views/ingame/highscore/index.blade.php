@extends('ingame.layouts.main')

@section('content')

    @if (session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

    <script type="text/javascript">
        highscoreContentUrl = '{{ route('highscore.ajax') }}';
        // SpaceShelf: the page no longer auto-scrolls to the current
        // player's rank on load (rank is still highlighted via .myrank).
        // Links that explicitly search for a player's rank (searchRelId,
        // e.g. from the buddy list) still scroll to that player.
        var userWantsFocus = {{ request()->has('searchRelId') ? 'true' : 'false' }};
        var highscoreLoca = {!! json_encode([
            'playerHighscore'  => __('t_ingame.highscore.player_highscore'),
            'allianceHighscore'=> __('t_ingame.highscore.alliance_highscore'),
        ]) !!};
    </script>

    <div id="inhalt">
        <div id="highscoreContent">
            <div class="header">
                <h2>{{ __('t_ingame.highscore.player_highscore') }}</h2>
            </div>
            <div class="content">
                <form id="send" name="send" action="#highscore">
                    <div id="scrollToTop" style="left: 760.336px;"><a href="javascript:void(0);" title="{{ __('t_ingame.layout.back_to_top') }}" class="scrollToTop tooltip js_hideTipOnMobile"></a></div>
                    <div id="row">

                        <div class="buttons leftCol" id="categoryButtons">
                            <a id="player" class="active navButton" href="javascript:void(0);" rel="1" onclick="">
                                <img src="/img/icons/3e567d6f16d040326c7a0ea29a4f41.gif" height="54" width="54">
                                <span class="marker"></span>
                                <span class="textlabel">{{ __('t_ingame.highscore.player_highscore') }}</span>
                            </a>
                            <a id="alliance" class="navButton" href="javascript:void(0);" rel="2" onclick="">
                                <img src="/img/icons/3e567d6f16d040326c7a0ea29a4f41.gif" height="54" width="54">
                                <span class="marker"></span>
                                <span class="textlabel">{{ __('t_ingame.highscore.alliance_highscore') }}</span>
                            </a>
                        </div>

                        <div class="buttons rightCol" id="typeButtons">

                            <a id="points" class="stat_filter active navButton fleft" href="javascript:void(0);" rel="0">
                                <img src="/img/icons/3e567d6f16d040326c7a0ea29a4f41.gif" height="54" width="54">
                                <span class="marker"></span>
                                <span class="textlabel">{{ __('t_ingame.highscore.points') }}</span>
                            </a>

                            <a id="economy" class="stat_filter navButton fleft" href="javascript:void(0);" rel="1">
                                <img src="/img/icons/3e567d6f16d040326c7a0ea29a4f41.gif" height="54" width="54">
                                <span class="marker"></span>
                                <span class="textlabel">{{ __('t_ingame.highscore.economy') }}</span>
                            </a>

                            <a id="research" class="stat_filter navButton fleft" href="javascript:void(0);" rel="2">
                                <img src="/img/icons/3e567d6f16d040326c7a0ea29a4f41.gif" height="54" width="54">
                                <span class="marker"></span>
                                <span class="textlabel">{{ __('t_ingame.highscore.research') }}</span>
                            </a>

                            <a id="fleet" class="stat_filter navButton fleft" href="javascript:void(0);" rel="3">
                                <img src="/img/icons/3e567d6f16d040326c7a0ea29a4f41.gif" height="54" width="54">
                                <span class="marker"></span>
                                <span class="textlabel">{{ __('t_ingame.highscore.military') }}</span>
                            </a>

                            <div id="subnav_fleet" class="fleft subnav">
                                <a href="javascript:void(0);" rel="5" class="subnavButton subnavButton_built tooltip js_hideTipOnMobile" title="{{ __('t_ingame.highscore.military_built') }}">
                                    <span class="small-marker"></span>
                                </a>


                                <a href="javascript:void(0);" rel="6" class="subnavButton subnavButton_destroyed tooltip js_hideTipOnMobile" title="{{ __('t_ingame.highscore.military_destroyed') }}">
                                    <span class="small-marker"></span>
                                </a>

                                <a href="javascript:void(0);" rel="4" class="subnavButton subnavButton_lost tooltip js_hideTipOnMobile" title="{{ __('t_ingame.highscore.military_lost') }}">
                                    <span class="small-marker"></span>
                                </a>

                                <a href="javascript:void(0);" rel="7" class="subnavButton subnavButton_honor tooltip js_hideTipOnMobile" title="{{ __('t_ingame.highscore.honour_points') }}">
                                    <span class="small-marker"></span>
                                </a>
                            </div>
                        </div>

                        <br class="clearfloat">
                    </div>

                    <div class="" id="stat_list_content">
                        <!-- start dynamic highscore paging content -->
                        {!! $initialContent !!}
                        <!-- end dynamic highscore paging content -->
                    </div>
                </form>
            </div>
            <div class="footer"></div>
        </div>

        {{-- SpaceShelf addition: daily score timeline graphs, one per
             highscore tab (points/economy/research/military incl. its
             sub-categories, plus alliances). Lives outside
             #stat_list_content so the AJAX tab switching never replaces
             it; statscharts.js re-renders it whenever a tab is clicked. --}}
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
                            <b>{{ number_format($stats_timelines['ships']['total_built']) }}</b>
                        </div>
                        <div class="statsTotal">
                            <span>{{ __('t_ingame.stats.ships_destroyed') }}</span>
                            <b>{{ number_format($stats_timelines['ships']['total_destroyed']) }}</b>
                        </div>
                    </div>

                    @if (count($stats_timelines['ships']['dates']) === 0)
                        <p class="statsEmpty">{{ __('t_ingame.stats.no_data') }}</p>
                    @else
                        <h4 class="statsSubtitle" id="statsChartSubtitle">{{ $stats_subtitles['player_general'] }}</h4>
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
            var STATS_TIMELINES = {!! json_encode($stats_timelines, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
            var STATS_SUBTITLES = {!! json_encode($stats_subtitles, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
            var STATS_SHIP_LABELS = {
                built: '{{ __('t_ingame.stats.ships_built') }}',
                destroyed: '{{ __('t_ingame.stats.ships_destroyed') }}'
            };
        </script>
    </div>

@endsection
