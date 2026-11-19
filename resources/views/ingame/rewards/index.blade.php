@extends('ingame.layouts.main')

@section('content')

    <div id="rewardscomponent" class="maincontent">
        <div id="content">
            <div id="inhalt">
                <div id="planet" style="background-image:url(/img/headers/rewards/rewards.jpg);height:250px;">
                    <div id="header_text">
                        <h2>{{ __('t_rewards.title') }}</h2>
                    </div>
                </div>
                <div id="buttonz">
                    <div class="header">
                        <h2>{{ __('t_rewards.title') }}</h2>
                    </div>
                    <div class="content">
                        @if (session('rewardError'))
                            <div class="rewardhint rewardnotifyerror">
                                <img class="rewardwarningicon" src="/img/icons/04be50e8afc747846a55a646381a16.png">
                                <span class="rewardwarningtext">{{ session('rewardError') }}</span>
                            </div>
                        @elseif (session('rewardClaimed'))
                            <div class="rewardhint rewardnotifyclaimed">
                                <img class="rewardwarningicon" src="/img/icons/04be50e8afc747846a55a646381a16.png">
                                <span class="rewardwarningtext">{{ session('rewardClaimed') }}</span>
                            </div>
                        @else
                            <div class="rewardhint rewardnotifyhidden">
                                <img class="rewardwarningicon" src="/img/icons/04be50e8afc747846a55a646381a16.png">
                                <span class="rewardwarningtext"></span>
                            </div>
                        @endif

                        <div class="rewardlist">
                            <a class="tooltipLeft fright questionIcons" style="display: inline-block" title="{{ __('t_rewards.hint') }}">
                                <span class="rewardDetail"></span>
                            </a>
                            <br>
                            <h3>{{ __('t_rewards.new_awards') }}</h3>

                            @foreach ($rewards['new'] ?? [] as $item)
                                <div class="rewardlist-item">
                                    <div class="rewardlistimg {{ $item['reward']->getIconClass() }} rewardcanclaim">
                                        <div class="rewardlist-item-icon">
                                            <img src="/img/icons/2251eaefdfdf075833e5247781a4ac.png">
                                        </div>
                                        <div class="rewardlist-item-text">
                                            <h3>{{ __('t_rewards.rewards.' . $item['reward']->value . '.title') }}</h3>
                                            <div class="rewardlist-item-wrapper">
                                                <p>{!! nl2br(e($item['message'])) !!}</p>
                                                <form id="claim-form-{{ $item['reward']->value }}" action="{{ route('rewards.claim', ['reward' => $item['reward']->value]) }}" method="post" style="display:none">@csrf</form>
                                                <a class="reward-button" href="javascript:void(0)" onclick="document.getElementById('claim-form-{{ $item['reward']->value }}').submit()">{{ __('t_rewards.claim') }}</a>
                                            </div>
                                            <div class="rewardlist-item-bottom"></div>
                                        </div>
                                    </div>
                                </div>
                                <br>
                            @endforeach

                            <h3>{{ __('t_rewards.awards_not_reached') }}</h3>
                            @foreach ($rewards['not_reached'] ?? [] as $item)
                                <div class="rewardlist-item">
                                    <div class="rewardlistimg {{ $item['reward']->getIconClass() }} rewardnotclaim">
                                        <div class="rewardlist-item-icon">
                                            <img src="/img/icons/2251eaefdfdf075833e5247781a4ac.png">
                                        </div>
                                        <div class="rewardlist-item-text">
                                            <h3>{{ __('t_rewards.rewards.' . $item['reward']->value . '.title') }}</h3>
                                            <div class="rewardlist-item-wrapper">
                                                <p>{!! nl2br(e($item['message'])) !!}</p>
                                                <a class="reward-button disabled" href="javascript:void(0)">{{ __('t_rewards.not_fulfilled') }}</a>
                                            </div>
                                            <div class="rewardlist-item-bottom"></div>
                                        </div>
                                    </div>
                                </div>
                                <br>
                            @endforeach

                            <h3>{{ __('t_rewards.collected_awards') }}</h3>
                            @foreach ($rewards['collected'] ?? [] as $item)
                                <div class="rewardlist-item">
                                    <div class="rewardlistimg {{ $item['reward']->getIconClass() }} rewardclaimed">
                                        <div class="rewardlist-item-icon">
                                            <img src="/img/icons/2251eaefdfdf075833e5247781a4ac.png">
                                        </div>
                                        <div class="rewardlist-item-text">
                                            <h3>{{ __('t_rewards.rewards.' . $item['reward']->value . '.title') }}</h3>
                                            <div class="rewardlist-item-wrapper">
                                                <p>{!! nl2br(e($item['message'])) !!}</p>
                                                <a class="reward-button disabled" href="javascript:void(0)">{{ __('t_rewards.collected') }}</a>
                                            </div>
                                            <div class="rewardlist-item-bottom"></div>
                                        </div>
                                    </div>
                                </div>
                                <br>
                            @endforeach
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

@endsection
