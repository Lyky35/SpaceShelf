@extends('ingame.layouts.main')

@section('content')

    @if (session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

    @if (session('statusError'))
        <div class="alert alert-error">
            {{ session('statusError') }}
        </div>
    @endif

    <div id="eventboxContent" style="display: none">
        <img height="16" width="16" src="/img/icons/3f9884806436537bdec305aa26fc60.gif">
    </div>

    <div id="inhalt" class="officers">
        <div id="planet">
            <div id="header_text">
                <h2>{{ __('t_ingame.premium.recruit_officers') }}</h2>
            </div>

            <div id="detail" class="detail_screen small">
                <div id="techDetailLoading"></div>
            </div>

        </div>	<div class="c-left"></div>
        <div class="c-right"></div>
        <div id="buttonz">
            <div class="header">
                <h2>{{ __('t_ingame.premium.your_officers') }}</h2>
            </div>
            <div class="content">
                <p class="stimulus">
                    {{ __('t_ingame.premium.intro_text') }}</p>

                <ul id="building">
                    <li class="on button" id="button1">
                        <div class="premium1">
                            <div class="officers100  darkMatter">
                                <a tabindex="1" href="javascript:void(0);" title="{{ __('t_ingame.premium.info_dark_matter') }}" class="detail_button tooltip js_hideTipOnMobile slideIn" ref="1">
                        <span class="ecke">
                            <span class="level">
                                {{ number_format($darkMatter, 0, ',', '.') }}
                            </span>
                        </span>
                                </a>
                            </div>
                        </div>			</li>
                    @foreach($officerRows as $index => $officerData)
                        <li class="button{{ $officerData['remainingDays'] > 0 ? ' on' : '' }}" id="button{{ $index + 2 }}">
                            <div class="premium">
                                <div class="officers100  {{ $officerData['officer']->value }}">
                                    <a tabindex="{{ $index + 2 }}" href="javascript:void(0);" title="{{ __('t_ingame.premium.info_' . $officerData['officer']->value) }}" ref="{{ $index + 2 }}" class="detail_button tooltip js_hideTipOnMobile slideIn">
                                    </a>
                                </div>
                                <div class="remaining tooltip" title="{{ __('t_ingame.premium.cost_per_week') }}">
                                    <span class="remDate">
                                        @if($officerData['remainingDays'] > 0)
                                            {{ __('t_ingame.premium.status_active', ['days' => $officerData['remainingDays']]) }}
                                        @else
                                            {{ __('t_ingame.premium.status_not_hired') }}
                                        @endif
                                    </span>
                                </div>
                                <form method="POST" action="{{ route('premium.officer.hire') }}" style="position: absolute; bottom: 2px; left: 2px; width: 96px;">
                                    @csrf
                                    <input type="hidden" name="officer" value="{{ $officerData['officer']->value }}">
                                    <input type="hidden" name="weeks" value="1">
                                    <button type="submit" class="btn_blue" style="width: 100%; font-size: 10px; line-height: 1.1; padding: 2px 0; cursor: pointer;"
                                        @if($darkMatter < $officerData['cost'])
                                            disabled title="{{ __('t_ingame.premium.hire_insufficient_dm') }}"
                                        @else
                                            title="{{ __('t_ingame.premium.cost_week', ['cost' => number_format($officerData['cost'], 0, ',', '.')]) }}"
                                        @endif>
                                        {{ __('t_ingame.premium.hire') }}<br>{{ number_format($officerData['cost'], 0, ',', '.') }} DM
                                    </button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                    <li class="button{{ $activeOfficerCount === 5 ? ' on' : '' }}" id="button12">
                        <div class="premium">
                            <div class="officers100  allOfficers">
                                <a tabindex="12" href="javascript:void(0);" title="{{ __('t_ingame.premium.info_commanding_staff') }}" ref="12" class="detail_button tooltip js_hideTipOnMobile slideIn">
                        <span class="ecke">
                            <span class="level">
                                5
                            </span>
                        </span>
                                </a>
                            </div>
                            <div class="remaining tooltip " title="">
                                <span class="remDate">{{ __('t_ingame.premium.remaining_officers', ['current' => $activeOfficerCount, 'max' => 5]) }}</span>
                            </div>
                        </div>
                    </li>

                    <li class="allOfficers {{ $activeOfficerCount === 5 ? 'on' : 'off' }}">
                        <span title="{{ __('t_ingame.premium.benefit_fleet_slots_title') }}" class="tooltipCustom tooltipTop">{{ __('t_ingame.premium.benefit_fleet_slots') }}</span><span title="{{ __('t_ingame.premium.benefit_energy_title') }}" class="tooltipCustom tooltipTop">{{ __('t_ingame.premium.benefit_energy') }}</span><span title="{{ __('t_ingame.premium.benefit_mines_title') }}" class="tooltipCustom tooltipTop">{{ __('t_ingame.premium.benefit_mines') }}</span><span title="{{ __('t_ingame.premium.benefit_espionage_title') }}" class="tooltipCustom tooltipTop">{{ __('t_ingame.premium.benefit_espionage') }}</span>            </li>
                </ul>
                <br class="clearfloat">
                <div class="footer"></div>
            </div>
        </div>
    </div>

@endsection
