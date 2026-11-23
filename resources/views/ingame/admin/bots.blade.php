@extends('ingame.layouts.main')

@section('content')

    @if (session('success'))
        <script>fadeBox('{{ session('success') }}', false);</script>
    @endif

    @if (session('error'))
        <script>fadeBox('{{ session('error') }}', true);</script>
    @endif

    <div id="botscomponent" class="maincontent">
        <div id="planet" class="shortHeader">
            <h2>Bots</h2>
        </div>

        <p style="font-size: 11px; color: #999;">
            {{ $bots->total() }} bot player(s), {{ $enabledCount }} enabled, {{ $hiatusCount }} on hiatus (pool target {{ (int) config('bots.population.target_size') }}).
            Profile switches rebuild the bot's personality and sync its character class; hidden bots are removed from the public highscore.
        </p>

        <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
            <tr style="background: #1f1f1f; color: #fff;">
                <th style="padding: 4px 6px; text-align: left;">Bot</th>
                <th style="padding: 4px 6px; text-align: left;">Profile</th>
                <th style="padding: 4px 6px; text-align: right;">Score</th>
                <th style="padding: 4px 6px; text-align: right;">Ticks / last actions</th>
                <th style="padding: 4px 6px; text-align: left;">Next tick</th>
                <th style="padding: 4px 6px; text-align: left;">Actions</th>
            </tr>
            @foreach ($bots as $bot)
                <tr style="border-bottom: 1px solid #333;">
                    <td style="padding: 4px 6px;">{{ $bot->user->username }}</td>
                    <td style="padding: 4px 6px;">
                        {{ $bot->profile }}
                        @if ($bot->hide_from_highscore)
                            <span style="color: #888;">(highscore hidden)</span>
                        @endif
                    </td>
                    <td style="padding: 4px 6px; text-align: right;">{{ number_format((int) ($bot->user->highscore->general ?? 0)) }}</td>
                    <td style="padding: 4px 6px; text-align: right;">{{ $bot->total_ticks }} / {{ $bot->last_actions ?? '—' }}</td>
                    <td style="padding: 4px 6px;">
                        @if ($bot->inactive_since)
                            {{ $bot->inactive_until ? 'hiatus until ' . $bot->inactive_until->format('Y-m-d') : 'gone for good (' . $bot->inactive_since->format('Y-m-d') . ')' }}
                        @elseif ($bot->enabled)
                            {{ $bot->next_action_at?->format('Y-m-d H:i') ?? '—' }}
                        @else
                            paused
                        @endif
                    </td>
                    <td style="padding: 4px 6px; white-space: nowrap;">
                        <form method="post" action="{{ route('admin.bots.enabled', $bot) }}" style="display: inline;">
                            @csrf
                            <button type="submit">{{ $bot->enabled ? 'Pause' : 'Resume' }}</button>
                        </form>
                        <form method="post" action="{{ route('admin.bots.highscore', $bot) }}" style="display: inline;">
                            @csrf
                            <button type="submit">{{ $bot->hide_from_highscore ? 'Unhide' : 'Hide' }}</button>
                        </form>
                        <form method="post" action="{{ route('admin.bots.profile', $bot) }}" style="display: inline;">
                            @csrf
                            <select name="profile">
                                @foreach ($profiles as $profile)
                                    <option value="{{ $profile }}" @selected($profile === $bot->profile)>{{ $profile }}</option>
                                @endforeach
                            </select>
                            <button type="submit">Save</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </table>

        <div style="margin-top: 10px;">
            {{ $bots->links() }}
        </div>
    </div>

@endsection
