{{--
    Local dev tenant bar. Injected before </body> by App\Http\Middleware\DevSiteBar.

    All styling is inline and scoped to #dev-site-bar on purpose:
    - The per-theme Tailwind bundles restrict @source to their own theme
      directory, so utility classes used here would not be compiled into the
      bundle of the theme you are debugging. A debugging tool must not depend
      on the build it is used to debug.
    - <details> instead of Alpine: themes/ss loads no JS chrome at all.
    - ID-scoped selectors outrank any theme utility class on specificity.
--}}
@php
    $navBroken = collect($sites->firstWhere('is_current')['nav'] ?? [])->where('status', '404')->count();
@endphp
<div id="dev-site-bar" data-site="{{ $site->slug }}">
    <style>
        #dev-site-bar {
            position: fixed; left: 0; bottom: 0; z-index: 2147483000;
            font: 12px/1.45 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            color: #e7e5e4; max-width: min(560px, 100vw);
        }
        #dev-site-bar * { box-sizing: border-box; }
        #dev-site-bar details {
            background: #1c1917; border: 1px solid #44403c; border-left: 0; border-bottom: 0;
            border-top: 3px solid hsl({{ $sites->firstWhere('is_current')['hue'] ?? 0 }} 70% 55%);
            border-radius: 0 8px 0 0; box-shadow: 0 -2px 24px rgb(0 0 0 / .35);
        }
        #dev-site-bar summary {
            cursor: pointer; list-style: none; padding: 7px 12px;
            display: flex; align-items: center; gap: 8px; white-space: nowrap;
        }
        #dev-site-bar summary::-webkit-details-marker { display: none; }
        #dev-site-bar .dsb-dot { width: 9px; height: 9px; border-radius: 50%; flex: none; }
        #dev-site-bar .dsb-slug { font-weight: 700; color: #fafaf9; }
        #dev-site-bar .dsb-dim { color: #a8a29e; }
        #dev-site-bar .dsb-tag {
            padding: 1px 6px; border-radius: 4px; background: #292524; color: #d6d3d1;
            border: 1px solid #44403c; font-size: 11px;
        }
        #dev-site-bar .dsb-tag.warn { background: #422006; border-color: #854d0e; color: #fde68a; }
        #dev-site-bar .dsb-tag.bad { background: #450a0a; border-color: #7f1d1d; color: #fecaca; }
        #dev-site-bar .dsb-panel { padding: 4px 12px 12px; border-top: 1px solid #292524; max-height: 60vh; overflow: auto; }
        #dev-site-bar h4 {
            margin: 12px 0 5px; font-size: 10px; letter-spacing: .12em; text-transform: uppercase;
            color: #78716c; font-weight: 600;
        }
        #dev-site-bar a { color: #7dd3fc; text-decoration: none; }
        #dev-site-bar a:hover { text-decoration: underline; }
        #dev-site-bar table { width: 100%; border-collapse: collapse; }
        #dev-site-bar td { padding: 3px 6px 3px 0; vertical-align: top; }
        #dev-site-bar tr.dsb-here td { background: #292524; }
        #dev-site-bar .dsb-code {
            display: inline-block; min-width: 30px; text-align: center; border-radius: 3px;
            padding: 0 4px; font-size: 11px; font-weight: 700;
        }
        #dev-site-bar .dsb-200 { background: #14532d; color: #bbf7d0; }
        #dev-site-bar .dsb-404 { background: #450a0a; color: #fecaca; }
        #dev-site-bar .dsb-301 { background: #422006; color: #fde68a; }
        #dev-site-bar .dsb-none { background: #292524; color: #a8a29e; }
        #dev-site-bar ul { margin: 0; padding-left: 16px; }
        #dev-site-bar .dsb-foot { margin-top: 12px; padding-top: 8px; border-top: 1px solid #292524; display: flex; gap: 12px; }
    </style>

    <details>
        <summary>
            <span class="dsb-dot" style="background: hsl({{ $sites->firstWhere('is_current')['hue'] ?? 0 }} 70% 55%)"></span>
            <span class="dsb-slug">{{ $site->slug }}</span>
            <span class="dsb-dim">{{ $site->name }}</span>
            <span class="dsb-tag">theme: {{ $site->theme }}</span>
            @unless ($site->is_active)
                <span class="dsb-tag warn">in build</span>
            @endunless
            @if ($navBroken)
                <span class="dsb-tag bad">{{ $navBroken }} nav 404</span>
            @endif
            @if ($adminSite)
                <span class="dsb-tag">admin: {{ $adminSite }}</span>
            @endif
        </summary>

        <div class="dsb-panel">
            <h4>Resolved via</h4>
            <div class="dsb-dim">{{ $via }}</div>

            <h4>Switch tenant &mdash; same path, predicted result</h4>
            <table>
                @foreach ($sites as $row)
                    <tr @class(['dsb-here' => $row['is_current']])>
                        <td><span class="dsb-code dsb-{{ $row['status'] }}">{{ $row['status'] }}</span></td>
                        <td>
                            @if ($row['is_current'])
                                <strong>{{ $row['site']->slug }}</strong>
                            @else
                                <a href="{{ $row['url'] }}">{{ $row['site']->slug }}</a>
                            @endif
                        </td>
                        <td class="dsb-dim">{{ $row['note'] }}</td>
                        <td class="dsb-dim">{{ $row['site']->devHost() }}</td>
                    </tr>
                @endforeach
            </table>

            @php $nav = $sites->firstWhere('is_current')['nav'] ?? []; @endphp
            @if ($nav)
                <h4>This tenant&rsquo;s nav</h4>
                <table>
                    @foreach ($nav as $link)
                        <tr>
                            <td><span class="dsb-code dsb-{{ $link['status'] === '—' ? 'none' : $link['status'] }}">{{ $link['status'] }}</span></td>
                            <td><a href="{{ $link['href'] }}">{{ $link['label'] }}</a></td>
                            <td class="dsb-dim">{{ $link['href'] }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif

            @php $cur = $sites->firstWhere('is_current'); @endphp
            <h4>Config overlays</h4>
            <div class="dsb-dim">{{ $cur['overlays'] ? implode(', ', $cur['overlays']) : 'none — inherits every shared config file' }}</div>

            <h4>Theme views serving this page</h4>
            @if ($viewsUsed)
                <ul>@foreach ($viewsUsed as $v)<li class="dsb-dim">{{ $v }}</li>@endforeach</ul>
            @else
                <div class="dsb-dim">none &mdash; every view fell through to resources/views</div>
            @endif

            <div class="dsb-foot">
                <a href="/_sites?path={{ urlencode(request()->getPathInfo()) }}">All sites &rarr;</a>
                <a href="/_sites/bar?state=off&amp;back={{ urlencode($back) }}">Hide this bar</a>
                <span class="dsb-dim">or append ?_bar=0</span>
            </div>
        </div>
    </details>
</div>
