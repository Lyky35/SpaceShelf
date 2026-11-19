{{--
    Directives (quest system) overlay — fetched by the vanilla overlay framework
    from the left-menu "Directives" panel link. Markup mirrors the vanilla
    structure the vendored client CSS expects: #ipioverviewlayer >
    #ipiOverviewChapters, #ipiOverviewChapterTitle, #ipiOverviewContent
    (position: relative) wrapping #ipiOverviewTasklist (the scroll region) and
    the absolutely-positioned #ipiOverviewChapterRewards bar, then
    #ipiOverviewFooter. Interactions are driven by directives.js.

    #ipiOverviewChapterRewards is position:absolute bottom:0 relative to
    #ipiOverviewContent — do NOT move it outside that wrapper or the Claim All
    bar floats over the menu/task list again. The scroll height is pinned by
    resources/css/ingame/modules/directives.css.
--}}
<div id="ipioverviewlayer" data-unclaimed="{{ $unclaimed }}">
    <div id="ipiOverviewChapters">
        @foreach ($chapters as $chapterTab)
            <div class="ipiChapterItem{{ $chapterTab['active'] ? ' active' : '' }}">
                <a href="{{ route('directives.overlay', ['chapter' => $chapterTab['id']]) }}"
                   data-directive-chapter="{{ $chapterTab['id'] }}">
                    {{ $chapterTab['tab'] }}@if ($chapterTab['all_done'])<span class="ipiTabDoneMark">&#10004;</span>@endif
                </a>
            </div>
        @endforeach
    </div>

    <h1 id="ipiOverviewChapterTitle">{{ $chapter['title'] }}</h1>

    <div id="ipiOverviewContent">
        <ul id="ipiOverviewTasklist">
            @foreach ($missions as $mission)
                <li class="ipiTaskItem" data-state="{{ $mission['state'] }}">
                    <div class="ipiTaskItemHeader">
                        <div class="ipiTaskItemTitle">{{ $mission['title'] }} <span class="ipiTaskCompletedMark">&#10004;</span></div>
                        <div class="ipiTaskItemProgress">{{ $mission['progress_current'] }} / {{ $mission['progress_target'] }}</div>
                        <div class="ipiTaskItemTrack">{{ $mission['track'] }}</div>
                    </div>
                    <div class="ipiTaskItemContent">
                        <div class="ipiTaskItemContentInner">
                            @if ($mission['description'] !== null)
                                <div class="ipiTaskItemDescription">
                                    <div class="ipiTaskItemImage"></div>
                                    <div class="ipiTaskItemDescriptionInner">{{ $mission['description'] }}</div>
                                </div>
                            @endif

                            <div class="ipiTaskItemContentSplit">
                                <div class="ipiTaskItemActions">
                                    @if ($mission['actions'] !== [])
                                        {{ __('t_directives.ui.complete_the_following_missions') }}
                                        <ul class="ipiTaskItemActionsList">
                                            @foreach ($mission['actions'] as $action)
                                                <li class="ipiActionItem">{{ $action }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                                <div class="ipiTaskItemRewards">
                                    @if ($mission['rewards'] !== [])
                                        {{ __('t_directives.ui.mission_reward_header') }}
                                        <ul class="ipiRewardsList">
                                            @foreach ($mission['rewards'] as $reward)
                                                <li>
                                                    <span class="{{ $reward['class'] }}"></span>{{ $reward['amount'] }}
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            </div>

                            <div class="ipiTaskItemContentCollect">
                                @if ($mission['state'] === 'collected')
                                    <span class="textBeefy">{{ __('t_directives.ui.collected') }}</span>
                                @else
                                    <button type="button" class="btn_blue"
                                            data-directive-claim="1"
                                            data-directive="{{ $mission['directive_id'] }}"
                                            data-mission="{{ $mission['mission_id'] }}"
                                            @if ($mission['state'] !== 'completed') disabled="disabled" @endif
                                    >{{ __('t_directives.ui.collect') }}</button>
                                @endif
                            </div>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>

        @if ($chapter['rewards'] !== [])
            <div id="ipiOverviewChapterRewards">
                <div class="ipiOverviewChapterRewardsText">
                    {{ __('t_directives.ui.chapter_reward_header') }}
                    <ul class="ipiRewardsList">
                        @foreach ($chapter['rewards'] as $reward)
                            <li>
                                <span class="{{ $reward['class'] }}"></span>{{ $reward['amount'] }}
                            </li>
                        @endforeach
                    </ul>
                </div>
                <span class="ipiOverviewCollectRewards{{ $chapter['bonus_state'] !== 'completed' ? ' disabled' : '' }}"
                      data-directive-claim-all="{{ $chapter['id'] }}"
                >{{ __('t_directives.ui.claim_all') }}</span>
            </div>
        @endif
    </div>

    <div id="ipiOverviewFooter"></div>
</div>
