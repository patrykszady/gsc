{{--
    Decorative architectural remodel plan for <x-page-decor variant="floorplan" />.
    The group classes and pathLength="1" attributes are load-bearing: the
    component's CSS stages each group off scroll progress. Keep them intact.
      g-shell/g-int  existing walls        g-demo/g-demo-dash  wall being removed
      g-new          new construction      g-fills             pastel room washes
--}}
<svg viewBox="0 0 1000 780" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
  <!-- ============ ROOM FILLS (painted first, behind linework) ============ -->
  <g class="g-fills" stroke="none">
    <rect class="f-1" x="143" y="113" width="374" height="224" fill="#bae6fd"/>
    <rect class="f-2" x="523" y="113" width="234" height="224" fill="#cffafe"/>
    <rect class="f-3" x="143" y="343" width="417" height="264" fill="#e0f2fe"/>
    <rect class="f-4" x="623" y="343" width="114" height="114" fill="#fef3c7"/>
    <rect class="f-5" x="763" y="113" width="114" height="224" fill="#ffedd5"/>
  </g>

  <!-- ============ EXTERIOR SHELL ============ -->
  <g class="g-shell" stroke-width="2.75">
    <!-- outer wall face -->
    <path pathLength="1" d="M137 107 H300 M410 107 H570 M630 107 H660 M720 107 H790 M850 107 H883 M883 107 V160 M883 220 V613 M883 613 H685 M625 613 H430 M370 613 H270 M210 613 H137 M137 613 V550 M137 490 V460 M137 400 V230 M137 170 V107"/>
    <!-- inner wall face -->
    <path pathLength="1" d="M143 113 H300 M410 113 H570 M630 113 H660 M720 113 H790 M850 113 H877 M877 113 V160 M877 220 V607 M877 607 H685 M625 607 H430 M370 607 H270 M210 607 H143 M143 607 V550 M143 490 V460 M143 400 V230 M143 170 V113"/>
    <!-- jamb caps at exterior door openings -->
    <path pathLength="1" d="M625 607 V613 M685 607 V613 M877 160 H883 M877 220 H883"/>
    <!-- front porch edge -->
    <path pathLength="1" stroke-width="1.5" d="M565 613 V688 H745 V613"/>
    <rect pathLength="1" stroke-width="1.5" x="569" y="668" width="14" height="14"/>
    <rect pathLength="1" stroke-width="1.5" x="727" y="668" width="14" height="14"/>
    <path pathLength="1" stroke-width="1.5" d="M615 696 H695 M623 704 H687"/>
  </g>

  <!-- ============ INTERIOR WALLS (REMAIN) ============ -->
  <g class="g-int" stroke-width="2.75">
    <!-- kitchen / dining wall with cased opening -->
    <path pathLength="1" d="M517 113 V160 M523 113 V160 M517 160 H523 M517 300 V343 M523 300 V343 M517 300 H523 M517 343 H523"/>
    <!-- wall behind foyer / powder / stairs -->
    <path pathLength="1" d="M617 337 H877 M617 343 H877 M617 337 V343"/>
    <!-- powder room enclosure -->
    <path pathLength="1" d="M617 343 V463 M623 343 V457 M737 343 V457 M743 343 V463 M617 457 H668 M718 457 H743 M617 463 H668 M718 463 H743 M668 457 V463 M718 457 V463"/>
    <!-- dining / mudroom wall with opening -->
    <path pathLength="1" d="M757 113 V277 M763 113 V277 M757 277 H763"/>
  </g>

  <!-- ============ DEMO: WALL BETWEEN KITCHEN AND LIVING ============ -->
  <g class="g-demo" stroke-width="2.75">
    <line pathLength="1" x1="143" y1="337" x2="517" y2="337"/>
    <line pathLength="1" x1="143" y1="343" x2="517" y2="343"/>
  </g>

  <!-- ============ DEMO GHOST: dashed outline of what was removed ============ -->
  <g class="g-demo-dash" stroke-width="1.6">
    <line pathLength="1" x1="143" y1="337" x2="517" y2="337"/>
    <line pathLength="1" x1="143" y1="343" x2="517" y2="343"/>
    <text stroke="none" fill="currentColor" font-family="ui-monospace, monospace" font-size="9" letter-spacing="1.5" text-anchor="middle" x="330" y="330">REMOVE EXIST. BEARING WALL</text>
  </g>

  <!-- ============ NEW CONSTRUCTION ============ -->
  <g class="g-new" stroke-width="2.5">
    <!-- kitchen peninsula at removed wall -->
    <rect pathLength="1" x="300" y="352" width="217" height="50"/>
    <line pathLength="1" x1="300" y1="382" x2="517" y2="382"/>
    <path pathLength="1" d="M517 343 V352 M523 343 V352"/>
    <!-- mudroom bench + cubbies -->
    <rect pathLength="1" x="766" y="125" width="28" height="135"/>
    <path pathLength="1" d="M766 159 H794 M766 193 H794 M766 227 H794"/>
  </g>

  <!-- ============ DOOR SWINGS ============ -->
  <g class="g-doors" stroke-width="1.5">
    <!-- front door -->
    <line pathLength="1" x1="625" y1="607" x2="625" y2="547"/>
    <path pathLength="1" d="M685 607 A60 60 0 0 0 625 547"/>
    <!-- mudroom side entry -->
    <line pathLength="1" x1="877" y1="160" x2="817" y2="160"/>
    <path pathLength="1" d="M877 220 A60 60 0 0 1 817 160"/>
    <!-- powder door (out-swing) -->
    <line pathLength="1" x1="718" y1="463" x2="718" y2="513"/>
    <path pathLength="1" d="M668 463 A50 50 0 0 0 718 513"/>
  </g>

  <!-- ============ WINDOWS ============ -->
  <g class="g-windows" stroke-width="1.4">
    <path pathLength="1" d="M300 107 H410 M300 110 H410 M300 113 H410 M300 107 V113 M410 107 V113"/>
    <path pathLength="1" d="M570 107 H630 M570 110 H630 M570 113 H630 M570 107 V113 M630 107 V113"/>
    <path pathLength="1" d="M660 107 H720 M660 110 H720 M660 113 H720 M660 107 V113 M720 107 V113"/>
    <path pathLength="1" d="M790 107 H850 M790 110 H850 M790 113 H850 M790 107 V113 M850 107 V113"/>
    <path pathLength="1" d="M137 170 V230 M140 170 V230 M143 170 V230 M137 170 H143 M137 230 H143"/>
    <path pathLength="1" d="M137 400 V460 M140 400 V460 M143 400 V460 M137 400 H143 M137 460 H143"/>
    <path pathLength="1" d="M137 490 V550 M140 490 V550 M143 490 V550 M137 490 H143 M137 550 H143"/>
    <path pathLength="1" d="M210 607 H270 M210 610 H270 M210 613 H270 M210 607 V613 M270 607 V613"/>
    <path pathLength="1" d="M370 607 H430 M370 610 H430 M370 613 H430 M370 607 V613 M430 607 V613"/>
  </g>

  <!-- ============ FIXTURES + CASEWORK ============ -->
  <g class="g-fix" stroke-width="1.75">
    <!-- kitchen counters -->
    <path pathLength="1" d="M517 153 H183 V260 H143"/>
    <rect pathLength="1" x="318" y="120" width="74" height="24" rx="2"/>
    <line pathLength="1" x1="355" y1="122" x2="355" y2="142"/>
    <rect pathLength="1" x="432" y="114" width="60" height="38"/>
    <circle pathLength="1" cx="447" cy="126" r="5.5"/>
    <circle pathLength="1" cx="477" cy="126" r="5.5"/>
    <circle pathLength="1" cx="447" cy="141" r="5.5"/>
    <circle pathLength="1" cx="477" cy="141" r="5.5"/>
    <rect pathLength="1" x="146" y="266" width="58" height="60"/>
    <text stroke="none" fill="currentColor" font-family="ui-monospace, monospace" font-size="9" letter-spacing="1" text-anchor="middle" x="175" y="300">REF</text>
    <!-- stairs -->
    <path pathLength="1" d="M812 570 H877 M812 550 H877 M812 530 H877 M812 510 H877 M812 490 H877 M812 470 H877 M812 450 H877 M812 430 H877 M812 410 H877"/>
    <line pathLength="1" x1="812" y1="580" x2="812" y2="402"/>
    <path pathLength="1" d="M810 400 L838 391 L832 383 L877 369"/>
    <line pathLength="1" x1="845" y1="588" x2="845" y2="430"/>
    <path pathLength="1" d="M837 442 L845 428 L853 442"/>
    <circle pathLength="1" cx="845" cy="588" r="4"/>
    <!-- powder fixtures -->
    <rect pathLength="1" x="632" y="346" width="36" height="12"/>
    <ellipse pathLength="1" cx="650" cy="374" rx="13" ry="16"/>
    <rect pathLength="1" x="695" y="346" width="42" height="36"/>
    <ellipse pathLength="1" cx="716" cy="364" rx="12" ry="10"/>
  </g>

  <!-- ============ DIMENSIONS ============ -->
  <g class="g-dims" stroke-width="1">
    <line pathLength="1" x1="140" y1="98" x2="140" y2="62"/>
    <line pathLength="1" x1="520" y1="98" x2="520" y2="62"/>
    <line pathLength="1" x1="880" y1="98" x2="880" y2="62"/>
    <line pathLength="1" x1="140" y1="70" x2="880" y2="70"/>
    <path pathLength="1" d="M134 76 L146 64"/>
    <path pathLength="1" d="M514 76 L526 64"/>
    <path pathLength="1" d="M874 76 L886 64"/>
    <text stroke="none" fill="currentColor" font-family="ui-monospace, monospace" font-size="12" letter-spacing="2.5" text-anchor="middle" x="330" y="58">19&#39;-0&quot;</text>
    <text stroke="none" fill="currentColor" font-family="ui-monospace, monospace" font-size="12" letter-spacing="2.5" text-anchor="middle" x="700" y="58">18&#39;-0&quot;</text>
    <line pathLength="1" x1="128" y1="110" x2="78" y2="110"/>
    <line pathLength="1" x1="128" y1="610" x2="78" y2="610"/>
    <line pathLength="1" x1="84" y1="110" x2="84" y2="610"/>
    <path pathLength="1" d="M78 116 L90 104"/>
    <path pathLength="1" d="M78 616 L90 604"/>
    <text stroke="none" fill="currentColor" font-family="ui-monospace, monospace" font-size="12" letter-spacing="2.5" text-anchor="middle" transform="rotate(-90 66 360)" x="66" y="360">25&#39;-0&quot;</text>
    <!-- north arrow -->
    <circle pathLength="1" cx="940" cy="86" r="17"/>
    <line pathLength="1" x1="940" y1="100" x2="940" y2="72"/>
    <path pathLength="1" d="M934 81 L940 70 L946 81"/>
    <text stroke="none" fill="currentColor" font-family="ui-monospace, monospace" font-size="12" letter-spacing="2" text-anchor="middle" x="940" y="123">N</text>
    <!-- sheet frame + title block -->
    <rect pathLength="1" stroke-width="1.2" x="18" y="14" width="964" height="752"/>
    <rect pathLength="1" stroke-width="1.1" x="620" y="714" width="340" height="44"/>
    <line pathLength="1" stroke-width="1.1" x1="872" y1="714" x2="872" y2="758"/>
    <text stroke="none" fill="currentColor" font-family="ui-monospace, monospace" font-size="9" letter-spacing="1" x="632" y="732">GS CONSTRUCTION &amp; REMODELING</text>
    <text stroke="none" fill="currentColor" font-family="ui-monospace, monospace" font-size="8.5" letter-spacing="1" x="632" y="748">GREG &amp; SON CONSTRUCTION COMPANY</text>
    <text stroke="none" fill="currentColor" font-family="ui-monospace, monospace" font-size="13" letter-spacing="1.5" text-anchor="middle" x="916" y="742">A-2.1</text>
  </g>

  <!-- ============ ROOM LABELS ============ -->
  <g class="g-labels">
    <text stroke="none" fill="currentColor" font-family="ui-monospace, monospace" font-size="16" letter-spacing="2.5" text-anchor="middle" x="330" y="210">KITCHEN</text>
    <text stroke="none" fill="currentColor" font-family="ui-monospace, monospace" font-size="11" letter-spacing="2" text-anchor="middle" x="330" y="232">18&#39;-8&quot; X 11&#39;-2&quot;</text>
    <text stroke="none" fill="currentColor" font-family="ui-monospace, monospace" font-size="16" letter-spacing="2.5" text-anchor="middle" x="640" y="205">DINING</text>
    <text stroke="none" fill="currentColor" font-family="ui-monospace, monospace" font-size="11" letter-spacing="2" text-anchor="middle" x="640" y="227">11&#39;-8&quot; X 11&#39;-2&quot;</text>
    <text stroke="none" fill="currentColor" font-family="ui-monospace, monospace" font-size="16" letter-spacing="2.5" text-anchor="middle" x="350" y="468">LIVING</text>
    <text stroke="none" fill="currentColor" font-family="ui-monospace, monospace" font-size="11" letter-spacing="2" text-anchor="middle" x="350" y="490">20&#39;-10&quot; X 13&#39;-2&quot;</text>
    <text stroke="none" fill="currentColor" font-family="ui-monospace, monospace" font-size="15" letter-spacing="2" text-anchor="middle" x="680" y="418">POWDER</text>
    <text stroke="none" fill="currentColor" font-family="ui-monospace, monospace" font-size="11" letter-spacing="1" text-anchor="middle" x="680" y="436">5&#39;-8&quot; X 5&#39;-8&quot;</text>
    <text stroke="none" fill="currentColor" font-family="ui-monospace, monospace" font-size="16" letter-spacing="2.5" text-anchor="middle" transform="rotate(-90 816 268)" x="816" y="268">MUDROOM</text>
    <text stroke="none" fill="currentColor" font-family="ui-monospace, monospace" font-size="11" letter-spacing="1.5" text-anchor="middle" transform="rotate(-90 838 268)" x="838" y="268">5&#39;-8&quot; X 11&#39;-2&quot;</text>
    <text stroke="none" fill="currentColor" font-family="ui-monospace, monospace" font-size="14" letter-spacing="2.5" text-anchor="middle" x="735" y="558">FOYER</text>
    <text stroke="none" fill="currentColor" font-family="ui-monospace, monospace" font-size="11" letter-spacing="2" text-anchor="middle" x="845" y="604">UP</text>
    <text stroke="none" fill="currentColor" font-family="ui-monospace, monospace" font-size="16" letter-spacing="3" x="140" y="726">PROPOSED FIRST FLOOR</text>
    <line pathLength="1" stroke-width="1.5" x1="140" y1="736" x2="336" y2="736"/>
    <text stroke="none" fill="currentColor" font-family="ui-monospace, monospace" font-size="11" letter-spacing="2" x="140" y="756">SCALE: 1/4&quot; = 1&#39;-0&quot;</text>
  </g>
</svg>
