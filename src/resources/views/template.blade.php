<?php
if (!isset($skip_navbar)) {
    $skip_navbar = false;
}
$baseController = App::make(TmlpStats\Http\Controllers\Controller::class);
$center = $baseController->getCenter(Request::instance());
$region = $baseController->getRegion(Request::instance());
$reportingDate = $baseController->getReportingDate();
$needs_shim = Session::get('needs_shim', '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TMLP Stats</title>

    <script type="text/javascript" src="{{ asset('vendor/js/jquery.min.js') }}"></script>

    {{-- HTML5 shim and Respond.js for IE8 support of HTML5 elements and media queries --}}
    {{-- WARNING: Respond.js doesn't work if you view the page via file:// --}}
    <!--[if lt IE 9]>
    <script src="{{ asset('vendor/js/html5shiv.min.js') }}"></script>
    <script src="{{ asset('vendor/js/respond.min.js') }}"></script>
    <![endif]-->

    <link href="{{ mix('build/css/app.css') }}" rel="stylesheet">

    @yield('headers')
</head>
<body <?php if($skip_navbar) { ?>class="no-navbar"<?php } ?>>
    @if (!$skip_navbar)
        @include('partials.navbar')
    @endif
    <div id="main-container" class="container-fluid">
        @yield('content')
    </div>

    @if (Auth::check())
        @include('partials.feedback')
    @endif

    @include('partials.settings')

    {{-- The ES6 shim brings ES6 functions, but we're doing a detection, most browsers other than IE these days support this without a shim.--}}
    @if ($needs_shim == 'y')
        <script type="text/javascript" src="{{ asset('/vendor/js/shim.min.js') }}"></script>
    @elseif ($needs_shim != 'n')
        <script type="text/javascript" src="/js/detect-shim.js"></script>
    @endif

    <script src="{{ mix('build/js/classic-vendor.js') }}" type="text/javascript"></script>
    <script src="{{ mix('build/js/manifest.js') }}" type="text/javascript"></script>
    <script src="{{ mix('build/js/vendor.js') }}" type="text/javascript"></script>
    <script src="{{ mix('build/js/main.js') }}" type="text/javascript"></script>

    @if (Auth::check())
        <script type="text/javascript">
            $(document).ready(function() {
                Tmlp.enableFeedback(settings);
                @if (!Session::has('timezone') && !Session::has('locale'))
                    Tmlp.setTimezone({
                        isHome: @json(Request::is('/home'))
                    });
                @endif
            });
        </script>
    @endif

    @yield('scripts')

    @if (config('app.env') != 'local')
        <script>
          (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
          (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
          m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
          })(window,document,'script','https://www.google-analytics.com/analytics.js','ga');

          ga('create', 'UA-93716645-1', 'auto');
          ga('send', 'pageview');

        </script>
    @endif

    <div class="device-xs visible-xs"></div>
    <div class="device-sm visible-sm"></div>
    <div class="device-md visible-md"></div>
    <div class="device-lg visible-lg"></div>
</body>
</html>
