{{--
    Directives (quest system) overlay — fetched by the vanilla overlay framework
    from the left-menu "Directives" panel link. Markup and data-state attributes
    follow the vendored vanilla client CSS classes (#ipioverviewlayer,
    .ipiChapterItem, .ipiTaskItem[data-state], .ipiRewardsList,
    .ipiOverviewCollectRewards); interactions are driven by directives.js.
--}}
<div id="ipioverviewlayer" data-unclaimed="{{ $unclaimed }}">
    <div id="ipiOverviewChapters">
        <ul style="list-style: none; margin: 0; padding: 0; display: flex; gap: 5px;">
            @foreach ($chapters as $chapterTab)
                <li class="ipiChapterItem{{ $chapterTab['active'] ? ' active' : '' }}">
                    <a href="{{ route('directives.overlay', ['chapter' => $chapterTab['id']]) }}"
                       data-directive-chapter="{{ $chapterTab['id'] }}">
                        {{ $chapterTab['tab'] }}@if ($chapterTab['all_done'])<span style="color: #99cc00;">&#10004;</span>@endif
                    </a>
                </li>
            @endforeach
        </ul>
    </div>

    <h1 id="ipiOverviewChapterTitle" style="text-align: center;">{{ $chapter['title'] }}</h1>

    <ul id="ipiOverviewTasklist" style="list-style: none; margin: 10px 0 0 0; padding: 0;">
        @foreach ($missions as $mission)
            <li class="ipiTaskItem" data-state="{{ $mission['state'] }}">
                <div class="ipiTaskItemHeader">
                    <div class="ipiTaskItemTitle">{{ $mission['title'] }} <span class="ipiTaskCompletedMark" style="color: #99cc00;">&#10004;</span></div>
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
                                    <ul class="ipiTaskItemActionsList" style="list-style: none;">
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
                                <span class="textBeefy" style="color: #848484;">{{ __('t_directives.ui.collected') }}</span>
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
        <div id="ipiOverviewChapterRewards" style="margin-top: 10px;">
            <div style="display: flex; align-items: center; gap: 10px; background-color: #13181D; border: 1px solid #000; padding: 10px; box-sizing: border-box;">
                <div style="flex: 1;">
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
        </div>
    @endif
</div>
